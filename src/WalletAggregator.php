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

    final public const VERSION = '1.0.0';

    public function __construct(protected TransactionService $service)
    {
        //
    }

    /**
     * @throws IsNullException
     * @throws Exception
     */
    public function run(array $params): array
    {
        // null token_contract means a native-coin sweep (BNB/ETH/TRX/...).
        $tokenContract = $params['token_contract'] ?? null;

        $aggregatorAddress = $params['aggregator_address'] ?? throw new IsNullException('Aggregator ("aggregator_address") address is null');

        $network = $params['network'] ?? throw new IsNullException('Network ("network") is null');

        $pay_in_wallet_private_key = $params['pay_in_wallet']['private_key'] ?? throw new IsNullException('Payment wallet private key (["pay_in_wallet"]["private_key"]) is null');
        $pay_in_wallet_address = $params['pay_in_wallet']['address'] ?? throw new IsNullException('Payment wallet address (["pay_in_wallet"]["address"]) is null');
        $payInWallet = new Wallet($pay_in_wallet_private_key, $pay_in_wallet_address, $network);

        $buffering = '1.2';

        if ($tokenContract === null) {
            return $this->sweepNative($payInWallet, $aggregatorAddress, $network, $buffering, $params);
        }

        $fee_wallet_wallet_private_key = $params['fee_wallet']['private_key'] ?? throw new IsNullException('Fee wallet private key (["fee_wallet"]["private_key"]) is null');
        $fee_wallet_wallet_address = $params['fee_wallet']['address'] ?? throw new IsNullException('Fee wallet address (["fee_wallet"]["address"]) is null');
        $feeWallet = new Wallet($fee_wallet_wallet_private_key, $fee_wallet_wallet_address, $network);

        $tokenBalance = $this->service->balance($payInWallet, $tokenContract);

        if ($tokenBalance <= 0) throw new IsNullException('No balance in payment wallet');

        $bnbBalance = $this->service->balance($payInWallet);

        $transfer = new Transfer($payInWallet, $tokenBalance, $aggregatorAddress, $tokenContract);
        $gas = $this->service->transfer($transfer, true);

        $gasWithBuffer = bcmul($gas, $buffering, 18);

        if (bccomp($bnbBalance, $gasWithBuffer, 18) < 0) {

            $missingGas = bcsub($gasWithBuffer, $bnbBalance, 18);

            $transferGas = new Transfer($feeWallet, $missingGas, $payInWallet->getAddress());
            $feeTx = $this->service->transfer($transferGas);
        }

        $tx = $this->service->transfer($transfer);

        $reason = $tx['reason'] ?? null;

        if ($reason && str_contains($reason, 'insufficient funds for gas')) {
            preg_match('/have (\d+) want (\d+)/', $reason, $matches);

            if (count($matches) === 3) {

                $have = bcdiv($matches[1], bcpow('10', '18'), 18);
                $want = bcdiv($matches[2], bcpow('10', '18'), 18);

                $needed = bcmul(bcsub($want, $have, 18), $buffering, 18);

                // Re-send gas fee
                $gasTopUp = new Transfer($feeWallet, $needed, $payInWallet->getAddress());
                $this->service->transfer($gasTopUp);

                // Wait for confirmation (you can implement a poll/sleep here if necessary)
                sleep(120); // optionally wait before retrying

                // Retry transfer gas
                $tx = $this->service->transfer($transfer);

                if ($tx['reason'] ?? false)
                    throw new Exception("Transaction reverted: " . json_encode($tx));
            }
        }

        $transactionStatus = array_key_exists('hash', $tx);

        if (array_key_exists('callback', $params)) {

            $callbackResponse = Http::post($params['callback'], [
                'result' => $transactionStatus,
                'network' => $network,
                'contract' => $tokenContract,
                'input' => $payInWallet->getAddress(),
                'output' => $aggregatorAddress,
                'amount' => $tokenBalance,
                'message' => $transactionStatus ? 'Transaction completed' : 'Transaction reverted',
            ]);

            if ($callbackResponse->failed())
                throw new Exception("Callback Failed: " . json_encode($callbackResponse->body()));
        }

        return [
            'status' => $transactionStatus,
            'message' => $transactionStatus ? 'Transaction completed' : 'Transaction reverted',
            'transaction' => $tx ?? null,
            'fee_transaction' => $feeTx ?? null
        ];
    }

    /**
     * Sweep a native coin (no ERC20-style contract). The pay-in wallet pays its
     * own gas, so a fee_wallet top-up isn't part of the flow — we just transfer
     * `balance - gasWithBuffer` to the aggregator.
     *
     * @throws IsNullException
     * @throws Exception
     */
    private function sweepNative(Wallet $payInWallet, string $aggregatorAddress, string $network, string $buffering, array $params): array
    {
        $nativeBalance = $this->service->balance($payInWallet);

        if (bccomp((string) $nativeBalance, '0', 18) <= 0) {
            throw new IsNullException('No balance in payment wallet');
        }

        // Estimate gas using a preview of "transfer the full balance". On EVM
        // and TRX the gas for a native send doesn't change with the amount, so
        // sizing the preview at full balance is safe.
        $previewTransfer = new Transfer($payInWallet, (string) $nativeBalance, $aggregatorAddress);
        $gas = $this->service->transfer($previewTransfer, true);

        $gasWithBuffer = bcmul((string) $gas, $buffering, 18);

        $amount = bcsub((string) $nativeBalance, $gasWithBuffer, 18);
        if (bccomp($amount, '0', 18) <= 0) {
            throw new Exception('Insufficient native balance to cover gas');
        }

        $transfer = new Transfer($payInWallet, $amount, $aggregatorAddress);
        $tx = $this->service->transfer($transfer);

        if (($tx['reason'] ?? false)) {
            throw new Exception("Transaction reverted: " . json_encode($tx));
        }

        $transactionStatus = array_key_exists('hash', $tx);

        if (array_key_exists('callback', $params)) {
            $callbackResponse = Http::post($params['callback'], [
                'result'  => $transactionStatus,
                'network' => $network,
                'contract' => null,
                'input'   => $payInWallet->getAddress(),
                'output'  => $aggregatorAddress,
                'amount'  => $amount,
                'message' => $transactionStatus ? 'Transaction completed' : 'Transaction reverted',
            ]);

            if ($callbackResponse->failed()) {
                throw new Exception("Callback Failed: " . json_encode($callbackResponse->body()));
            }
        }

        return [
            'status'          => $transactionStatus,
            'message'         => $transactionStatus ? 'Transaction completed' : 'Transaction reverted',
            'transaction'     => $tx ?? null,
            'fee_transaction' => null,
        ];
    }
}
