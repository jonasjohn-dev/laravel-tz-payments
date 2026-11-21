# Contributing

Thank you for helping. Contributions are welcome through pull requests on [GitHub](https://github.com/jonasjohn-dev/laravel-tz-payments).

## Setup

```bash
git clone https://github.com/jonasjohn-dev/laravel-tz-payments.git
cd laravel-tz-payments
composer install
composer test
```

## Before you open a pull request

- **Add tests.** A change without tests will not be merged.
- **Run the tests:** `composer test`
- **Fix code style:** `composer format` (Laravel Pint)
- **Run static analysis:** `composer analyse` (PHPStan level 8, must report no errors)
- **Update [CHANGELOG.md](CHANGELOG.md)** under "Unreleased".
- **One pull request per feature or fix.**
- **Never commit real credentials**, including sandbox keys.

## Adding a gateway

Each gateway is one driver class that implements the package's `Gateway` interface. A new driver should:

- map every status the gateway can return to one of `Pending`, `Succeeded`, `Failed`, `Cancelled` or `Expired`;
- verify webhooks by signature or by asking the gateway's status API, and never trust an unverified webhook;
- never retry a charge request automatically;
- come with tests that fake the gateway's HTTP responses (`Http::fake()`), so no test calls a real gateway.

Please open an issue first to discuss a new gateway before writing it.

## Security

Do not report security problems in public issues. See [SECURITY.md](SECURITY.md).
