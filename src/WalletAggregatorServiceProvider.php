<?php

namespace Rublex\Wallet\Aggregator;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Rublex\Wallet\Aggregator\Services\TransactionService;

/*
 * This file is part of the Laravel Rublex Wallet Aggregator package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class WalletAggregatorServiceProvider extends ServiceProvider
{

    /*
    * Indicates if loading of the provider is deferred.
    *
    * @var bool
    */
    protected bool $defer = false;

    /**
     * Publishes all the config file this package needs to function
     */
    public function boot(): void
    {
        $this->registerDashboard();
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->app->bind('laravel-wallet-sweeper', function () {
            return new WalletAggregator(new TransactionService());
        });
    }


    /**
     * Register the dashboard components.
     *
     * @return void
     */
    protected function registerDashboard(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/routes.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views/', 'aggregator');
    }


    /**
     * Get the services provided by the provider
     * @return array
     */
    public function provides(): array
    {
        return ['laravel-wallet-sweeper'];
    }
}