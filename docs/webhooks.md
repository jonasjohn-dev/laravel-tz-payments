# Webhooks and events

A webhook is a message a gateway sends to your app when a payment changes. Anyone on the internet could send a fake one, so the package verifies every webhook before it changes anything. You never write a webhook controller yourself: you listen for events.

- [The webhook URL](#the-webhook-url)
- [How a webhook is handled](#how-a-webhook-is-handled)
- [Events](#events)
- [The webhook log](#the-webhook-log)
- [Replies to the gateway](#replies-to-the-gateway)

## The webhook URL

Every gateway sends to the same route, with its name at the end:

```
https://your-app.com/tz-payments/webhooks/azampay
https://your-app.com/tz-payments/webhooks/clickpesa
https://your-app.com/tz-payments/webhooks/pesapal
```

- It accepts GET and POST, because Pesapal can use either.
- It has no CSRF protection, and needs none. Do not add the `web` middleware group to it.
- The prefix and middleware can be changed, or the route switched off, in [the config](configuration.md#webhooks). Its name is `tz-payments.webhook`.

## How a webhook is handled

Every webhook goes through the same steps:

1. **Log it** in `tz_webhook_calls`, with passwords and keys hidden.
2. **Read it.** If it can't be read, answer `400`.
3. **Find the payment** by the gateway's reference. If none matches, answer `200` and change nothing, so the gateway stops retrying.
4. **Verify it:**
   - If the gateway signed it (AzamPay's RSA signature, ClickPesa's checksum) and the signature is valid, use the status in the webhook.
   - Otherwise, ask the gateway's status API for the real status. Accept the answer only if its reference, amount and currency match the stored payment.
   - If neither is possible, answer `403` and change nothing.
5. **Apply it once.** The payment row is locked inside a database transaction. The status changes only if the move is allowed (see [Statuses](payments.md#statuses)), and events fire only on a real change.
6. **Reply** in the format the gateway expects.

A signed webhook that reports a different amount from the stored payment is also rejected with `403`.

| Answer | Meaning |
|---|---|
| `200` | Handled, or ignored because the payment is unknown |
| `400` | The webhook could not be read |
| `403` | The webhook is not authentic, or could not be verified |
| `404` | No gateway with that name is configured |
| `503` | The gateway's status API could not be reached. The gateway will retry later. |

## Events

Listen for events to update your orders. In Laravel 11 and later, listeners in `app/Listeners` are discovered automatically. Otherwise, register them in a service provider:

```php
use Illuminate\Support\Facades\Event;
use JonasJohn\TzPayments\Events\PaymentFailed;
use JonasJohn\TzPayments\Events\PaymentSucceeded;

Event::listen(function (PaymentSucceeded $event) {
    $order = Order::where('number', $event->payment->reference)->firstOrFail();
    $order->markAsPaid();
});

Event::listen(function (PaymentFailed $event) {
    // $event->payment->status is Failed, Cancelled or Expired
});
```

| Event | Properties | Fired when |
|---|---|---|
| `PaymentInitiated` | `payment` | The gateway accepted the request. The customer has not paid yet. |
| `PaymentSucceeded` | `payment` | The money was received. **Exactly once** per payment. |
| `PaymentFailed` | `payment` | The payment failed, was cancelled or expired. Exactly once. |
| `PaymentReversed` | `payment` | A succeeded payment was reversed or refunded by the gateway. |
| `PaymentStatusChanged` | `payment`, `previous` | Any real status change, just before the specific event above. |
| `WebhookHandled` | `gateway`, `payment`, `event` | A verified webhook was applied. Fired for every delivery, including duplicates. |

All events are in the `JonasJohn\TzPayments\Events` namespace, and `$event->payment` is the `Payment` model. They work with queued listeners.

Put business logic in the payment events, not in `WebhookHandled`, because a gateway can deliver the same webhook several times.

There is deliberately no "webhook received" event. A listener must never act on a webhook before it has been verified.

### Listen to events, not the return page

Hosted pages (Pesapal, ClickPesa checkout) send the customer back to your `callback_url`. That visit proves nothing: anyone can open the URL. On that page, show "Payment received, confirming…" and rely on `PaymentSucceeded` or `TzPay::status()`.

## The webhook log

Every webhook is stored in `tz_webhook_calls`:

| Column | Description |
|---|---|
| `gateway` | Which gateway sent it |
| `payment_id` | The matched payment, if any |
| `status` | `received`, `processed`, `ignored`, `rejected` or `failed` |
| `payload` | The data received, with secrets replaced by `[redacted]` |
| `message` | Why it was ignored, rejected or failed |

```php
use JonasJohn\TzPayments\Models\WebhookCall;

WebhookCall::where('status', WebhookCall::STATUS_REJECTED)->latest()->take(20)->get();
```

Keys named `password`, `user`, `secret`, `token`, `api_key` or `apiKey` are hidden at any depth. Add your own in [`webhooks.redact`](configuration.md#webhooks).

Old rows are removed by Laravel's prune command. See [Installation, step 6](installation.md#6-schedule-the-clean-up-optional). The log can be switched off with `webhooks.log`.

## Replies to the gateway

Each gateway gets the reply it expects:

| Gateway | Reply |
|---|---|
| AzamPay | `{"success": true}` |
| ClickPesa | `{"received": true}` |
| Pesapal | The notification echoed back with `"status": 200`, as Pesapal requires |
