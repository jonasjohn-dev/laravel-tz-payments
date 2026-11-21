<?php

use JonasJohn\TzPayments\Models\Payment;

return [

    /*
    |--------------------------------------------------------------------------
    | Default gateway
    |--------------------------------------------------------------------------
    |
    | Used when TzPay::charge() is called without a gateway name.
    | Supported: "clickpesa", "azampay", "pesapal", or any name added with TzPay::extend().
    |
    */

    'default' => env('TZ_PAYMENTS_GATEWAY', 'clickpesa'),

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    */

    'currency' => env('TZ_PAYMENTS_CURRENCY', 'TZS'),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait for a gateway. Charges are never retried automatically,
    | because a retry could charge the customer twice.
    |
    */

    'timeout' => (int) env('TZ_PAYMENTS_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Every payment is stored. Safe webhooks and duplicate protection depend on it.
    | You may use your own model, as long as it extends the package's Payment model.
    |
    */

    'database' => [
        'connection' => env('TZ_PAYMENTS_DB_CONNECTION'),
        'table' => 'tz_payments',
        'model' => Payment::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Each gateway posts to {prefix}/{gateway}, for example /tz-payments/webhooks/azampay.
    | These routes need no CSRF protection, so do not add the "web" middleware group.
    |
    */

    'webhooks' => [
        'enabled' => true,
        'prefix' => 'tz-payments/webhooks',
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    |
    | Credentials always come from .env. Set each gateway's environment to
    | "sandbox" while testing and "live" in production.
    |
    */

    'gateways' => [

        'clickpesa' => [
            'environment' => env('CLICKPESA_ENV', 'sandbox'),
            'client_id' => env('CLICKPESA_CLIENT_ID'),
            'api_key' => env('CLICKPESA_API_KEY'),
            'checksum_key' => env('CLICKPESA_CHECKSUM_KEY'),
        ],

        'azampay' => [
            'environment' => env('AZAMPAY_ENV', 'sandbox'),
            'app_name' => env('AZAMPAY_APP_NAME'),
            'client_id' => env('AZAMPAY_CLIENT_ID'),
            'client_secret' => env('AZAMPAY_CLIENT_SECRET'),
            'api_key' => env('AZAMPAY_API_KEY'),
        ],

        'pesapal' => [
            'environment' => env('PESAPAL_ENV', 'sandbox'),
            'consumer_key' => env('PESAPAL_CONSUMER_KEY'),
            'consumer_secret' => env('PESAPAL_CONSUMER_SECRET'),
            'ipn_id' => env('PESAPAL_IPN_ID'),
        ],

    ],

];
