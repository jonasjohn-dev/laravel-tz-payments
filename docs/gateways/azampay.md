# AzamPay

AzamPay sends a USSD prompt to the customer's phone. The customer approves it with their PIN.

| | |
|---|---|
| Payment style | USSD push |
| Wallets | M-Pesa, Airtel Money, Mixx by Yas (Tigo), HaloPesa, AzamPesa |
| Currency | TZS only, up to 5,000,000 per payment |
| Status lookup | No: results arrive only through the callback |
| Webhook verification | RSA signature (SHA-256), plus an optional secret token in the URL |
| Sandbox | Yes |
| Tested against the real sandbox | **Not yet** |

## Setup

1. Register an app in the AzamPay sandbox portal, and copy its details into `.env`:

   ```dotenv
   AZAMPAY_ENV=sandbox
   AZAMPAY_APP_NAME=
   AZAMPAY_CLIENT_ID=
   AZAMPAY_CLIENT_SECRET=
   AZAMPAY_API_KEY=
   ```

2. Set the callback URL of your AzamPay app to:

   ```
   https://your-app.com/tz-payments/webhooks/azampay
   ```

3. Optional extra protection: set a long random token and add it to the callback URL.

   ```dotenv
   AZAMPAY_CALLBACK_TOKEN=a-long-random-value
   ```

   ```
   https://your-app.com/tz-payments/webhooks/azampay?token=a-long-random-value
   ```

For production, set `AZAMPAY_ENV=live` and use your live credentials, after AzamPay approves your KYC.

## Taking a payment

```php
$payment = TzPay::charge([
    'amount'    => 2000,
    'phone'     => '0754123456',
    'reference' => 'ORDER-1001',
], 'azampay');
```

The wallet is chosen from the phone number:

| Number starts with | Sent to AzamPay as |
|---|---|
| 074, 075, 076 | `Mpesa` |
| 068, 069, 078 | `Airtel` |
| 065, 067, 071, 077 | `Tigo` |
| 061, 062 | `Halopesa` |

TTCL numbers are not supported by AzamPay.

### Options

| Option | Values | Description |
|---|---|---|
| `provider` | `Mpesa`, `Airtel`, `Tigo`, `Halopesa`, `Azampesa` | Choose the wallet yourself. Needed for AzamPesa. |

```php
TzPay::charge([
    'amount'  => 2000,
    'phone'   => '0754123456',
    'options' => ['provider' => 'Azampesa'],
], 'azampay');
```

## How callbacks are verified

AzamPay signs each callback. The package:

1. Rebuilds the signed text: `utilityref` + `externalreference` + `transactionstatus` + `operator`.
2. Checks the `signature` with AzamPay's public key (RSA, SHA-256, PKCS#1 v1.5).
3. Caches the public key for 24 hours. If a check fails, it fetches the key again once before rejecting, in case AzamPay changed it.
4. Rejects the callback if its `amount` differs from the stored payment.

If `AZAMPAY_CALLBACK_TOKEN` is set, the token must also match. A callback with neither a signature nor a configured token is rejected.

| AzamPay `transactionstatus` | Package status |
|---|---|
| `success` | Succeeded |
| `failure`, `failed` | Failed |
| `cancelled` | Cancelled |

AzamPay's callback includes `user` and `password` fields. They are hidden in the webhook log.

## Good to know

- Tokens are cached until the expiry AzamPay gives, renewed a minute early.
- AzamPay has no status lookup for checkouts, so `TzPay::status()` returns the stored status. Make sure the callback URL is set correctly.
- The live server URLs are not in AzamPay's published API description. If they differ from the defaults (`authenticator.azampay.co.tz` and `checkout.azampay.co.tz`), set `AZAMPAY_AUTH_URL` and `AZAMPAY_BASE_URL`.

### Still to confirm in the sandbox

AzamPay's API description is unclear on a few points. The driver follows the most likely reading, and these will be confirmed with a real sandbox payment before v0.1:

- The amount is sent as a string (`"2000"`), as in AzamPay's code samples.
- The phone number is sent as `255754123456`.
- The signature covers the four fields listed above.
