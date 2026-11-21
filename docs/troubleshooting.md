# Troubleshooting

## "The [gateway] gateway is missing the [key] configuration value"

The credential is not set. Check `.env`, then clear the config cache:

```bash
php artisan config:clear
```

## "The phone number [...] is not a valid Tanzanian mobile number"

The number must be `255` followed by 9 digits starting with 6 or 7, in any common format. Numbers from other countries are not accepted.

## "A payment for reference [...] is already pending or has succeeded"

This is the double-charge protection working. Show the existing payment with `TzPay::status($reference)` instead of charging again. A new attempt is allowed once the previous one has failed, been cancelled or expired.

## "A payment for reference [...] is already being started"

Two requests tried to charge the same reference at the same moment, for example a double-click. Only the first one went through.

## The payment stays Pending

1. **Was a webhook received?** Look at the log:

   ```php
   JonasJohn\TzPayments\Models\WebhookCall::latest()->take(10)->get(['gateway', 'status', 'message', 'created_at']);
   ```

   - No rows: the gateway is not reaching your app. Check the webhook URL in the gateway's dashboard, and that the URL is public (not `localhost`).
   - `rejected`: the `message` column says why. Usually a wrong secret, a missing signature or an amount mismatch.
   - `ignored`: no payment matched. Check the webhook was sent to the right gateway URL.
   - `failed`: the gateway's status API could not be reached. The gateway will retry.

2. **Ask the gateway directly:** `TzPay::status($reference)`. This works for ClickPesa and Pesapal. AzamPay has no status lookup, so it depends on its callback.

3. **Pesapal**: `INVALID` means nobody has paid yet. That is pending, not failed.

## Webhooks are answered with 403

The webhook could not be proven authentic. Check the gateway's setting:

| Gateway | Check |
|---|---|
| AzamPay | The callback has a `signature`, or `AZAMPAY_CALLBACK_TOKEN` is set and the URL has `?token=` with the same value |
| ClickPesa | `CLICKPESA_CHECKSUM_KEY` matches the key in your ClickPesa dashboard. Without a key, webhooks are confirmed through the status API instead. |
| Pesapal | The status lookup answered for a different order, amount or currency |

## Webhooks are answered with 419

The `web` middleware group (CSRF protection) was added to the webhook route. Remove it from `tz-payments.webhooks.middleware`.

## ClickPesa: "rate limited by ClickPesa, retry after N seconds"

ClickPesa allows one new access token every 3 minutes per client id and IP address. The package caches tokens to stay within this. If several servers share one client id, make sure they share a cache store (Redis or database), so they share the token.

## Laravel 10 or 11: Composer refuses to install

Composer 2.9 and later block framework versions with known security advisories, and Laravel 10 and 11 no longer get fixes. Upgrade Laravel if you can.

## Still stuck?

Open an issue on [GitHub](https://github.com/jonasjohn-dev/laravel-tz-payments/issues) with the error message and the gateway name. **Never post credentials.** Report security problems privately, as described in [SECURITY.md](../SECURITY.md).
