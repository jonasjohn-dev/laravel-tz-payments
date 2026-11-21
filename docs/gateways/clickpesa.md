# ClickPesa

ClickPesa offers three ways to collect money, and the package supports all of them.

| | |
|---|---|
| Payment styles | USSD push (TZS), card page (USD), hosted checkout page (TZS or USD) |
| Wallets | M-Pesa, Airtel Money, Mixx by Yas, HaloPesa, and others ClickPesa enables |
| Status lookup | Yes |
| Webhook verification | HMAC-SHA256 checksum when a checksum key is set; otherwise a status lookup |
| Sandbox | **None.** Every call is live and moves real money. |
| Tested against the real API | **Not yet** |

> **There is no sandbox.** Test with small real amounts, such as TZS 500. Before your KYC is approved, ClickPesa limits your account.

## Setup

1. Create an application in the ClickPesa dashboard, and copy its credentials into `.env`:

   ```dotenv
   CLICKPESA_CLIENT_ID=
   CLICKPESA_API_KEY=
   CLICKPESA_CHECKSUM_KEY=   # only if checksums are enabled in your dashboard
   ```

2. In the dashboard, add application webhooks for **PAYMENT RECEIVED** and **PAYMENT FAILED**, pointing to:

   ```
   https://your-app.com/tz-payments/webhooks/clickpesa
   ```

3. If you whitelist IP addresses in ClickPesa, add your server's address. Requests from other addresses are refused.

## Taking a payment

The package picks the method from what you pass:

| You pass | Method |
|---|---|
| TZS and a phone number | USSD push to the phone |
| No phone number, or USD | Hosted checkout page |
| `'options' => ['channel' => 'card']` | Card page |
| `'options' => ['channel' => 'checkout']` or `['channel' => 'ussd']` | That method |

### USSD push

```php
TzPay::charge([
    'amount'    => 2000,          // TZS 2,000
    'phone'     => '0754123456',
    'reference' => 'ORDER-1001',
], 'clickpesa');
```

### Hosted checkout page

The customer chooses how to pay on ClickPesa's page.

```php
$payment = TzPay::charge([
    'amount'         => 2000,
    'customer_name'  => 'Asha Juma',
    'customer_email' => 'asha@example.com',
    'description'    => 'Order 1001',
], 'clickpesa');

return redirect()->away($payment->redirectUrl);
```

After paying, the customer returns to the **Return URL** set on your application in the ClickPesa dashboard.

### Card (USD)

```php
$payment = TzPay::charge([
    'amount'         => 1050,          // USD 10.50
    'currency'       => 'USD',
    'phone'          => '0754123456',
    'customer_name'  => 'Asha Juma',   // must match the name on the card
    'customer_email' => 'asha@example.com',
    'options'        => ['channel' => 'card'],
], 'clickpesa');

return redirect()->away($payment->redirectUrl);
```

Card payments need approved KYC on your ClickPesa account.

### Options

| Option | Values | Description |
|---|---|---|
| `channel` | `ussd`, `card`, `checkout` | Choose the method yourself |

## Statuses

| ClickPesa status | Package status |
|---|---|
| `SUCCESS`, `SETTLED` | Succeeded |
| `PROCESSING`, `PENDING`, `ON-HOLD` | Pending |
| `FAILED` | Failed |
| `REFUNDED`, `REVERSED` | Reversed |

The ClickPesa word is kept in `gatewayStatus`. `SUCCESS` means received, and `SETTLED` means paid out to your account.

When one reference has several attempts (for example a failed card and then a successful one on the same checkout link), a success counts.

## How webhooks are verified

- **With `CLICKPESA_CHECKSUM_KEY` set:** every webhook must carry a valid checksum, or it is rejected. Requests to ClickPesa are signed too. The checksum is HMAC-SHA256 over the body with keys sorted alphabetically at every depth, excluding `checksum` and `checksumMethod`.
- **Without it:** the webhook is treated as a hint, and the package asks ClickPesa's status API for the real status.

Payout and deposit webhooks are acknowledged and ignored. Payouts are planned for v0.2.

## Good to know

- ClickPesa allows **one new access token every 3 minutes**. The package caches each token for 55 minutes of its one-hour life. If several servers use the same client id, give them a shared cache (Redis or database).
- Turning checksums on or off in the dashboard invalidates existing tokens. The package notices the refused token and gets a new one by itself.
- Order references are 20 letters and digits, which is ClickPesa's limit. Reusing one is refused, so the package creates a new one for every attempt.

### Still to confirm with live payments

- Where ClickPesa places the checksum in a webhook (the package reads the top-level `checksum` field).
- The USD decimal format (`"10.50"`).
