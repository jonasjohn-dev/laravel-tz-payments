# Laravel TZ Payments documentation

One Laravel API for Tanzanian payment gateways: ClickPesa, AzamPay and Pesapal.

> **Work in progress.** v0.1 is not released yet, and no driver has been tested against a real gateway. Don't take real payments with it yet.

## Start here

1. [Installation](installation.md): requirements, install, migrate, credentials
2. [Taking payments](payments.md): charging, amounts, statuses, responses and errors
3. [Webhooks and events](webhooks.md): how payment results reach your app

## Gateways

- [AzamPay](gateways/azampay.md): USSD push (mobile money)
- [ClickPesa](gateways/clickpesa.md): USSD push, card and hosted checkout (no sandbox)
- [Pesapal](gateways/pesapal.md): hosted checkout (mobile money, card and bank)

## Reference

- [How it works](how-it-works.md): the payment lifecycle and the safety guarantees
- [Configuration](configuration.md): every config option and environment variable
- [Testing your app](testing.md): `TzPay::fake()` and its assertions
- [Adding a gateway](custom-gateways.md): writing your own driver
- [Troubleshooting](troubleshooting.md): common errors and how to fix them

## Which gateway can do what

| | AzamPay | ClickPesa | Pesapal |
|---|---|---|---|
| USSD push to the customer's phone | Yes | Yes (TZS) | No |
| Hosted payment page | No | Yes (TZS, USD) | Yes |
| Card payments | No | Yes (USD) | Yes, on its page |
| Status lookup | No | Yes | Yes |
| Webhook verification | RSA signature | HMAC checksum, or status lookup | Status lookup |
| Sandbox | Yes | **No**: every call is live | Yes |
