<?php

namespace Rublex\Wallet\Aggregator\Facades;

use Illuminate\Support\Facades\Facade;

/*
 * This file is part of the Laravel Rublex Wallet Aggregator package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class WalletSweeper extends Facade
{
    /**
     * Get the registered name of the component
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-wallet-sweeper';
    }
    
    final public const VERSION = '1.0.0';

}