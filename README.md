# Laravel TZ Payments

[![tests](https://github.com/jonasjohn-dev/laravel-tz-payments/actions/workflows/tests.yml/badge.svg)](https://github.com/jonasjohn-dev/laravel-tz-payments/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**One Laravel API for Tanzanian payment gateways.** Accept M-Pesa, Airtel Money, Mixx by Yas, HaloPesa, AzamPesa and card payments through ClickPesa, AzamPay or Pesapal, and write your payment code once. Changing gateway becomes a `.env` setting, not a rewrite.

> [!WARNING]
> **Work in progress. Not ready for production.** The package is being built toward v0.1. No driver has been tested against a real gateway yet. Do not use it to take real payments.

## Why

Every Tanzanian gateway has its own authentication, request format, phone number format, status names and webhook style. This package hides those differences behind one small, well-tested interface:

- **One API** to start a payment and check its status, on any gateway.
- **Phone numbers normalised** for you: `0754…`, `754…`, `255…` and `+255…` all work, and the mobile operator is detected.
- **One status vocabulary:** `Pending`, `Succeeded`, `Failed`, `Cancelled`, `Expired`, `Reversed`.
- **No double charges:** a reference that is pending or paid cannot be charged again.
- **Safe webhooks:** every webhook is verified by signature or by asking the gateway. Duplicates change nothing, and a paid payment is never marked paid twice.
- **Every payment saved** in your database, with a log of every webhook received.
- **A fake gateway** for testing your own app without real money.

## Gateways

| Gateway   | Payment style                          | Webhook verification            | Status     |
|-----------|----------------------------------------|---------------------------------|------------|
| AzamPay   | USSD push                              | RSA signature, optional URL token | Built, not yet sandbox-tested |
| ClickPesa | USSD push, card (USD), hosted checkout | HMAC-SHA256 checksum + status lookup | Planned |
| Pesapal   | Hosted checkout (mobile, card, bank)   | Status lookup, checked against the stored payment | Built, not yet sandbox-tested |

ClickPesa has no sandbox: every call moves real money. Payouts (disbursements) are planned for v0.2.

## Requirements

- PHP 8.1 or newer
- Laravel 10, 11, 12 or 13

> Laravel 10 and 11 no longer receive security fixes. They are still supported, but Composer 2.9+ blocks their releases by default. We recommend Laravel 12 or newer.

## Installation (once released)

```bash
composer require jonasjohn/laravel-tz-payments
php artisan vendor:publish --tag=tz-payments-config
php artisan vendor:publish --tag=tz-payments-migrations
php artisan migrate
```

Then add your gateway credentials to `.env`:

```dotenv
TZ_PAYMENTS_GATEWAY=azampay

AZAMPAY_ENV=sandbox
AZAMPAY_APP_NAME=
AZAMPAY_CLIENT_ID=
AZAMPAY_CLIENT_SECRET=
AZAMPAY_API_KEY=
```

## Usage

### Amounts

Amounts are always **whole numbers in the smallest unit of the currency**. No decimals, no floats:

| You want      | Pass                                    |
|---------------|-----------------------------------------|
| TZS 500       | `'amount' => 500`                       |
| TZS 2,000     | `'amount' => 2000`                      |
| USD 10.50     | `'amount' => 1050, 'currency' => 'USD'` |

The package converts the amount to the format each gateway expects.

### Start a payment

```php
use JonasJohn\TzPayments\Facades\TzPay;

$payment = TzPay::charge([
    'amount'    => 2000,
    'phone'     => '0754123456',   // any common format
    'reference' => 'ORDER-1001',   // your own reference; generated if left out
]);

$payment->status;           // PaymentStatus::Pending
$payment->isPending();      // true
$payment->requiresRedirect(); // true for hosted checkouts: send the customer to $payment->redirectUrl
```

Optional fields: `currency`, `description`, `customer_name`, `customer_email`, `callback_url`, `meta` (your own data, stored but never sent) and `options` (gateway-specific settings).

Choose a gateway for one call:

```php
TzPay::charge([...], 'azampay');
```

### Check the status

```php
$payment = TzPay::status('ORDER-1001');
```

A final payment is answered from your database. A pending one is checked with the gateway when the gateway supports it.

### React when money arrives

```php
use JonasJohn\TzPayments\Events\PaymentSucceeded;

Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
    Order::where('reference', $event->payment->reference)->update(['paid' => true]);
});
```

| Event                  | When                                                        |
|------------------------|-------------------------------------------------------------|
| `PaymentInitiated`     | The gateway accepted the request; the customer has not paid yet |
| `PaymentSucceeded`     | The money was received (fired once)                         |
| `PaymentFailed`        | Failed, cancelled or expired (fired once)                    |
| `PaymentReversed`      | A paid payment was reversed by the gateway                   |
| `PaymentStatusChanged` | Any real status change                                       |
| `WebhookHandled`       | A verified webhook was processed                            |

### Errors

| Exception                       | When                                                         |
|---------------------------------|--------------------------------------------------------------|
| `InvalidRequestException`       | Bad phone, amount, currency, option or missing config        |
| `DuplicatePaymentException`     | The reference is already pending or paid                     |
| `GatewayException`              | The gateway refused or failed. `$e->outcomeUnknown` is true when the payment may still complete; it then stays pending |
| `UnsupportedOperationException` | The gateway cannot do what was asked                         |
| `PaymentNotFoundException`      | `TzPay::status()` with an unknown reference                   |

All of them extend `TzPaymentsException`. Messages never contain keys, tokens or secrets.

## Webhooks

Every gateway sends its webhooks to:

```
https://your-app.com/tz-payments/webhooks/{gateway}
```

The route needs no CSRF protection, and you don't need to write a controller.

Every webhook is stored in `tz_webhook_calls`, with passwords and keys hidden. Remove old rows (30 days by default) by scheduling Laravel's prune command:

```php
Schedule::command('model:prune', [
    '--model' => [JonasJohn\TzPayments\Models\WebhookCall::class],
])->daily();
```

## Gateway setup

### AzamPay

1. Create an app in the AzamPay sandbox portal and copy the app name, client ID, client secret and API key into `.env`.
2. Set the callback URL in AzamPay to `https://your-app.com/tz-payments/webhooks/azampay`.
3. Optional extra protection: set `AZAMPAY_CALLBACK_TOKEN` to a long random value and add `?token=<that value>` to the callback URL.

The operator is detected from the phone number (Mpesa, Airtel, Tigo, Halopesa). To charge an AzamPesa wallet, choose it explicitly:

```php
TzPay::charge(['amount' => 2000, 'phone' => '0754123456', 'options' => ['provider' => 'Azampesa']], 'azampay');
```

AzamPay accepts TZS only, up to TZS 5,000,000 per payment. It has no status lookup for checkouts, so status changes arrive through the signed callback.

### Pesapal

The customer pays on Pesapal's own page, by mobile money, card or bank.

1. Copy your consumer key and secret into `.env`:

   ```dotenv
   PESAPAL_ENV=sandbox
   PESAPAL_CONSUMER_KEY=
   PESAPAL_CONSUMER_SECRET=
   PESAPAL_CALLBACK_URL=https://your-app.com/payment/return
   ```

2. Register your notification (IPN) URL once, and put the id it prints in `.env` as `PESAPAL_IPN_ID`:

   ```bash
   php artisan tz-payments:pesapal-register-ipn
   php artisan tz-payments:pesapal-register-ipn --list   # see what is registered
   ```

3. Send the customer to the payment page:

   ```php
   $payment = TzPay::charge([
       'amount'         => 2000,
       'customer_email' => 'buyer@example.com', // a phone, an email, or both
       'customer_name'  => 'Asha Juma',
       'description'    => 'Order 1001',
   ], 'pesapal');

   return redirect()->away($payment->redirectUrl);
   ```

When the customer comes back to your callback page, show "payment received, confirming…" and rely on the `PaymentSucceeded` event or `TzPay::status()`, never on the return alone. Pesapal's notifications carry no status and no signature, so the package always asks Pesapal for the real status, and accepts the answer only if its reference, amount and currency match the stored payment.

Optional per-payment `options`: `cancellation_url`, `redirect_mode` (`TOP_WINDOW` or `PARENT_WINDOW`) and `branch`.

## Testing your own app

```php
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Facades\TzPay;

$gateway = TzPay::fake();   // no real gateway is called; payments are still saved and events still fire

// ... run your checkout code ...

TzPay::assertCharged(fn ($request) => $request->amount === 2000);
$gateway->setStatus('ORDER-1001', PaymentStatus::Succeeded); // decide the outcome
```

Also available: `assertNotCharged`, `assertNothingCharged`, `assertChargedTimes`, `chargeWillFail`, `chargeWillRedirectTo` and `withoutCapability`.

## Adding your own gateway

Implement `JonasJohn\TzPayments\Contracts\Gateway` (and `HandlesWebhooks` if it sends webhooks), then register it:

```php
TzPay::extend('selcom', fn ($app) => new SelcomGateway(config('tz-payments.gateways.selcom')));
```

## Development

```bash
composer test      # Pest
composer analyse   # PHPStan level 8
composer format    # Laravel Pint
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). To report a security problem, read [SECURITY.md](SECURITY.md) and do not open a public issue.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE).
