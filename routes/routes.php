<?php

use Illuminate\Support\Facades\Route;
use Rublex\Wallet\Aggregator\Http\Controllers\DashboardController;

/*
 * This file is part of the Laravel Rublex Wallet Aggregator package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

Route::group([
	'prefix'  =>  config('aggregator.path', 'rublex-aggregator'),
], function () {
	Route::get('/', DashboardController::class)->name('rublex-aggregator.dashboard');
});
