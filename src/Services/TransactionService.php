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
