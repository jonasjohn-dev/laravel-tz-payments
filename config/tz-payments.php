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
    | Currency decimals
    |--------------------------------------------------------------------------
    |
    | Amounts are always whole numbers in the smallest unit in practical use:
    | 5000 TZS is five thousand shillings, 1050 USD is $10.50. Other currencies
    | default to 2 decimals.
    |
    */

    'currencies' => [
        'TZS' => 0,
        'USD' => 2,
    ],

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
        'webhook_calls_table' => 'tz_webhook_calls',
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

        // Keep a log of every webhook in the webhook_calls_table. Prune old rows with:
        // Schedule::command('model:prune', ['--model' => [JonasJohn\TzPayments\Models\WebhookCall::class]])->daily();
        'log' => true,
        'log_retention_days' => 30,

        // Payload keys replaced with "[redacted]" before logging, at any depth.
        'redact' => ['password', 'user', 'secret', 'token', 'api_key', 'apiKey'],
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

            // Optional extra protection: add ?token=<this value> to the callback URL you give AzamPay.
            'callback_token' => env('AZAMPAY_CALLBACK_TOKEN'),

            // Leave empty to use AzamPay's standard URLs for the environment above.
            'auth_url' => env('AZAMPAY_AUTH_URL'),
            'base_url' => env('AZAMPAY_BASE_URL'),
        ],

        'pesapal' => [
            'environment' => env('PESAPAL_ENV', 'sandbox'),
            'consumer_key' => env('PESAPAL_CONSUMER_KEY'),
            'consumer_secret' => env('PESAPAL_CONSUMER_SECRET'),
            // Get it with: php artisan tz-payments:pesapal-register-ipn
            'ipn_id' => env('PESAPAL_IPN_ID'),

            // Where Pesapal sends the customer after paying. Can also be set per payment with 'callback_url'.
            'callback_url' => env('PESAPAL_CALLBACK_URL'),

            // Leave empty to use Pesapal's standard URL for the environment above.
            'base_url' => env('PESAPAL_BASE_URL'),
        ],

    ],

];
