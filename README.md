# Laravel TZ Payments

[![tests](https://github.com/jonasjohn-dev/laravel-tz-payments/actions/workflows/tests.yml/badge.svg)](https://github.com/jonasjohn-dev/laravel-tz-payments/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**One Laravel API for Tanzanian payment gateways.** Accept M-Pesa, Airtel Money, Mixx by Yas, HaloPesa, AzamPesa and card payments through ClickPesa, AzamPay or Pesapal, and write your payment code once. Changing gateway becomes a `.env` setting, not a rewrite.

> [!WARNING]
> **Work in progress. Not ready for production.** The package is being built toward v0.1. The API below is the planned design and may still change. Do not use it to take real payments yet.

## Why

Every Tanzanian gateway has its own authentication, request format, phone number format, status names and webhook style. This package hides those differences behind one small, well-tested interface:

- **One API** to start a payment and check its status, on any supported gateway.
- **Phone numbers normalised** for you: `0754…`, `754…`, `255…` and `+255…` all work.
- **One status vocabulary:** `Pending`, `Succeeded`, `Failed`, `Cancelled`, `Expired`.
- **Safe webhooks:** every webhook is verified (by signature or by asking the gateway), duplicates are ignored, and a payment is never marked paid twice.
- **Every payment saved** in your database.
- **A fake gateway** for testing your own app without real money.

## Supported gateways (planned for v0.1)

| Gateway  | Payment style                    | Webhook verification                  | Sandbox tested |
|----------|----------------------------------|---------------------------------------|----------------|
| ClickPesa| USSD push, hosted checkout       | HMAC-SHA256 checksum + status lookup  | Not yet        |
| AzamPay  | USSD push                        | RSA signature                         | Not yet        |
| Pesapal  | Hosted checkout (mobile, card, bank) | Status lookup (IPN carries no status) | Not yet    |

Selcom and DPO are planned after v0.1.

## Requirements

- PHP 8.1 or newer
- Laravel 10, 11, 12 or 13

> Laravel 10 and 11 no longer receive security fixes. They are still supported by this package, but Composer 2.9+ blocks their releases by default. We recommend Laravel 12 or newer.

## Installation (once released)

```bash
composer require jonasjohn/laravel-tz-payments
php artisan vendor:publish --tag=tz-payments-config
php artisan vendor:publish --tag=tz-payments-migrations
php artisan migrate
```

## Planned usage

```php
use JonasJohn\TzPayments\Facades\TzPay;

// Start a payment with the default gateway
$payment = TzPay::charge([
    'amount'    => 5000,          // whole TZS
    'phone'     => '0754123456',  // any common format
    'reference' => 'ORDER-1001',  // optional
]);

// Or choose a gateway for one call
TzPay::charge([...], 'azampay');

// Check the status
TzPay::status('ORDER-1001');

// React when money arrives
Event::listen(PaymentSucceeded::class, function ($event) {
    // mark the order paid
});
```

## Testing

```bash
composer test
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). To report a security problem, read [SECURITY.md](SECURITY.md) and do not open a public issue.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE).
