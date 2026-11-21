# Changelog

All notable changes to `jonasjohn/laravel-tz-payments` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Package skeleton: Composer setup, test suite (Pest + Testbench), CI for PHP 8.1 to 8.5 across Laravel 10 to 13, Pint code style.
- Core: `TzPay` facade and manager (Laravel Manager pattern, `TzPay::extend()` for custom gateways), `Gateway` and `HandlesWebhooks` contracts.
- `TzPay::charge()` and `TzPay::status()` with one response object for every gateway.
- Payment storage in `tz_payments` with a publishable migration, configurable table, connection and model.
- Statuses `Pending`, `Succeeded`, `Failed`, `Cancelled`, `Expired` and `Reversed`. Final statuses never change, except a reversal after success.
- Duplicate-charge protection: a reference that is pending or paid cannot be charged again, guarded by an atomic lock.
- Timeouts leave the payment pending instead of failed, because the customer may still pay.
- Webhook route `/tz-payments/webhooks/{gateway}`: verified webhooks are applied, unverified ones are checked with the gateway's status API, and anything unverifiable is rejected with 403. Duplicates fire events once.
- Events `PaymentInitiated`, `PaymentSucceeded`, `PaymentFailed`, `PaymentReversed` and `PaymentStatusChanged`.
- Tanzanian phone normalisation and operator detection; gateway-safe 20-character references.
- `TzPay::fake()` with `assertCharged`, `assertNotCharged`, `assertNothingCharged` and `assertChargedTimes`.
- Amounts in the smallest unit of each currency (5000 TZS, 1050 USD = $10.50) with `Currency` conversion to gateway formats; decimals configurable.
- The gateway's raw status (such as `SETTLED`) is stored in `gateway_status` next to the normalised status.
- Webhook log in `tz_webhook_calls` (processed, ignored, rejected, failed), with secrets redacted and old rows pruned by `model:prune`.
- `WebhookHandled` event, fired only after a webhook was verified.
- Static analysis with Larastan (PHPStan level 8) in CI.
- **AzamPay driver** (mobile money checkout): cached tokens with one retry on 401, provider detected from the phone number or set with `options.provider` (including Azampesa), TZS only up to 5,000,000, sandbox and live URLs (overridable).
- AzamPay callbacks verified by RSA signature (SHA-256, PKCS#1 v1.5) with the public key cached and re-fetched once on failure, plus an optional secret token in the callback URL. Callbacks with a mismatched amount are rejected.
- Verified webhooks that report a different amount from the stored payment are rejected.
- `options` field on charges for gateway-specific settings.
- `guzzlehttp/guzzle` is now a direct requirement, because Laravel 10 does not install it by default.
