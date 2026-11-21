<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JonasJohn\TzPayments\Data\StatusRequest;
use JonasJohn\TzPayments\Drivers\AzamPayGateway;
use JonasJohn\TzPayments\Enums\Capability;
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Events\PaymentSucceeded;
use JonasJohn\TzPayments\Exceptions\GatewayException;
use JonasJohn\TzPayments\Exceptions\InvalidRequestException;
use JonasJohn\TzPayments\Exceptions\UnsupportedOperationException;
use JonasJohn\TzPayments\Facades\TzPay;
use JonasJohn\TzPayments\Models\Payment;
use JonasJohn\TzPayments\Models\WebhookCall;

/**
 * One RSA key pair per test run, standing in for AzamPay's signing key.
 *
 * @return array{private: OpenSSLAsymmetricKey, public: string}
 */
function azampayKeys(): array
{
    static $keys = null;

    if ($keys === null) {
        $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $keys = ['private' => $private, 'public' => openssl_pkey_get_details($private)['key']];
    }

    return $keys;
}

function azampaySign(array $data): string
{
    $signed = $data['utilityref'].$data['externalreference'].$data['transactionstatus'].$data['operator'];
    openssl_sign($signed, $signature, azampayKeys()['private'], OPENSSL_ALGO_SHA256);

    return base64_encode($signature);
}

/** A callback shaped like AzamPay's CallbackRequest schema. */
function azampayCallback(string $utilityRef, array $overrides = [], bool $sign = true): array
{
    $data = array_merge([
        'message' => 'Transaction completed successfully',
        'user' => 'merchant_user',
        'password' => 'merchant_password',
        'clientId' => 'client_123',
        'transactionstatus' => 'success',
        'operator' => 'Mpesa',
        'reference' => 'REF123456789',
        'externalreference' => 'AZ-EXT-1',
        'utilityref' => $utilityRef,
        'amount' => '5000',
        'transid' => 'TXN123456',
        'msisdn' => '255754123456',
        'mnoreference' => 'MNO_REF_001',
        'submerchantAcc' => null,
    ], $overrides);

    if ($sign) {
        $data['signature'] = azampaySign($data);
    }

    return $data;
}

function fakeAzamPay(array $checkout = ['transactionId' => 'AZ-TX-1', 'message' => 'Request sent', 'success' => true], int $checkoutStatus = 200): void
{
    Http::fake([
        '*/AppRegistration/GenerateToken' => Http::response([
            'data' => ['accessToken' => 'test-access-token', 'expire' => now()->addHours(23)->toIso8601String()],
            'message' => 'Token generated successfully',
            'success' => true,
            'statusCode' => 200,
        ]),
        '*/azampay/mno/checkout' => Http::response($checkout, $checkoutStatus),
        '*/azampay/v1/public-key*' => Http::response(['success' => true, 'format' => 'Pem', 'publicKey' => azampayKeys()['public']]),
    ]);
}

beforeEach(function () {
    config([
        'tz-payments.default' => 'azampay',
        'tz-payments.gateways.azampay' => [
            'environment' => 'sandbox',
            'app_name' => 'test-app',
            'client_id' => 'test-client-id',
            'client_secret' => 'super-secret-value',
            'api_key' => 'test-api-key',
        ],
    ]);
});

// Charging

it('sends a mobile money checkout in the format AzamPay expects', function () {
    fakeAzamPay();

    $response = TzPay::charge(['amount' => 5000, 'phone' => '0754123456', 'reference' => 'ORDER-1']);

    expect($response->status)->toBe(PaymentStatus::Pending)
        ->and($response->gateway)->toBe('azampay')
        ->and($response->gatewayReference)->toBe('AZ-TX-1');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://authenticator-sandbox.azampay.co.tz/AppRegistration/GenerateToken'
        && $r['appName'] === 'test-app' && $r['clientId'] === 'test-client-id' && $r['clientSecret'] === 'super-secret-value');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://sandbox.azampay.co.tz/azampay/mno/checkout'
        && $r->hasHeader('Authorization', 'Bearer test-access-token')
        && $r->hasHeader('X-API-Key', 'test-api-key')
        && $r['accountNumber'] === '255754123456'
        && $r['amount'] === '5000'
        && $r['currency'] === 'TZS'
        && $r['externalId'] === $response->merchantReference
        && $r['provider'] === 'Mpesa');
});

it('picks the AzamPay provider from the phone number', function (string $phone, string $provider) {
    fakeAzamPay();

    TzPay::charge(['amount' => 1000, 'phone' => $phone]);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout') && $r['provider'] === $provider);
})->with([
    ['0754123456', 'Mpesa'],
    ['0688123456', 'Airtel'],
    ['0715123456', 'Tigo'],
    ['0622123456', 'Halopesa'],
]);

it('lets the developer choose the provider, such as Azampesa', function () {
    fakeAzamPay();

    TzPay::charge(['amount' => 1000, 'phone' => '0754123456', 'options' => ['provider' => 'azampesa']]);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout') && $r['provider'] === 'Azampesa');
});

