<?php

namespace Rublex\Wallet\Aggregator;

use Exception;
use Illuminate\Support\Facades\Http;
use Rublex\Wallet\Aggregator\Services\TransactionService;

/*
 * This file is part of the Laravel Rublex Wallet Aggregator package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class WalletAggregator
{
    final public const VERSION = '2.0.0';

    public function __construct(protected TransactionService $service)
    {
        //
    }

    /**
     * Sweep funds from a pay-in wallet to the aggregator address.
     *
     * Delegates the whole flow — top-up gas from the fee wallet (token
     * sweeps only) and broadcast the main transfer — to the chain
     * service's /settle endpoint. The chain awaits the top-up's receipt
     * before broadcasting the main transfer, so there is no client-side
     * confirmation race, no buffered gas margin left behind as dust, and
     * a single HTTP round-trip per sweep.
     *
     * Required params:
     *  - aggregator_address: destination
     *  - network: chain slug (bsc, eth, trx, ...)
     *  - pay_in_wallet: ['address' => ..., 'private_key' => ...]
     *
     * Token sweeps (token_contract set) additionally require:
     *  - fee_wallet: ['address' => ..., 'private_key' => ...]
     *
     * Optional params:
     *  - amount: explicit withdrawal amount as a decimal string. When
     *    omitted the chain sweeps the maximum possible (token: full token
     *    balance; native: balance minus gas). When provided but larger
     *    than the max, the chain clamps it down.
     *  - token_contract: null for a native-coin sweep.
     *  - callback: URL the result is POSTed to.
     *
     * @throws IsNullException
     * @throws Exception
     */
    public function run(array $params): array
    {
        $tokenContract = $params['token_contract'] ?? null;

        $aggregatorAddress = $params['aggregator_address'] ?? throw new IsNullException('Aggregator ("aggregator_address") address is null');
        $network = $params['network'] ?? throw new IsNullException('Network ("network") is null');

        $payInPrivateKey = $params['pay_in_wallet']['private_key'] ?? throw new IsNullException('Payment wallet private key (["pay_in_wallet"]["private_key"]) is null');
        $payInAddress = $params['pay_in_wallet']['address'] ?? throw new IsNullException('Payment wallet address (["pay_in_wallet"]["address"]) is null');

        // fee_wallet is required for token sweeps (the chain tops up gas
        // from it) and unused for native sweeps (the pay-in wallet pays
        // its own gas in the same currency).
        $feePrivateKey = null;
        if ($tokenContract !== null) {
            $feePrivateKey = $params['fee_wallet']['private_key'] ?? throw new IsNullException('Fee wallet private key (["fee_wallet"]["private_key"]) is null');
        }

        $amount = $this->normalizeAmount($params['amount'] ?? null);

        $response = $this->service->settle(
            network: $network,
            payInPrivateKey: $payInPrivateKey,
            output: $aggregatorAddress,
            tokenContract: $tokenContract,
            feePrivateKey: $feePrivateKey,
            amount: $amount,
        );

        $hash = $response['hash'] ?? null;
        $success = is_string($hash) && $hash !== '';

        if (array_key_exists('callback', $params)) {
            $callbackResponse = Http::post($params['callback'], [
                'result'   => $success,
                'network'  => $network,
                'contract' => $tokenContract,
                'input'    => $payInAddress,
                'output'   => $aggregatorAddress,
                'amount'   => $response['amount'] ?? $amount,
                'message'  => $success ? 'Transaction completed' : ($response['message'] ?? 'Transaction reverted'),
            ]);

            if ($callbackResponse->failed()) {
                throw new Exception("Callback Failed: " . json_encode($callbackResponse->body()));
            }
        }

        // Preserve the response shape callers have been integrated against:
        // `transaction` carries the main settle, `fee_transaction` carries
        // the (optional) gas top-up. Map the flat /settle response into
        // that shape.
        $transaction = $success
            ? [
                'hash'   => $hash,
                'amount' => $response['amount'] ?? $amount,
                'fee'    => $response['fee'] ?? null,
            ]
            : [
                'message'      => $response['message'] ?? 'Transaction reverted',
                'required_fee' => $response['required_fee'] ?? null,
            ];

        $feeTransaction = null;
        if (!empty($response['fee_hash'])) {
            $feeTransaction = [
                'hash' => $response['fee_hash'],
            ];
        }

        return [
            'status'          => $success,
            'message'         => $success ? 'Transaction completed' : ($response['message'] ?? 'Transaction reverted'),
            'transaction'     => $transaction,
            'fee_transaction' => $feeTransaction,
        ];
    }

    /**
     * Normalize the caller-supplied amount to a decimal string, or null
     * when no specific amount was requested (= sweep the maximum). Returns
     * null for missing/empty/non-numeric/zero values so the chain falls
     * back to its own max-sendable calculation instead of trying to send
     * a meaningless figure.
     */
    private function normalizeAmount(mixed $amount): ?string
    {
        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            return null;
        }
        $amount = (string) $amount;
        return bccomp($amount, '0', 18) > 0 ? $amount : null;
    }
}
