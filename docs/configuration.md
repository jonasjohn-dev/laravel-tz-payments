# Configuration

Publish the config file to change it:

```bash
php artisan vendor:publish --tag=tz-payments-config
```

It is created at `config/tz-payments.php`. Most settings can be set from `.env`.

## General

| Key | `.env` variable | Default | Description |
|---|---|---|---|
| `default` | `TZ_PAYMENTS_GATEWAY` | `clickpesa` | The gateway used when `TzPay::charge()` gets no gateway name |
| `currency` | `TZ_PAYMENTS_CURRENCY` | `TZS` | The currency used when a payment has none |
| `timeout` | `TZ_PAYMENTS_TIMEOUT` | `30` | Seconds to wait for a gateway. Charges are never retried. |

## Currency

```php
'currencies' => [
    'TZS' => 0,
    'USD' => 2,
],
```

The number of decimals in each currency's smallest unit. With `'USD' => 2`, an amount of `1050` means $10.50. Currencies not listed use 2. TZS is set to 0 on purpose: Tanzanian amounts are whole shillings.

## Database

```php
'database' => [
    'connection'          => env('TZ_PAYMENTS_DB_CONNECTION'), // null = your default connection
    'table'               => 'tz_payments',
    'webhook_calls_table' => 'tz_webhook_calls',
    'model'               => JonasJohn\TzPayments\Models\Payment::class,
],
```

Change the table names **before** running the migration.

To add your own methods or relations, extend the model and point the config at it:

```php
namespace App\Models;

use JonasJohn\TzPayments\Models\Payment as BasePayment;

class Payment extends BasePayment
{
    public function order()
    {
        return $this->belongsTo(Order::class, 'reference', 'number');
    }
}
```

```php
'model' => App\Models\Payment::class,
```

The model must extend the package's `Payment` model, or a clear error is thrown.

Storage cannot be switched off. Duplicate protection and safe webhooks depend on it.

## Webhooks

```php
'webhooks' => [
    'enabled'            => true,
    'prefix'             => 'tz-payments/webhooks',
    'middleware'         => [],
    'log'                => true,
    'log_retention_days' => 30,
    'redact'             => ['password', 'user', 'secret', 'token', 'api_key', 'apiKey'],
],
```

| Key | Description |
|---|---|
| `enabled` | Register the webhook route. Turn off only if you route webhooks yourself. |
| `prefix` | The route becomes `{prefix}/{gateway}`. |
| `middleware` | Middleware for the route, for example `['throttle:120,1']`. Do not add `web`: webhooks can't carry a CSRF token. |
| `log` | Store every webhook in the webhook log table. |
| `log_retention_days` | How long `model:prune` keeps log rows. |
| `redact` | Payload keys replaced with `[redacted]` in the log, at any depth, ignoring case. |

## Gateways

Each gateway has its own block. Only fill in the ones you use.

### AzamPay

| Key | `.env` variable | Description |
|---|---|---|
| `environment` | `AZAMPAY_ENV` | `sandbox` (default) or `live` |
| `app_name` | `AZAMPAY_APP_NAME` | From the AzamPay portal |
| `client_id` | `AZAMPAY_CLIENT_ID` | |
| `client_secret` | `AZAMPAY_CLIENT_SECRET` | |
| `api_key` | `AZAMPAY_API_KEY` | Sent as `X-API-Key` |
| `callback_token` | `AZAMPAY_CALLBACK_TOKEN` | Optional. Callbacks must then carry `?token=<value>` |
| `auth_url` | `AZAMPAY_AUTH_URL` | Optional. Overrides the token server URL |
| `base_url` | `AZAMPAY_BASE_URL` | Optional. Overrides the checkout server URL |

### ClickPesa

| Key | `.env` variable | Description |
|---|---|---|
| `client_id` | `CLICKPESA_CLIENT_ID` | |
| `api_key` | `CLICKPESA_API_KEY` | |
| `checksum_key` | `CLICKPESA_CHECKSUM_KEY` | Set it when checksums are enabled in your ClickPesa dashboard |
| `base_url` | `CLICKPESA_BASE_URL` | Optional. Overrides `https://api.clickpesa.com/third-parties` |

ClickPesa has no environment setting because it has no sandbox.

### Pesapal

| Key | `.env` variable | Description |
|---|---|---|
| `environment` | `PESAPAL_ENV` | `sandbox` (default) or `live` |
| `consumer_key` | `PESAPAL_CONSUMER_KEY` | |
| `consumer_secret` | `PESAPAL_CONSUMER_SECRET` | |
| `ipn_id` | `PESAPAL_IPN_ID` | Printed by `php artisan tz-payments:pesapal-register-ipn` |
| `callback_url` | `PESAPAL_CALLBACK_URL` | Where customers return after paying. Can be set per payment. |
| `base_url` | `PESAPAL_BASE_URL` | Optional. Overrides Pesapal's URL for the environment |

## Config caching

The package reads settings only through `config()`, so `php artisan config:cache` works. Remember to run it again after changing `.env` in production.
