<?php

namespace Rublex\Wallet\Aggregator;

class Wallet
{
    private string $privateKey;
    private string $address;
    private string $network;

    /**
     * Create a new class instance.
     */
    public function __construct(string $privateKey, string $address, string $network)
    {
        $this->privateKey = $privateKey;
        $this->address = $address;
        $this->network = $network;
    }

    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getNetwork(): string
    {
        return $this->network;
    }


}
