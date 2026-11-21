# Testing your app

`TzPay::fake()` replaces every gateway with a fake that never calls the internet and never moves money. Payments are still saved and events still fire, so your own code runs as it would in production.

```php
use JonasJohn\TzPayments\Data\PaymentRequest;
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Facades\TzPay;

it('charges the customer for the order', function () {
    $gateway = TzPay::fake();

    $this->post('/checkout', ['order' => 1001, 'phone' => '0754123456'])->assertOk();

    TzPay::assertCharged(fn (PaymentRequest $request) =>
        $request->amount === 2000 && $request->reference === 'ORDER-1001'
    );
});
```

## Deciding the outcome

The fake starts every payment as `Pending`. Change that before running your code:

| Method | Effect |
|---|---|
| `chargeWillReturn(PaymentStatus::Succeeded)` | Every charge returns this status immediately |
| `chargeWillRedirectTo('https://...')` | Every charge returns a hosted page URL |
| `chargeWillFail(new GatewayException('...'))` | Every charge throws this exception |
| `setStatus('ORDER-1001', PaymentStatus::Succeeded)` | What a status lookup reports for a reference (yours or the merchant reference) |
| `withoutCapability(Capability::StatusLookup)` | Behave like a gateway without that capability |
| `withoutWebhookVerification()` | Treat webhooks as unsigned, so the status lookup decides |

The methods can be chained:

```php
use JonasJohn\TzPayments\Exceptions\GatewayException;

TzPay::fake()->chargeWillFail(GatewayException::timeout('fake'));
```

## Assertions

| Method | Passes when |
|---|---|
| `TzPay::assertCharged()` | At least one charge was made |
| `TzPay::assertCharged(fn ($request) => ...)` | A charge matched the callback |
| `TzPay::assertNotCharged(fn ($request) => ...)` | No charge matched the callback |
| `TzPay::assertNothingCharged()` | No charge was made |
| `TzPay::assertChargedTimes(2)` | Exactly that many charges were made |

The callback receives a `PaymentRequest` with `amount`, `currency`, `reference`, `merchantReference`, `phone`, `operator`, `description`, `customerName`, `customerEmail`, `callbackUrl`, `meta` and `options`.

You can also read the fake directly: `$gateway->charged()` returns every request, and `$gateway->statusChecks()` counts status lookups.

## Simulating the payment result

### With a status lookup

```php
$gateway = TzPay::fake();
$this->post('/checkout', [...]);

$gateway->setStatus('ORDER-1001', PaymentStatus::Succeeded);
TzPay::status('ORDER-1001');   // applies the change and fires PaymentSucceeded

expect(Order::find(1001)->paid)->toBeTrue();
```

### With a webhook

The fake accepts this webhook body at any gateway's URL:

```php
$gateway = TzPay::fake();
$payment = TzPay::charge(['amount' => 2000, 'phone' => '0754123456']);

$this->postJson('/tz-payments/webhooks/azampay', [
    'merchant_reference' => $payment->merchantReference,
    'status'             => 'succeeded',   // any PaymentStatus value
    'gateway_status'     => 'SUCCESS',     // optional
])->assertOk();
```

This runs the real webhook handling: the log, the once-only rules and the events.

## Asserting events

Fake only the package events you check, so model events keep working:

```php
use Illuminate\Support\Facades\Event;
use JonasJohn\TzPayments\Events\PaymentSucceeded;

Event::fake([PaymentSucceeded::class]);
// ...
Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
```

## Testing against the real drivers

To test your integration with a real driver without calling the gateway, use Laravel's `Http::fake()` with the gateway's response shapes. The package's own tests in `tests/Feature/Drivers` show how for every gateway.
