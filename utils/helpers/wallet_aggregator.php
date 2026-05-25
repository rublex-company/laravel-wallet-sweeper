<?php

if (! function_exists("wallet_sweeper"))
{
    function wallet_sweeper() {
        
        return app()->make('laravel-wallet-sweeper');
    }
}