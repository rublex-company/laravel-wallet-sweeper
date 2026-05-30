<?php

namespace Rublex\Wallet\Aggregator\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Rublex\Wallet\Aggregator\Transfer;
use Rublex\Wallet\Aggregator\Wallet;

class TransactionService extends PendingRequest
{

    private const TX_URL = 'https://tx.rublex.io';

    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        $this->baseUrl(self::TX_URL);

        parent::__construct();
    }

    /**
     * @throws ConnectionException
     */
    public function balance(Wallet $wallet, $contract = null)
    {
        $balance = $this->post('/balance', [
            'chain' => $wallet->getNetwork(),
            'address' => $wallet->getAddress(),
            'token_contract' => $contract
        ])->json('balance');

        return $this->convertScientificToDecimal($balance);
    }

    /**
     * @throws ConnectionException
     */
    public function transfer(Transfer $transfer, $preview = false)
    {
        $response = $this->post('/transfer', [
            'chain' => $transfer->getWallet()->getNetwork(),
            'from_address' => $transfer->getWallet()->getAddress(),
            'to_address' => $transfer->getAddress(),
            'private_key' => $transfer->getWallet()->getPrivateKey(),
            'amount' => $transfer->getAmount(),
            'token_contract' => $transfer->getContract(),
            'preview' => $preview
        ])->json();

        return $preview ? data_get($response, 'gas') : $response;
    }

    /**
     * Ask the chain service whether a tx has at least $threshold confirmed
     * blocks behind it. Returns the parsed response — typically shaped
     * `{confirmed: bool, threshold: int, passed_blocks: int}`.
     *
     * @throws ConnectionException
     */
    public function confirmation(string $network, string $hash, int $threshold = 1): array
    {
        return $this->post('/check-tx-confirmation', [
            'chain'     => $network,
            'hash'      => $hash,
            'threshold' => $threshold,
        ])->json();
    }

    /**
     * Atomic sweep: hand the whole flow (top-up, then send) to the chain
     * service. For tokens the service tops up the pay-in wallet from the
     * fee wallet by the exact gap, then sends the token to `output`; the
     * top-up tx is awaited before the send so there's no confirmation
     * race in client code. For native sweeps the pay-in wallet pays its
     * own gas — no fee wallet leg.
     *
     * `$amount` is the explicit withdrawal amount; when null the service
     * sweeps the maximum possible (full token balance for tokens, balance
     * minus gas for native). When provided but larger than the max the
     * service clamps it down so this call never errors for "too high".
     *
     * Returns the raw response, typically shaped:
     *   {hash, amount, fee, fee_hash}     // success
     *   {message, required_fee?}          // failure
     *
     * @throws ConnectionException
     */
    public function settle(
        string $network,
        string $payInPrivateKey,
        string $output,
        ?string $tokenContract = null,
        ?string $feePrivateKey = null,
        ?string $amount = null,
    ): array {
        return $this->post('/settle', [
            'chain'           => $network,
            'private_key'     => $payInPrivateKey,
            'fee_private_key' => $feePrivateKey,
            'output'          => $output,
            'token_contract'  => $tokenContract,
            'amount'          => $amount,
        ])->json();
    }

    function convertScientificToDecimal(string $sci, int $precision = 20): string
    {
        // Check if the number is in scientific notation
        if (stripos($sci, 'e') === false) return $sci;

        // Split the base and the exponent
        [$base, $exp] = explode('e', strtolower($sci));

        // Use BCMath to calculate: base * (10 ^ exponent)
        $converted = bcmul($base, bcpow('10', $exp, $precision), $precision);

        return rtrim(rtrim($converted, '0'), '.');
    }

}
