<?php
namespace rosasurfer\rost\view;

use rosasurfer\core\StaticClass;

use const rosasurfer\rost\OP_BUY;
use const rosasurfer\rost\OP_SELL;
use const rosasurfer\rost\OP_BUYLIMIT;
use const rosasurfer\rost\OP_SELLLIMIT;
use const rosasurfer\rost\OP_BUYSTOP;
use const rosasurfer\rost\OP_SELLSTOP;
use const rosasurfer\rost\OP_BALANCE;
use const rosasurfer\rost\OP_CREDIT;
use const rosasurfer\rost\OP_TRANSFER;
use const rosasurfer\rost\OP_VENDOR;


/**
 * ViewHelper
 */
class ViewHelper extends StaticClass {


    /** @var string[] - operation types */
    public static $operationTypes = [
        OP_BUY       => 'buy'       ,   // 0
        OP_SELL      => 'sell'      ,   // 1
        OP_BUYLIMIT  => 'buy limit' ,   // 2
        OP_SELLLIMIT => 'sell limit',   // 3
        OP_BUYSTOP   => 'stop buy'  ,   // 4
        OP_SELLSTOP  => 'stop sell' ,   // 5
        OP_BALANCE   => 'balance'   ,   // 6
        OP_CREDIT    => 'credit'    ,   // 7
        OP_TRANSFER  => 'transfer'  ,   // 8
        OP_VENDOR    => 'vendor'    ,   // 9
    ];

    /** @var string[] - instruments and their names */
    public static $instruments = [
        'AUDCAD' => 'AUD/CAD',
        'AUDCHF' => 'AUD/CHF',
        'AUDJPY' => 'AUD/JPY',
        'AUDNZD' => 'AUD/NZD',
        'AUDUSD' => 'AUD/USD',
        'CADCHF' => 'CAD/CHF',
        'CADJPY' => 'CAD/JPY',
        'CHFJPY' => 'CHF/JPY',
        'EURAUD' => 'EUR/AUD',
        'EURCAD' => 'EUR/CAD',
        'EURCHF' => 'EUR/CHF',
        'EURDKK' => 'EUR/DKK',
        'EURGBP' => 'EUR/GBP',
        'EURJPY' => 'EUR/JPY',
        'EURNOK' => 'EUR/NOK',
        'EURNZD' => 'EUR/NZD',
        'EURRUR' => 'EUR/RUR',
        'EURSEK' => 'EUR/SEK',
        'EURUSD' => 'EUR/USD',
        'GBPAUD' => 'GBP/AUD',
        'GBPCAD' => 'GBP/CAD',
        'GBPCHF' => 'GBP/CHF',
        'GBPJPY' => 'GBP/JPY',
        'GBPNZD' => 'GBP/NZD',
        'GBPRUR' => 'GBP/RUR',
        'GBPUSD' => 'GBP/USD',
        'NZDCAD' => 'NZD/CAD',
        'NZDCHF' => 'NZD/CHF',
        'NZDJPY' => 'NZD/JPY',
        'NZDUSD' => 'NZD/USD',
        'SGDJPY' => 'SGD/JPY',
        'USDCAD' => 'USD/CAD',
        'USDCHF' => 'USD/CHF',
        'USDCZK' => 'USD/CZK',
        'USDDKK' => 'USD/DKK',
        'USDHKD' => 'USD/HKD',
        'USDHUF' => 'USD/HUF',
        'USDJPY' => 'USD/JPY',
        'USDMXN' => 'USD/MXN',
        'USDNOK' => 'USD/NOK',
        'USDPLN' => 'USD/PLN',
        'USDRUR' => 'USD/RUR',
        'USDSEK' => 'USD/SEK',
        'USDSGD' => 'USD/SGD',
        'USDZAR' => 'USD/ZAR',
    ];
}