it('reuses the cached token instead of asking for a new one each time', function () {
    fakeAzamPay();

    TzPay::charge(['amount' => 1000, 'phone' => '0754123456']);
    TzPay::charge(['amount' => 1000, 'phone' => '0754123456']);

    Http::assertSentCount(3); // one token, two checkouts
});

it('gets a new token once when the old one is refused', function () {
    $checkouts = 0;
    Http::fake([
        '*/AppRegistration/GenerateToken' => Http::response(['data' => ['accessToken' => 'fresh', 'expire' => now()->addHour()->toIso8601String()], 'success' => true]),
        '*/azampay/mno/checkout' => function () use (&$checkouts) {
            return ++$checkouts === 1
                ? Http::response(['message' => 'Unauthorized'], 401)
                : Http::response(['transactionId' => 'AZ-TX-2', 'success' => true]);
        },
    ]);

    expect(TzPay::charge(['amount' => 1000, 'phone' => '0754123456'])->gatewayReference)->toBe('AZ-TX-2')
        ->and($checkouts)->toBe(2);
});

it('uses the live URLs in the live environment', function () {
    config(['tz-payments.gateways.azampay.environment' => 'live']);
    fakeAzamPay();

    TzPay::charge(['amount' => 1000, 'phone' => '0754123456']);

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://authenticator.azampay.co.tz/'));
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://checkout.azampay.co.tz/'));
});

// Failures

it('marks the payment failed when AzamPay refuses it', function () {
    fakeAzamPay(['transactionId' => null, 'message' => 'Invalid account', 'success' => false]);

    expect(fn () => TzPay::charge(['amount' => 1000, 'phone' => '0754123456']))
        ->toThrow(GatewayException::class, 'Invalid account');

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed);
});

it('marks the payment failed on a validation error', function () {
    fakeAzamPay(['title' => 'One or more validation errors occurred', 'status' => 400], 400);

    expect(fn () => TzPay::charge(['amount' => 1000, 'phone' => '0754123456']))->toThrow(GatewayException::class);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed);
});

