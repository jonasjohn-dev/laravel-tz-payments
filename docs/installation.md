# Installation

## Requirements

- PHP 8.1 or newer, with the `json` and `openssl` extensions
- Laravel 10, 11, 12 or 13
- A database supported by Laravel (MySQL, PostgreSQL, SQLite and SQL Server all work)
- A cache store that supports atomic locks in production (Redis, database or Memcached). It prevents two simultaneous charges for the same order. See [How it works](how-it-works.md#no-double-charges).

> Laravel 10 and 11 no longer receive security fixes. They are still supported, but Composer 2.9 and later refuse to install them by default because of their unfixed security advisories. Use Laravel 12 or newer if you can.

## 1. Install the package

```bash
composer require jonasjohn/laravel-tz-payments
```

Laravel finds the service provider automatically.

## 2. Publish the config and the migration

```bash
php artisan vendor:publish --tag=tz-payments-config
php artisan vendor:publish --tag=tz-payments-migrations
php artisan migrate
```

This creates two tables:

| Table | Holds |
|---|---|
| `tz_payments` | Every payment attempt and its status |
| `tz_webhook_calls` | A log of every webhook received |

Both names can be changed in [the config](configuration.md#database).

## 3. Add your credentials

Choose the default gateway and add its credentials to `.env`. Only fill in the gateways you use.

```dotenv
TZ_PAYMENTS_GATEWAY=azampay
```

Each gateway page lists its own variables:

- [AzamPay](gateways/azampay.md#setup)
- [ClickPesa](gateways/clickpesa.md#setup)
- [Pesapal](gateways/pesapal.md#setup)

Credentials are only ever read from config and `.env`. They never appear in error messages or logs.

## 4. Tell the gateway where to send results

Every gateway reports results to one URL in your app:

```
https://your-app.com/tz-payments/webhooks/{gateway}
```

For example `https://your-app.com/tz-payments/webhooks/azampay`. The route is registered for you and needs no CSRF exemption. Each gateway page explains where to enter it.

The URL must be reachable from the internet. For local development, expose your app with a tunnel such as Expose, ngrok or Cloudflare Tunnel.

## 5. Take a payment

```php
use JonasJohn\TzPayments\Facades\TzPay;

$payment = TzPay::charge([
    'amount'    => 2000,          // TZS 2,000
    'phone'     => '0754123456',
    'reference' => 'ORDER-1001',
]);
```

Then listen for the result. See [Taking payments](payments.md) and [Webhooks and events](webhooks.md).

## 6. Schedule the clean-up (optional)

The webhook log keeps 30 days by default. Remove old rows with Laravel's prune command, in `routes/console.php` (Laravel 11 and later) or your console kernel (Laravel 10):

```php
use Illuminate\Support\Facades\Schedule;
use JonasJohn\TzPayments\Models\WebhookCall;

Schedule::command('model:prune', ['--model' => [WebhookCall::class]])->daily();
```
