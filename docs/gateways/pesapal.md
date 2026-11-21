# Pesapal

Pesapal shows its own payment page, where the customer pays by mobile money, card or bank.

| | |
|---|---|
| Payment style | Hosted checkout page (redirect) |
| Pays with | Mobile money, Visa, Mastercard and bank, as enabled on your Pesapal account |
| Currencies | TZS, USD and the others your account supports |
| Status lookup | Yes |
| Webhook verification | Status lookup (Pesapal notifications carry no signature) |
| Sandbox | Yes |
| Tested against the real sandbox | **Not yet** |

## Setup

1. Copy your consumer key and secret into `.env`. Pesapal publishes sandbox keys on its developer site.

   ```dotenv
   PESAPAL_ENV=sandbox
   PESAPAL_CONSUMER_KEY=
   PESAPAL_CONSUMER_SECRET=
   PESAPAL_CALLBACK_URL=https://your-app.com/payment/return
   ```

2. Register your notification (IPN) URL once:

   ```bash
   php artisan tz-payments:pesapal-register-ipn
   ```

   It registers `https://your-app.com/tz-payments/webhooks/pesapal` and prints a line to add to `.env`:

   ```dotenv
   PESAPAL_IPN_ID=e32182ca-0983-4fa0-91bc-c3bb813ba750
   ```

   Other forms of the command:

   ```bash
   php artisan tz-payments:pesapal-register-ipn https://example.com/ipn   # a different URL
   php artisan tz-payments:pesapal-register-ipn --method=GET              # Pesapal calls it with GET
   php artisan tz-payments:pesapal-register-ipn --list                    # list registered URLs
   ```

   Register again, and update `PESAPAL_IPN_ID`, when you move from sandbox to live.

## Taking a payment

```php
$payment = TzPay::charge([
    'amount'         => 2000,
    'customer_email' => 'asha@example.com',
    'phone'          => '0754123456',
    'customer_name'  => 'Asha Juma',
    'description'    => 'Order 1001',
    'reference'      => 'ORDER-1001',
], 'pesapal');

return redirect()->away($payment->redirectUrl);
```

- A phone number, an email address, or both, is required.
- `callback_url` is where Pesapal sends the customer afterwards. Pass it per payment, or set `PESAPAL_CALLBACK_URL`.
- The description is cut to Pesapal's limit of 100 characters. Without one, `Payment ORDER-1001` is sent.
- USD and other currencies work the same way: `'amount' => 1050, 'currency' => 'USD'` is $10.50.

### Options

| Option | Values | Description |
|---|---|---|
| `cancellation_url` | URL | Where the customer goes if they cancel |
| `redirect_mode` | `TOP_WINDOW`, `PARENT_WINDOW` | How Pesapal redirects when its page is in an iframe |
| `branch` | string | Your store branch name, shown in the Pesapal dashboard |

## The return page

When the customer returns to your `callback_url`, Pesapal adds `OrderTrackingId` and `OrderMerchantReference` to it. The return itself does **not** mean the customer paid. Show "Payment received, confirming…" and wait for the `PaymentSucceeded` event, or check:

```php
$payment = TzPay::status('ORDER-1001');
```

## Statuses

| Pesapal `status_code` | Description | Package status |
|---|---|---|
| 0 | INVALID | Pending (nobody has paid yet) |
| 1 | COMPLETED | Succeeded |
| 2 | FAILED | Failed |
| 3 | REVERSED | Reversed |

## How notifications are verified

Pesapal's IPN only says "something changed". It carries no status and no signature. For every IPN, the package:

1. Finds the payment, and asks Pesapal's `GetTransactionStatus` for the order using the **stored** tracking id, not the one in the IPN.
2. Accepts the answer only if its reference, amount and currency match the payment. A forged IPN that points your order at someone else's paid order is rejected.
3. Replies with the JSON Pesapal requires:

   ```json
   {"orderNotificationType": "IPNCHANGE", "orderTrackingId": "...", "orderMerchantReference": "...", "status": 200}
   ```

## Good to know

- Tokens last 5 minutes and are cached for about 4.
- Pesapal can only look an order up by its tracking id. If the order request timed out before Pesapal returned one, the payment stays pending until an IPN arrives.

### Still to confirm in the sandbox

- The phone number is sent in local form (`0754123456`), as in Pesapal's examples.
