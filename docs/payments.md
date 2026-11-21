# Taking payments

- [Starting a payment](#starting-a-payment)
- [Amounts and currencies](#amounts-and-currencies)
- [Phone numbers](#phone-numbers)
- [References](#references)
- [The response](#the-response)
- [Checking the status](#checking-the-status)
- [Statuses](#statuses)
- [Errors](#errors)
- [Choosing a gateway](#choosing-a-gateway)

## Starting a payment

```php
use JonasJohn\TzPayments\Facades\TzPay;

$payment = TzPay::charge([
    'amount'    => 2000,
    'phone'     => '0754123456',
    'reference' => 'ORDER-1001',
]);
```

`TzPay::charge()` returns at once. The customer has not paid yet: they still have to approve the prompt on their phone, or pay on the gateway's page. The result arrives later through a [webhook](webhooks.md) and fires an event.

### All fields

| Field | Type | Required | Description |
|---|---|---|---|
| `amount` | int | Yes | Whole number in the currency's smallest unit. See [Amounts](#amounts-and-currencies). |
| `phone` | string | Depends | The customer's phone number, in any common format. Required for USSD push. |
| `reference` | string | No | Your own reference, such as an order number (up to 100 characters). Generated if left out. |
| `currency` | string | No | Three-letter code. Default: `TZS` (or `TZ_PAYMENTS_CURRENCY`). |
| `description` | string | No | Shown to the customer where the gateway supports it. |
| `customer_name` | string | Depends | Required for ClickPesa card payments. |
| `customer_email` | string | Depends | Required for ClickPesa card payments, and for Pesapal when there is no phone. |
| `callback_url` | string | Depends | Where a hosted page sends the customer afterwards. Required for Pesapal unless set in config. |
| `operator` | string | No | Override the detected mobile operator: `vodacom`, `airtel`, `yas`, `halotel` or `ttcl`. |
| `meta` | array | No | Your own data. Stored with the payment, never sent to the gateway. |
| `options` | array | No | Gateway-specific settings, listed on each [gateway page](README.md#gateways). |

Every field is validated before any gateway is called. Invalid input throws an `InvalidRequestException` that says what to fix.

## Amounts and currencies

Amounts are always **whole numbers in the smallest unit of the currency**. Floats and decimal strings are refused, so rounding errors cannot reach money.

| You want | Pass |
|---|---|
| TZS 500 | `'amount' => 500` |
| TZS 2,000 | `'amount' => 2000` |
| TZS 150,000 | `'amount' => 150000` |
| USD 10.50 | `'amount' => 1050, 'currency' => 'USD'` |
| USD 25.00 | `'amount' => 2500, 'currency' => 'USD'` |

TZS is handled in whole shillings and USD in cents. The package converts the amount into the format each gateway expects, for example `1050` USD becomes `"10.50"`. Other currencies default to two decimals; change that in [the config](configuration.md#currency).

A whole-number string such as `'2000'` is also accepted.

Which currencies a gateway accepts:

| Gateway | Currencies |
|---|---|
| AzamPay | TZS only (up to 5,000,000 per payment) |
| ClickPesa | TZS for USSD push, USD for cards, TZS or USD for the hosted page |
| Pesapal | TZS, USD and the other currencies your Pesapal account supports |

## Phone numbers

Pass the number in any common Tanzanian format. All of these work:

```
0754123456     754123456     255754123456     +255754123456
00255754123456     +255 754 123 456     0754-123-456
```

The package stores and sends it as `255754123456`, and rejects anything that is not a Tanzanian mobile number (`255` followed by 9 digits starting with 6 or 7).

The operator is detected from the first two digits after 255:

| Prefix | Operator | Wallet |
|---|---|---|
| 74, 75, 76 | Vodacom | M-Pesa |
| 68, 69, 78 | Airtel | Airtel Money |
| 65, 67, 71, 77 | Yas | Mixx by Yas |
| 61, 62 | Halotel | HaloPesa |
| 73 | TTCL | T-Pesa |

A customer who moved their number to another network may need an explicit `'operator' => 'airtel'`.

You can use the same helper in your own forms:

```php
use JonasJohn\TzPayments\Support\Phone;

Phone::isValid('0754123456');                 // true
Phone::parse('0754123456')->international();  // "255754123456"
Phone::parse('0754123456')->local();          // "0754123456"
Phone::parse('0754123456')->operator();       // Operator::Vodacom
```

## References

Each payment has two references:

| | Example | Who chooses it | Used for |
|---|---|---|---|
| `reference` | `ORDER-1001` | You | Finding your payment: `TzPay::status('ORDER-1001')` |
| `merchantReference` | `TZP7K2M9QX4R8ZT1BN5D` | The package | What the gateway sees. Always 20 letters and digits, unique per attempt. |

The gateway reference is generated because gateways restrict references (ClickPesa allows at most 20 letters and digits) and reject reused ones.

One `reference` can have several attempts. If the customer cancels, call `TzPay::charge()` again with the same reference. A reference that is still **pending** or already **paid** is refused, so nobody pays twice.

To generate gateway references yourself, for example with your own prefix:

```php
use JonasJohn\TzPayments\Support\Reference;

Reference::generateUsing(fn () => 'SHOP'.strtoupper(Str::random(16)));
```

They must be unique, letters and digits only, and at most 20 characters.

## The response

`TzPay::charge()` and `TzPay::status()` return the same `PaymentResponse` for every gateway:

| Property | Example | Description |
|---|---|---|
| `gateway` | `"azampay"` | The gateway name |
| `reference` | `"ORDER-1001"` | Your reference |
| `merchantReference` | `"TZP7K2M9QX4R8ZT1BN5D"` | The reference sent to the gateway |
| `status` | `PaymentStatus::Pending` | See [Statuses](#statuses) |
| `amount` | `2000` | In the smallest unit |
| `currency` | `"TZS"` | |
| `gatewayReference` | `"AZ-TX-1"` | The gateway's own id, when it gives one |
| `gatewayStatus` | `"PROCESSING"` | The gateway's own status word, for debugging |
| `redirectUrl` | `"https://..."` | Set for hosted pages: send the customer here |
| `message` | `"Request sent"` | The gateway's message, if any |
| `raw` | `[...]` | The gateway's latest response body |

And three helpers: `isPending()`, `isSuccessful()` and `requiresRedirect()`.

For hosted pages, send the customer on:

```php
$payment = TzPay::charge([...]);

if ($payment->requiresRedirect()) {
    return redirect()->away($payment->redirectUrl);
}

return view('checkout.waiting-for-phone', ['reference' => $payment->reference]);
```

The stored payment is an Eloquent model, `JonasJohn\TzPayments\Models\Payment`, so you can query it like any other:

```php
use JonasJohn\TzPayments\Models\Payment;

Payment::where('reference', 'ORDER-1001')->latest('id')->first();
```

## Checking the status

```php
$payment = TzPay::status('ORDER-1001');
```

It returns the latest attempt for that reference:

- A **final** payment (succeeded, failed, cancelled, expired or reversed) is answered from your database, with no gateway call.
- A **pending** payment is checked with the gateway, if the gateway can look payments up (AzamPay can't). Any change is saved and fires the usual events.

A page that waits for a USSD payment can poll this every few seconds. Webhooks normally arrive first.

## Statuses

```php
use JonasJohn\TzPayments\Enums\PaymentStatus;
```

| Status | Meaning | Final |
|---|---|---|
| `Pending` | Waiting for the customer or the gateway | No |
| `Succeeded` | The money was received | Yes, but can still become `Reversed` |
| `Failed` | The gateway refused it, or the customer could not pay | Yes |
| `Cancelled` | The customer cancelled | Yes |
| `Expired` | The customer never responded in time (set by a planned clean-up command, not yet released) | Yes |
| `Reversed` | The gateway returned the money after success | Yes |

A final status never changes, with one exception: `Succeeded` can become `Reversed`. A late or duplicate webhook can't turn a paid order back into a failed one, or a failed one into a paid one.

The gateway's own status word is kept next to it in `gatewayStatus`, for example `SETTLED` for ClickPesa or `COMPLETED` for Pesapal.

## Errors

All exceptions extend `JonasJohn\TzPayments\Exceptions\TzPaymentsException`.

| Exception | When | What to do |
|---|---|---|
| `InvalidRequestException` | Bad phone, amount, currency, email or option, or missing config | Fix the input or `.env`. The message says what is wrong. |
| `DuplicatePaymentException` | The reference is already pending or paid, or being charged at this moment | Show the existing payment instead of charging again. |
| `GatewayException` | The gateway refused or failed | See below |
| `UnsupportedOperationException` | The gateway can't do what was asked | Use another gateway or channel. |
| `PaymentNotFoundException` | `TzPay::status()` with an unknown reference | |

`GatewayException` tells you whether the customer could still be charged:

```php
use JonasJohn\TzPayments\Exceptions\GatewayException;

try {
    $payment = TzPay::charge([...]);
} catch (GatewayException $e) {
    if ($e->outcomeUnknown) {
        // The request may have reached the gateway (a timeout, or a server error after
        // sending). The payment stays Pending and may still succeed: tell the customer
        // to wait, and do NOT charge again.
    } else {
        // Nothing was charged. The payment is marked Failed, and the customer can try again.
    }

    $e->httpStatus; // the gateway's HTTP status, if any
    $e->response;   // the gateway's response body (never your credentials)
}
```

The package never retries a charge by itself, because a retry could charge the customer twice.

## Choosing a gateway

The default comes from `TZ_PAYMENTS_GATEWAY`. Pass a name to use another one for a single payment:

```php
TzPay::charge([...], 'pesapal');
```

Check what a gateway can do before offering an option:

```php
use JonasJohn\TzPayments\Enums\Capability;

TzPay::gateway('azampay')->supports(Capability::StatusLookup);   // false
TzPay::gateway('clickpesa')->supports(Capability::HostedCheckout); // true
```

The capabilities are `UssdPush`, `HostedCheckout` and `StatusLookup`.
