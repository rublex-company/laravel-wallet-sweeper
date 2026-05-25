<?php

namespace Rublex\Wallet\Aggregator;

class Transfer
{
    private Wallet $wallet;
    private ?string $contract;
    private string $amount;
    private string $address;

    /**
     * Create a new class instance.
     */
    public function __construct(Wallet $wallet, string $amount, string $address, ?string $contract = null)
    {
        $this->wallet = $wallet;
        $this->contract = $contract;
        $this->amount = $amount;
        $this->address = $address;
    }

    public function getWallet(): Wallet
    {
        return $this->wallet;
    }

    public function getContract(): ?string
    {
        return $this->contract;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

}