it('keeps the payment pending when AzamPay fails after receiving the request', function () {
    fakeAzamPay([], 500);

    expect(fn () => TzPay::charge(['amount' => 1000, 'phone' => '0754123456']))->toThrow(GatewayException::class);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('keeps the payment pending when the checkout times out', function () {
    Http::fake([
        '*/AppRegistration/GenerateToken' => Http::response(['data' => ['accessToken' => 't', 'expire' => now()->addHour()->toIso8601String()], 'success' => true]),
        '*/azampay/mno/checkout' => fn () => throw new ConnectionException('timed out'),
    ]);

    expect(fn () => TzPay::charge(['amount' => 1000, 'phone' => '0754123456']))->toThrow(GatewayException::class);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('marks the payment failed when no token can be obtained, because nothing was sent', function () {
    Http::fake(['*/AppRegistration/GenerateToken' => fn () => throw new ConnectionException('down')]);

    expect(fn () => TzPay::charge(['amount' => 1000, 'phone' => '0754123456']))->toThrow(GatewayException::class, 'No payment was started');

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed);
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/checkout'));
});

it('never puts credentials in error messages', function () {
    Http::fake(['*/AppRegistration/GenerateToken' => Http::response(['message' => 'Provided detail is not valid', 'success' => false], 423)]);

    try {
        TzPay::charge(['amount' => 1000, 'phone' => '0754123456']);
    } catch (GatewayException $e) {
        expect($e->getMessage())->not->toContain('super-secret-value')
            ->and(json_encode($e->response))->not->toContain('super-secret-value');
    }

    expect($e ?? null)->toBeInstanceOf(GatewayException::class);
});

it('rejects requests AzamPay cannot take before calling it', function (array $data) {
    Http::fake();

    expect(fn () => TzPay::charge($data))->toThrow(InvalidRequestException::class);

    Http::assertNothingSent();
})->with([
    'USD' => [['amount' => 1000, 'currency' => 'USD', 'phone' => '0754123456']],
    'over the limit' => [['amount' => 5_000_001, 'phone' => '0754123456']],
    'no phone' => [['amount' => 1000]],
    'TTCL number' => [['amount' => 1000, 'phone' => '0733123456']],
    'unknown provider' => [['amount' => 1000, 'phone' => '0754123456', 'options' => ['provider' => 'Paypal']]],
]);

it('explains missing credentials', function () {
    config(['tz-payments.gateways.azampay.client_secret' => null]);
    Http::fake();

    expect(fn () => TzPay::charge(['amount' => 1000, 'phone' => '0754123456']))
        ->toThrow(InvalidRequestException::class, 'client_secret');
});

it('declares that it has no status lookup', function () {
    $gateway = TzPay::gateway('azampay');

    expect($gateway)->toBeInstanceOf(AzamPayGateway::class)
        ->and($gateway->supports(Capability::UssdPush))->toBeTrue()
        ->and($gateway->supports(Capability::StatusLookup))->toBeFalse()
        ->and(fn () => $gateway->status(new StatusRequest('a', 'b')))
        ->toThrow(UnsupportedOperationException::class);
});

// Callbacks

it('accepts a correctly signed callback and marks the payment paid once', function () {
    Event::fake([PaymentSucceeded::class]);
    fakeAzamPay();
    $payment = TzPay::charge(['amount' => 5000, 'phone' => '0754123456']);
    $callback = azampayCallback($payment->merchantReference);

    $this->postJson('/tz-payments/webhooks/azampay', $callback)->assertOk()->assertJson(['success' => true]);
    $this->postJson('/tz-payments/webhooks/azampay', $callback)->assertOk();

    $stored = Payment::sole();
    expect($stored->status)->toBe(PaymentStatus::Succeeded)
        ->and($stored->gateway_status)->toBe('success')
        ->and($stored->paid_at)->not->toBeNull();
    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
});

it('marks the payment failed on a signed failure callback', function () {
    fakeAzamPay();
    $payment = TzPay::charge(['amount' => 5000, 'phone' => '0754123456']);

    $this->postJson('/tz-payments/webhooks/azampay', azampayCallback($payment->merchantReference, ['transactionstatus' => 'failure']))->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed);
});

it('rejects a callback with a forged signature', function () {
    fakeAzamPay();
    $payment = TzPay::charge(['amount' => 5000, 'phone' => '0754123456']);
    $callback = azampayCallback($payment->merchantReference);
    $callback['transactionstatus'] = 'success';
    $callback['utilityref'] = $payment->merchantReference;
    $callback['signature'] = base64_encode(random_bytes(256));

    $this->postJson('/tz-payments/webhooks/azampay', $callback)->assertForbidden();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('rejects a signed callback whose status was changed after signing', function () {
    fakeAzamPay();
    $payment = TzPay::charge(['amount' => 5000, 'phone' => '0754123456']);
    $callback = azampayCallback($payment->merchantReference, ['transactionstatus' => 'failure']);
    $callback['transactionstatus'] = 'success';

    $this->postJson('/tz-payments/webhooks/azampay', $callback)->assertForbidden();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('re-fetches the public key once before rejecting, in case AzamPay rotated it', function () {
    fakeAzamPay();
    $payment = TzPay::charge(['amount' => 5000, 'phone' => '0754123456']);
    cache()->put('tz-payments:azampay:sandbox:'.sha1('test-client-id').':public-key', 'stale-key', 3600);

    $this->postJson('/tz-payments/webhooks/azampay', azampayCallback($payment->merchantReference))->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Succeeded);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/azampay/v1/public-key'));
});

it('rejects an unsigned callback when no callback token is configured', function () {
    fakeAzamPay();
    $payment = TzPay::charge(['amount' => 5000, 'phone' => '0754123456']);

    $this->postJson('/tz-payments/webhooks/azampay', azampayCallback($payment->merchantReference, sign: false))->assertForbidden();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('accepts an unsigned callback with the right callback token', function () {
    config(['tz-payments.gateways.azampay.callback_token' => 'url-secret']);
    fakeAzamPay();
    $payment = TzPay::charge(['amount' => 5000, 'phone' => '0754123456']);

    $this->postJson('/tz-payments/webhooks/azampay?token=url-secret', azampayCallback($payment->merchantReference, sign: false))->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Succeeded);
});

it('rejects a callback with the wrong callback token, even if signed', function () {
    config(['tz-payments.gateways.azampay.callback_token' => 'url-secret']);
    fakeAzamPay();
    $payment = TzPay::charge(['amount' => 5000, 'phone' => '0754123456']);

    $this->postJson('/tz-payments/webhooks/azampay?token=wrong', azampayCallback($payment->merchantReference))->assertForbidden();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('rejects a signed callback whose amount does not match the payment', function () {
    fakeAzamPay();
    $payment = TzPay::charge(['amount' => 5000, 'phone' => '0754123456']);

    $this->postJson('/tz-payments/webhooks/azampay', azampayCallback($payment->merchantReference, ['amount' => '100']))->assertForbidden();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('keeps AzamPay passwords out of the webhook log', function () {
    fakeAzamPay();
    $payment = TzPay::charge(['amount' => 5000, 'phone' => '0754123456']);

    $this->postJson('/tz-payments/webhooks/azampay', azampayCallback($payment->merchantReference));

    $payload = WebhookCall::sole()->payload;
    expect($payload['password'])->toBe('[redacted]')
        ->and($payload['user'])->toBe('[redacted]');
});

it('answers 503 so AzamPay retries when the public key cannot be fetched', function () {
    Http::fake([
        '*/AppRegistration/GenerateToken' => Http::response(['data' => ['accessToken' => 't', 'expire' => now()->addHour()->toIso8601String()], 'success' => true]),
        '*/azampay/mno/checkout' => Http::response(['transactionId' => 'AZ-TX-1', 'success' => true]),
        '*/azampay/v1/public-key*' => fn () => throw new ConnectionException('down'),
    ]);
    $payment = TzPay::charge(['amount' => 5000, 'phone' => '0754123456']);

    $this->postJson('/tz-payments/webhooks/azampay', azampayCallback($payment->merchantReference))->assertStatus(503);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});
