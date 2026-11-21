# Adding a gateway

Any gateway can be added without changing the package. A driver is one class. The package keeps doing the storage, duplicate protection, webhook verification rules and events for it.

## The contract

```php
namespace JonasJohn\TzPayments\Contracts;

interface Gateway
{
    public function supports(Capability $capability): bool;

    public function charge(PaymentRequest $request): GatewayResponse;

    public function status(StatusRequest $request): GatewayResponse;
}
```

If the gateway sends webhooks, also implement `HandlesWebhooks`:

```php
interface HandlesWebhooks
{
    public function parseWebhook(Request $request): WebhookEvent;

    public function acknowledgeWebhook(WebhookEvent $event): Response;
}
```

## The rules a driver must follow

1. **Never retry a charge.** A retry could charge the customer twice.
2. **Say when the outcome is unknown.** On a timeout or a server error after sending, throw `GatewayException::timeout()` or `GatewayException::ambiguous()`, so the payment stays pending. Throw `GatewayException::requestFailed()` only when nothing was charged.
3. **Map every gateway status** to a `PaymentStatus`. An unknown or in-progress status is `Pending`, never `Failed`.
4. **Mark a webhook `verified` only when it is proven**, for example by a valid signature. Otherwise leave it unverified, and the package confirms it with `status()`.
5. **Report what the gateway says** in `GatewayResponse`: `merchantReference`, `amount` and `currency` when the gateway returns them. The package then refuses answers that belong to another payment.
6. **Never put credentials** in exception messages or `$response` data.
7. **Validate early.** Throw `InvalidRequestException` for input the gateway can't take, before any HTTP call.

## Example

Extending `HttpGateway` gives you an HTTP client with the configured timeout, token caching, one safe retry on 401, and the right exception for each kind of connection failure.

```php
namespace App\Payments;

use Illuminate\Http\Client\Response;
use JonasJohn\TzPayments\Data\GatewayResponse;
use JonasJohn\TzPayments\Data\PaymentRequest;
use JonasJohn\TzPayments\Data\StatusRequest;
use JonasJohn\TzPayments\Drivers\HttpGateway;
use JonasJohn\TzPayments\Enums\Capability;
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Exceptions\GatewayException;
use JonasJohn\TzPayments\Exceptions\InvalidRequestException;
use JonasJohn\TzPayments\Support\Currency;

class AcmePayGateway extends HttpGateway
{
    protected function name(): string
    {
        return 'acmepay';
    }

    public function supports(Capability $capability): bool
    {
        return in_array($capability, [Capability::UssdPush, Capability::StatusLookup], true);
    }

    public function charge(PaymentRequest $request): GatewayResponse
    {
        if ($request->phone === null) {
            throw InvalidRequestException::missingField($this->name(), 'phone');
        }

        $response = $this->sendPaymentRequest(fn (): Response => $this->request()
            ->withToken($this->requireConfig('api_key'))
            ->post('https://api.acmepay.test/push', [
                'reference' => $request->merchantReference,
                'amount' => Currency::toDecimal($request->amount, $request->currency),
                'msisdn' => $request->phone->international(),
            ]));

        if ($response->serverError()) {
            throw GatewayException::ambiguous($this->name(), $response->status(), $this->body($response));
        }

        if (! $response->successful()) {
            throw GatewayException::requestFailed($this->name(), $response->status(), $this->body($response));
        }

        return new GatewayResponse(
            status: PaymentStatus::Pending,
            gatewayReference: $response->json('id'),
            raw: $this->body($response),
        );
    }

    public function status(StatusRequest $request): GatewayResponse
    {
        $response = $this->sendSafeRequest(fn (): Response => $this->request()
            ->withToken($this->requireConfig('api_key'))
            ->get('https://api.acmepay.test/payments/'.$request->merchantReference));

        $state = (string) $response->json('state');

        return new GatewayResponse(
            status: match ($state) {
                'PAID' => PaymentStatus::Succeeded,
                'FAILED' => PaymentStatus::Failed,
                default => PaymentStatus::Pending,
            },
            gatewayStatus: $state,
            merchantReference: $response->json('reference'),
            amount: Currency::fromDecimal((string) $response->json('amount'), 'TZS'),
            currency: 'TZS',
            raw: $this->body($response),
        );
    }
}
```

## Registering it

Add its config to `config/tz-payments.php`:

```php
'gateways' => [
    // ...
    'acmepay' => [
        'api_key' => env('ACMEPAY_API_KEY'),
    ],
],
```

Then register it in a service provider's `boot()` method:

```php
use App\Payments\AcmePayGateway;
use Illuminate\Http\Client\Factory as HttpFactory;
use JonasJohn\TzPayments\Facades\TzPay;

TzPay::extend('acmepay', fn ($app) => new AcmePayGateway(
    array_merge(['timeout' => config('tz-payments.timeout')], config('tz-payments.gateways.acmepay')),
    $app->make(HttpFactory::class),
    $app->make('cache')->store(),
));
```

It works like a built-in gateway:

```php
TzPay::charge(['amount' => 2000, 'phone' => '0754123456'], 'acmepay');
```

Its webhooks arrive at `/tz-payments/webhooks/acmepay`.

## Testing it

Use `Http::fake()` with the gateway's real response shapes, and test the failure cases, not only success: a refusal, a server error, a timeout, a forged webhook and a status answer for another payment. The tests in `tests/Feature/Drivers` are a template.

Want to share it? Open an issue first. See [CONTRIBUTING.md](../CONTRIBUTING.md).
