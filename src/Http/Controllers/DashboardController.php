<?php

namespace Rublex\Wallet\Aggregator\Http\Controllers;

use Illuminate\Http\Request;

/*
 * This file is part of the Laravel Rublex Wallet Aggregator package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class DashboardController
{
    public function __invoke(Request $request)
    {
        return view('aggregator::dashboard');
    }
}
