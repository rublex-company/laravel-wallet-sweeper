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

    /**
     * Headroom applied on top of the reported gas figure (the chain's
     * `required_fee` on token sweeps, the preview's gas on native sweeps).
     * Absorbs the small gas-price drift between the figure being reported
     * and the broadcast that consumes it, so a single top-up + single retry
     * is always enough — we never need a second top-up trip that would burn
     * extra native fees out of the customer's pocket.
     */
    private const GAS_BUFFER = '1.2';

    private const DECIMALS = 18;

    /**
     * Confirmations to wait for after a fee-wallet top-up before retrying
     * the main transfer. One block is enough for the chain service to see
     * the new pay-in balance — without it, the retry races the top-up's
     * mining and the chain still reports `Insufficient fee (gas)!` so a
     * downstream cron retry burns a second top-up fee unnecessarily.
     */
    private const TOPUP_CONFIRMATIONS = 1;

    /**
     * How long to wait for the top-up to confirm before giving up and
     * letting the retry race. Generous enough for the slowest EVM-class
     * chain we run on (~3s per block × ~10 blocks of headroom). On
     * timeout we proceed anyway; the job's own retry chain absorbs any
     * remaining race.
     */
    private const TOPUP_CONFIRMATION_TIMEOUT_SECONDS = 30;

    /**
     * Seconds between confirmation polls. Short enough to retry quickly
     * once the block lands, long enough to avoid hammering the RPC node.
     */
    private const TOPUP_CONFIRMATION_POLL_SECONDS = 2;

    public function __construct(protected TransactionService $service)
    {
        //
    }

    /**
     * Sweep funds from a pay-in wallet to the aggregator address.
     *
     * Required params:
     *  - aggregator_address: destination
     *  - network: chain slug (bsc, eth, trx, ...)
     *  - pay_in_wallet: ['address' => ..., 'private_key' => ...]
     *
     * Token sweeps (token_contract set) additionally require:
     *  - fee_wallet: ['address' => ..., 'private_key' => ...] — funds the
     *    pay-in wallet's gas top-up just before the transfer.
     *
     * Optional params:
     *  - amount: explicit withdrawal amount (decimal string). When omitted,
     *    sweeps the maximum possible (token: full token balance; native:
     *    balance minus gas). When provided but greater than the maximum
     *    possible, it's clamped down so the call never fails for "too high"
     *    on the caller's side.
     *  - token_contract: null means a native-coin sweep (BNB/ETH/TRX/...).
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

        $payInWalletPrivateKey = $params['pay_in_wallet']['private_key'] ?? throw new IsNullException('Payment wallet private key (["pay_in_wallet"]["private_key"]) is null');
        $payInWalletAddress = $params['pay_in_wallet']['address'] ?? throw new IsNullException('Payment wallet address (["pay_in_wallet"]["address"]) is null');
        $payInWallet = new Wallet($payInWalletPrivateKey, $payInWalletAddress, $network);

        $requestedAmount = $this->normalizeRequestedAmount($params['amount'] ?? null);

        if ($tokenContract === null) {
            return $this->sweepNative($payInWallet, $aggregatorAddress, $network, $requestedAmount, $params);
        }

        $feeWalletPrivateKey = $params['fee_wallet']['private_key'] ?? throw new IsNullException('Fee wallet private key (["fee_wallet"]["private_key"]) is null');
        $feeWalletAddress = $params['fee_wallet']['address'] ?? throw new IsNullException('Fee wallet address (["fee_wallet"]["address"]) is null');
        $feeWallet = new Wallet($feeWalletPrivateKey, $feeWalletAddress, $network);

        return $this->sweepToken($payInWallet, $feeWallet, $tokenContract, $aggregatorAddress, $network, $requestedAmount, $params);
    }

    /**
     * Token sweep: pay-in wallet sends ERC20-style tokens to the aggregator,
     * and the fee wallet covers the native-coin gas the pay-in wallet needs.
     *
     * Fee discovery is driven by the network response, not by a separate
     * preview round-trip. We submit the real transfer first; when the
     * pay-in wallet already holds enough gas (leftover from a previous
     * sweep, manual top-up, etc.) the call succeeds in one shot. Otherwise
     * the chain returns `Insufficient fee (gas)!` with the exact
     * `required_fee` figure, which we use to top up the precise gap from
     * the fee wallet (plus a small buffer) before retrying once. Sizing
     * the top-up off the chain's own number guarantees no second top-up
     * trip is ever needed and no customer tokens are wasted.
     *
     * @throws IsNullException
     * @throws Exception
     */
    private function sweepToken(
        Wallet $payInWallet,
        Wallet $feeWallet,
        string $tokenContract,
        string $aggregatorAddress,
        string $network,
        ?string $requestedAmount,
        array $params
    ): array {
        $tokenBalance = $this->service->balance($payInWallet, $tokenContract);
        if (bccomp((string) $tokenBalance, '0', self::DECIMALS) <= 0) {
            throw new IsNullException('No balance in payment wallet');
        }

        $amount = $this->clampToBalance($requestedAmount, (string) $tokenBalance);
        $transfer = new Transfer($payInWallet, $amount, $aggregatorAddress, $tokenContract);

        // Step 1: send the real transfer. When gas is already sufficient
        // this single call is the whole flow.
        $tx = $this->service->transfer($transfer);
        $feeTx = null;

        if (!$this->isSuccessful($tx)) {
            // Step 2: read the exact gas figure the chain reported in its
            // "Insufficient fee (gas)!" response. This is the authoritative
            // number — the same one the chain's own preview would return.
            $requiredFee = $this->extractRequiredFee($tx);

            if ($requiredFee === null) {
                // Not a gas issue, or the response didn't carry a
                // parseable fee. Let the caller see the raw response.
                return $this->finalize($tx, $feeTx, $payInWallet, $aggregatorAddress, $network, $tokenContract, $amount, $params);
            }

            $nativeBalance = (string) $this->service->balance($payInWallet);
            $missingGas = bcsub($requiredFee, $nativeBalance, self::DECIMALS);

            // Defensive: if the wallet now reports enough native (cached
            // balance from another in-flight sweep, etc.), skip top-up and
            // go straight to the retry.
            if (bccomp($missingGas, '0', self::DECIMALS) > 0) {
                $topupAmount = bcmul($missingGas, self::GAS_BUFFER, self::DECIMALS);
                $topup = new Transfer($feeWallet, $topupAmount, $payInWallet->getAddress());
                $feeTx = $this->service->transfer($topup);

                // If the fee wallet itself couldn't fund the gas, retrying
                // the main transfer would just produce the same error —
                // surface it so the caller can flag fee wallet insufficient.
                if (!$this->isSuccessful($feeTx)) {
                    throw new Exception('Fee wallet gas top-up failed: ' . json_encode($feeTx));
                }

                // The top-up has a hash but the chain service queries node
                // balances directly — until the top-up tx is mined, the
                // chain still reports the pre-top-up balance and the retry
                // would race the confirmation and fail with another
                // `Insufficient fee (gas)!`. Wait for the top-up to land so
                // a single retry is enough and no second top-up burns gas.
                $this->waitForConfirmation($network, (string) $feeTx['hash']);
            }

            // Step 3: retry once with the now-funded gas. The top-up
            // covered the chain's exact requirement, so one retry is enough.
            $tx = $this->service->transfer($transfer);
        }

        return $this->finalize($tx, $feeTx, $payInWallet, $aggregatorAddress, $network, $tokenContract, $amount, $params);
    }

    /**
     * Whether the chain service's transfer response represents a confirmed
     * broadcast (has a tx hash).
     */
    private function isSuccessful(mixed $tx): bool
    {
        return is_array($tx) && array_key_exists('hash', $tx);
    }

    /**
     * Poll the chain service's confirmation endpoint until the given tx
     * has at least TOPUP_CONFIRMATIONS blocks behind it, or the timeout
     * elapses. Returns once the chain reports confirmed (or on timeout —
     * the caller's retry chain absorbs any remaining race).
     */
    private function waitForConfirmation(string $network, string $hash): void
    {
        $deadline = time() + self::TOPUP_CONFIRMATION_TIMEOUT_SECONDS;

        while (time() < $deadline) {
            try {
                $response = $this->service->confirmation($network, $hash, self::TOPUP_CONFIRMATIONS);
                if (($response['confirmed'] ?? false) === true) {
                    return;
                }
            } catch (Exception) {
                // Transient lookup failure (RPC blip, network blink) —
                // fall through to the sleep + next iteration. Don't bail
                // out, the deadline already caps the total wait.
            }

            sleep(self::TOPUP_CONFIRMATION_POLL_SECONDS);
        }
    }

    /**
     * Read the total native-coin gas required for the transfer out of a
     * failed response. The chain service surfaces this as `required_fee`
     * alongside the "Insufficient fee (gas)!" message; standard
     * go-ethereum revert text (`have X want Y`, wei) is accepted as a
     * fallback. Returns the total required figure as a decimal string in
     * native units, or null when nothing parseable is present.
     */
    private function extractRequiredFee(mixed $tx): ?string
    {
        if (!is_array($tx)) {
            return null;
        }

        if (isset($tx['required_fee']) && is_numeric($tx['required_fee'])) {
            $val = (string) $tx['required_fee'];
            if (bccomp($val, '0', self::DECIMALS) > 0) {
                return $val;
            }
        }

        $reason = $tx['reason'] ?? $tx['message'] ?? null;
        if (is_string($reason) && preg_match('/have (\d+) want (\d+)/', $reason, $matches)) {
            $weiPerNative = bcpow('10', '18');
            return bcdiv($matches[2], $weiPerNative, self::DECIMALS);
        }

        return null;
    }

    /**
     * Native sweep: the pay-in wallet pays its own gas in the same currency
     * it's sending, so there is no separate fee-wallet top-up. The effective
     * amount is whichever is smaller of the requested amount and
     * `balance - gas` so the call never tries to overspend the wallet.
     *
     * @throws IsNullException
     * @throws Exception
     */
    private function sweepNative(
        Wallet $payInWallet,
        string $aggregatorAddress,
        string $network,
        ?string $requestedAmount,
        array $params
    ): array {
        $nativeBalance = (string) $this->service->balance($payInWallet);
        if (bccomp($nativeBalance, '0', self::DECIMALS) <= 0) {
            throw new IsNullException('No balance in payment wallet');
        }

        // Gas for a native send is essentially independent of amount on EVM
        // and TRX, so we preview against the full balance to get a stable
        // figure and then derive the effective amount from it.
        $previewTransfer = new Transfer($payInWallet, $nativeBalance, $aggregatorAddress);
        $gas = (string) $this->service->transfer($previewTransfer, true);
        $gasWithBuffer = bcmul($gas, self::GAS_BUFFER, self::DECIMALS);

        $maxSendable = bcsub($nativeBalance, $gasWithBuffer, self::DECIMALS);
        if (bccomp($maxSendable, '0', self::DECIMALS) <= 0) {
            throw new Exception('Insufficient native balance to cover gas');
        }

        $amount = $this->clampToBalance($requestedAmount, $maxSendable);

        $transfer = new Transfer($payInWallet, $amount, $aggregatorAddress);
        $tx = $this->service->transfer($transfer);

        if ($tx['reason'] ?? false) {
            throw new Exception("Transaction reverted: " . json_encode($tx));
        }

        return $this->finalize($tx, null, $payInWallet, $aggregatorAddress, $network, null, $amount, $params);
    }

    /**
     * Normalize the caller-supplied amount to a decimal string, or null when
     * the caller didn't request a specific amount (= sweep the maximum
     * sendable).
     */
    private function normalizeRequestedAmount(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        if (!is_numeric($amount)) {
            return null;
        }
        $amount = (string) $amount;
        if (bccomp($amount, '0', self::DECIMALS) <= 0) {
            return null;
        }
        return $amount;
    }

    /**
     * Clamp the requested amount to whatever the wallet can actually send.
     * Returns the requested amount when it fits, the max sendable when it
     * doesn't (or when the caller didn't request a specific amount).
     */
    private function clampToBalance(?string $requested, string $max): string
    {
        if ($requested === null) {
            return $max;
        }
        return bccomp($requested, $max, self::DECIMALS) > 0 ? $max : $requested;
    }

    /**
     * Build the final response payload (and post the callback when set).
     *
     * @throws Exception when the callback HTTP call fails.
     */
    private function finalize(
        mixed $tx,
        ?array $feeTx,
        Wallet $payInWallet,
        string $aggregatorAddress,
        string $network,
        ?string $tokenContract,
        string $amount,
        array $params
    ): array {
        $transactionStatus = $this->isSuccessful($tx);

        if (array_key_exists('callback', $params)) {
            $callbackResponse = Http::post($params['callback'], [
                'result' => $transactionStatus,
                'network' => $network,
                'contract' => $tokenContract,
                'input' => $payInWallet->getAddress(),
                'output' => $aggregatorAddress,
                'amount' => $amount,
                'message' => $transactionStatus ? 'Transaction completed' : 'Transaction reverted',
            ]);

            if ($callbackResponse->failed()) {
                throw new Exception("Callback Failed: " . json_encode($callbackResponse->body()));
            }
        }

        return [
            'status' => $transactionStatus,
            'message' => $transactionStatus ? 'Transaction completed' : 'Transaction reverted',
            'transaction' => $tx ?? null,
            'fee_transaction' => $feeTx,
        ];
    }
}
