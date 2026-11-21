# Laravel TZ Payments

[![tests](https://github.com/jonasjohn-dev/laravel-tz-payments/actions/workflows/tests.yml/badge.svg)](https://github.com/jonasjohn-dev/laravel-tz-payments/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**One Laravel API for Tanzanian payment gateways.** Accept M-Pesa, Airtel Money, Mixx by Yas, HaloPesa, AzamPesa and card payments through ClickPesa, AzamPay or Pesapal, and write your payment code once. Changing gateway becomes a `.env` setting, not a rewrite.

> [!WARNING]
> **Work in progress. Not ready for production.** v0.1 is not released yet, and no driver has been tested against a real gateway. Do not use it to take real payments.

```php
use JonasJohn\TzPayments\Events\PaymentSucceeded;
use JonasJohn\TzPayments\Facades\TzPay;

$payment = TzPay::charge([
    'amount'    => 2000,          // TZS 2,000
    'phone'     => '0754123456',  // any common format
    'reference' => 'ORDER-1001',
]);

// Later, when the customer has paid:
Event::listen(function (PaymentSucceeded $event) {
    Order::where('number', $event->payment->reference)->update(['paid' => true]);
});
```

## Why

Every Tanzanian gateway has its own authentication, request format, phone format, status names and webhook style. This package hides those differences behind one small, well-tested interface:

- **One API** for every gateway: `TzPay::charge()` and `TzPay::status()`.
- **Phone numbers normalised** (`0754…`, `+255…`, `255…`) and the operator detected.
- **One set of statuses:** Pending, Succeeded, Failed, Cancelled, Expired, Reversed.
- **No double charges:** a reference that is pending or paid cannot be charged again.
- **No fake payments:** every webhook is verified by signature or by asking the gateway, and checked against the stored amount.
- **Paid stays paid:** duplicate or late webhooks change nothing, and `PaymentSucceeded` fires exactly once.
- **Everything recorded:** every payment and every webhook is stored in your database.
- **Easy to test:** `TzPay::fake()` replaces every gateway in your own tests.

## Gateways

| Gateway | Payment style | Webhook verification | Status |
|---|---|---|---|
| [AzamPay](docs/gateways/azampay.md) | USSD push | RSA signature | Built, not yet sandbox-tested |
| [ClickPesa](docs/gateways/clickpesa.md) | USSD push, card (USD), hosted checkout | HMAC checksum, or status lookup | Built, not yet live-tested (no sandbox) |
| [Pesapal](docs/gateways/pesapal.md) | Hosted checkout (mobile, card, bank) | Status lookup, checked against the payment | Built, not yet sandbox-tested |

Payouts (disbursements) are planned for v0.2. You can [add any other gateway](docs/custom-gateways.md) yourself.

## Requirements

- PHP 8.1 or newer
- Laravel 10, 11, 12 or 13

## Installation (once released)

```bash
composer require jonasjohn/laravel-tz-payments
php artisan vendor:publish --tag=tz-payments-config
php artisan vendor:publish --tag=tz-payments-migrations
php artisan migrate
```

Then add your gateway's credentials to `.env`, and point its webhooks at `https://your-app.com/tz-payments/webhooks/{gateway}`. See [Installation](docs/installation.md).

## Documentation

- [Installation](docs/installation.md)
- [Taking payments](docs/payments.md): amounts, phone numbers, references, statuses and errors
- [Webhooks and events](docs/webhooks.md)
- Gateways: [AzamPay](docs/gateways/azampay.md), [ClickPesa](docs/gateways/clickpesa.md), [Pesapal](docs/gateways/pesapal.md)
- [How it works](docs/how-it-works.md): the safety guarantees
- [Configuration](docs/configuration.md)
- [Testing your app](docs/testing.md)
- [Adding a gateway](docs/custom-gateways.md)
- [Troubleshooting](docs/troubleshooting.md)

## Amounts at a glance

Amounts are whole numbers in the smallest unit of the currency. No decimals, no floats.

| You want | Pass |
|---|---|
| TZS 500 | `'amount' => 500` |
| TZS 2,000 | `'amount' => 2000` |
| USD 10.50 | `'amount' => 1050, 'currency' => 'USD'` |

## Development

```bash
composer test      # Pest
composer analyse   # PHPStan level 8
composer format    # Laravel Pint
```

Every push is tested on PHP 8.1 to 8.5 across Laravel 10 to 13.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). To report a security problem, follow [SECURITY.md](SECURITY.md) and do not open a public issue.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE).
