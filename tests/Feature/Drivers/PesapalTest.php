<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JonasJohn\TzPayments\Data\PaymentResponse;
use JonasJohn\TzPayments\Drivers\PesapalGateway;
use JonasJohn\TzPayments\Enums\Capability;
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Events\PaymentSucceeded;
use JonasJohn\TzPayments\Exceptions\GatewayException;
use JonasJohn\TzPayments\Exceptions\InvalidRequestException;
use JonasJohn\TzPayments\Facades\TzPay;
use JonasJohn\TzPayments\Models\Payment;

/** A GetTransactionStatus body shaped like Pesapal's documented response. */
function pesapalStatusBody(string $merchantReference, int $code, string $description, int|float $amount = 2000, string $currency = 'TZS'): array
{
    return [
        'payment_method' => 'Mpesa',
        'amount' => $amount,
        'created_date' => '2026-11-21T10:00:00.000',
        'confirmation_code' => 'CONF123',
        'payment_status_description' => $description,
        'description' => 'Payment processed',
        'message' => 'Request processed successfully',
        'payment_account' => '2557****3456',
        'call_back_url' => 'https://shop.test/return',
        'status_code' => $code,
        'merchant_reference' => $merchantReference,
        'payment_status_code' => '',
        'currency' => $currency,
        'error' => ['error_type' => null, 'code' => null, 'message' => null, 'call_back_url' => null],
        'status' => '200',
    ];
}

/**
 * @param  array<string, mixed>|Closure|null  $status
 */
function fakePesapal(array|Closure|null $status = null, array $order = [], int $orderStatus = 200): void
{
    Http::fake([
        '*/Auth/RequestToken' => Http::response([
            'token' => 'pesapal-test-token',
            'expiryDate' => now()->addMinutes(5)->toIso8601String(),
            'error' => null,
            'status' => '200',
            'message' => 'Request processed successfully',
        ]),
        '*/Transactions/SubmitOrderRequest' => Http::response($order ?: [
            'order_tracking_id' => 'TRK-1',
            'merchant_reference' => 'set-by-test',
            'redirect_url' => 'https://cybqa.pesapal.com/pesapaliframe/PesapalIframe3/Index?OrderTrackingId=TRK-1',
            'error' => null,
            'status' => '200',
        ], $orderStatus),
        '*/Transactions/GetTransactionStatus*' => $status instanceof Closure ? $status : Http::response($status ?? []),
        '*/URLSetup/RegisterIPN' => Http::response([
            'url' => 'https://shop.test/tz-payments/webhooks/pesapal',
            'created_date' => '2026-11-21T10:00:00.000',
            'ipn_id' => 'e32182ca-0983-4fa0-91bc-c3bb813ba750',
            'notification_type' => 1,
            'ipn_notification_type_description' => 'POST',
            'ipn_status' => 1,
            'ipn_status_decription' => 'Active',
            'error' => null,
            'status' => '200',
        ]),
        '*/URLSetup/GetIpnList' => Http::response([
            ['url' => 'https://shop.test/tz-payments/webhooks/pesapal', 'created_date' => '2026-11-21', 'ipn_id' => 'IPN-1', 'error' => null, 'status' => '200'],
        ]),
    ]);
}

function pesapalCharge(array $overrides = []): PaymentResponse
{
    return TzPay::charge(array_merge([
        'amount' => 2000,
        'phone' => '0754123456',
        'customer_email' => 'buyer@example.com',
        'customer_name' => 'Asha Juma Mrisho',
        'reference' => 'ORDER-1',
        'description' => 'Order 1',
        'callback_url' => 'https://shop.test/return',
    ], $overrides));
}

beforeEach(function () {
    config([
        'tz-payments.default' => 'pesapal',
        'tz-payments.gateways.pesapal' => [
            'environment' => 'sandbox',
            'consumer_key' => 'test-consumer-key',
            'consumer_secret' => 'super-secret-value',
            'ipn_id' => 'IPN-1',
        ],
    ]);
});

// Charging

it('submits an order and returns the hosted checkout URL', function () {
    fakePesapal();

    $response = pesapalCharge();

    expect($response->status)->toBe(PaymentStatus::Pending)
        ->and($response->requiresRedirect())->toBeTrue()
        ->and($response->redirectUrl)->toContain('OrderTrackingId=TRK-1')
        ->and($response->gatewayReference)->toBe('TRK-1');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken'
        && $r['consumer_key'] === 'test-consumer-key' && $r['consumer_secret'] === 'super-secret-value');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://cybqa.pesapal.com/pesapalv3/api/Transactions/SubmitOrderRequest'
        && $r->hasHeader('Authorization', 'Bearer pesapal-test-token')
        && $r['id'] === $response->merchantReference
        && $r['currency'] === 'TZS'
        && $r['amount'] === 2000
        && $r['description'] === 'Order 1'
        && $r['callback_url'] === 'https://shop.test/return'
        && $r['notification_id'] === 'IPN-1'
        && $r['billing_address'] === [
            'phone_number' => '0754123456',
            'email_address' => 'buyer@example.com',
            'country_code' => 'TZ',
            'first_name' => 'Asha',
            'last_name' => 'Juma Mrisho',
        ]);
});

it('sends USD amounts with cents', function () {
    fakePesapal();

    pesapalCharge(['amount' => 1050, 'currency' => 'USD']);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/SubmitOrderRequest')
        && $r['amount'] === 10.5 && $r['currency'] === 'USD');
});

it('works with only an email address', function () {
    fakePesapal();

    pesapalCharge(['phone' => null]);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/SubmitOrderRequest')
        && ! isset($r['billing_address']['phone_number']) && $r['billing_address']['email_address'] === 'buyer@example.com');
});

it('uses the configured callback URL and a default description', function () {
    config(['tz-payments.gateways.pesapal.callback_url' => 'https://shop.test/default-return']);
    fakePesapal();

    pesapalCharge(['callback_url' => null, 'description' => null]);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/SubmitOrderRequest')
        && $r['callback_url'] === 'https://shop.test/default-return'
        && $r['description'] === 'Payment ORDER-1');
});

it('passes Pesapal options through', function () {
    fakePesapal();

    pesapalCharge(['options' => ['cancellation_url' => 'https://shop.test/cancel', 'redirect_mode' => 'PARENT_WINDOW']]);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/SubmitOrderRequest')
        && $r['cancellation_url'] === 'https://shop.test/cancel' && $r['redirect_mode'] === 'PARENT_WINDOW');
});

it('reuses the cached token', function () {
    fakePesapal();

    pesapalCharge(['reference' => 'A']);
    pesapalCharge(['reference' => 'B']);

    Http::assertSentCount(3);
});

it('uses the live URL in the live environment', function () {
    config(['tz-payments.gateways.pesapal.environment' => 'live']);
    fakePesapal();

    pesapalCharge();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://pay.pesapal.com/v3/api/Transactions/SubmitOrderRequest');
});

it('rejects requests Pesapal cannot take before calling it', function (array $overrides, string $missing) {
    Http::fake();

    expect(fn () => pesapalCharge($overrides))->toThrow(InvalidRequestException::class, $missing);

    Http::assertNothingSent();
})->with([
    'no phone or email' => [['phone' => null, 'customer_email' => null], 'phone or customer_email'],
    'no callback URL' => [['callback_url' => null], 'callback_url'],
]);

it('explains a missing IPN id', function () {
    config(['tz-payments.gateways.pesapal.ipn_id' => null]);
    Http::fake();

    expect(fn () => pesapalCharge())->toThrow(InvalidRequestException::class, 'ipn_id');
    Http::assertNothingSent();
});

it('marks the payment failed when Pesapal returns an error', function () {
    fakePesapal(order: [
        'order_tracking_id' => null,
        'redirect_url' => null,
        'error' => ['error_type' => 'api_error', 'code' => 'invalid_currency', 'message' => 'Invalid currency'],
        'status' => '500',
    ]);

    expect(fn () => pesapalCharge())->toThrow(GatewayException::class, 'Invalid currency');

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed);
});

it('keeps the payment pending when Pesapal fails after receiving the order', function () {
    fakePesapal(order: ['message' => 'Server error'], orderStatus: 502);

    expect(fn () => pesapalCharge())->toThrow(GatewayException::class);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('never puts credentials in error messages', function () {
    Http::fake(['*/Auth/RequestToken' => Http::response(['token' => null, 'error' => ['code' => 'invalid_consumer_key_or_secret_provided', 'message' => ''], 'status' => '500'])]);

    try {
        pesapalCharge();
    } catch (GatewayException $e) {
        expect($e->getMessage())->not->toContain('super-secret-value')
            ->and(json_encode($e->response))->not->toContain('super-secret-value');
    }

    expect($e ?? null)->toBeInstanceOf(GatewayException::class);
});

it('declares hosted checkout and status lookup', function () {
    $gateway = TzPay::gateway('pesapal');

    expect($gateway)->toBeInstanceOf(PesapalGateway::class)
        ->and($gateway->supports(Capability::HostedCheckout))->toBeTrue()
        ->and($gateway->supports(Capability::StatusLookup))->toBeTrue()
        ->and($gateway->supports(Capability::UssdPush))->toBeFalse();
});

// Status

it('maps every Pesapal status code', function (int $code, string $description, PaymentStatus $expected) {
    $merchantReference = '';
    fakePesapal(function (Request $r) use (&$merchantReference, $code, $description) {
        return Http::response(pesapalStatusBody($merchantReference, $code, $description));
    });
    $merchantReference = pesapalCharge()->merchantReference;

    expect(TzPay::status('ORDER-1')->status)->toBe($expected)
        ->and(Payment::sole()->gateway_status)->toBe($description);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'GetTransactionStatus?orderTrackingId=TRK-1'));
})->with([
    'INVALID is still pending' => [0, 'INVALID', PaymentStatus::Pending],
    'COMPLETED' => [1, 'COMPLETED', PaymentStatus::Succeeded],
    'FAILED' => [2, 'FAILED', PaymentStatus::Failed],
    'REVERSED' => [3, 'REVERSED', PaymentStatus::Reversed],
]);

it('refuses a status answer about a different order', function () {
    fakePesapal(fn () => Http::response(pesapalStatusBody('SOMEONEELSE', 1, 'COMPLETED')));
    pesapalCharge();

    expect(fn () => TzPay::status('ORDER-1'))->toThrow(GatewayException::class, 'does not match');

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('refuses a status answer with a different amount', function () {
    $merchantReference = '';
    fakePesapal(function () use (&$merchantReference) {
        return Http::response(pesapalStatusBody($merchantReference, 1, 'COMPLETED', amount: 10));
    });
    $merchantReference = pesapalCharge()->merchantReference;

    expect(fn () => TzPay::status('ORDER-1'))->toThrow(GatewayException::class, 'amount');

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

// IPN

it('confirms an IPN with Pesapal and echoes the notification back', function (string $method) {
    Event::fake([PaymentSucceeded::class]);
    $merchantReference = '';
    fakePesapal(function () use (&$merchantReference) {
        return Http::response(pesapalStatusBody($merchantReference, 1, 'COMPLETED'));
    });
    $merchantReference = pesapalCharge()->merchantReference;

    $ipn = [
        'OrderTrackingId' => 'TRK-1',
        'OrderMerchantReference' => $merchantReference,
        'OrderNotificationType' => 'IPNCHANGE',
    ];

    $response = $method === 'GET'
        ? $this->getJson('/tz-payments/webhooks/pesapal?'.http_build_query($ipn))
        : $this->postJson('/tz-payments/webhooks/pesapal', $ipn);

    $response->assertOk()->assertExactJson([
        'orderNotificationType' => 'IPNCHANGE',
        'orderTrackingId' => 'TRK-1',
        'orderMerchantReference' => $merchantReference,
        'status' => 200,
    ]);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Succeeded);
    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
})->with(['POST', 'GET']);

it('fires the success event once when Pesapal repeats the IPN', function () {
    Event::fake([PaymentSucceeded::class]);
    $merchantReference = '';
    fakePesapal(function () use (&$merchantReference) {
        return Http::response(pesapalStatusBody($merchantReference, 1, 'COMPLETED'));
    });
    $merchantReference = pesapalCharge()->merchantReference;

    $ipn = ['OrderTrackingId' => 'TRK-1', 'OrderMerchantReference' => $merchantReference, 'OrderNotificationType' => 'IPNCHANGE'];
    $this->postJson('/tz-payments/webhooks/pesapal', $ipn)->assertOk();
    $this->postJson('/tz-payments/webhooks/pesapal', $ipn)->assertOk();

    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
});

it('keeps the payment pending while Pesapal still reports INVALID', function () {
    $merchantReference = '';
    fakePesapal(function () use (&$merchantReference) {
        return Http::response(pesapalStatusBody($merchantReference, 0, 'INVALID'));
    });
    $merchantReference = pesapalCharge()->merchantReference;

    $this->postJson('/tz-payments/webhooks/pesapal', ['OrderTrackingId' => 'TRK-1', 'OrderMerchantReference' => $merchantReference])->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('looks up the stored tracking id, not the one in the IPN', function () {
    $merchantReference = '';
    fakePesapal(function () use (&$merchantReference) {
        return Http::response(pesapalStatusBody($merchantReference, 0, 'INVALID'));
    });
    $merchantReference = pesapalCharge()->merchantReference;

    $this->postJson('/tz-payments/webhooks/pesapal', ['OrderTrackingId' => 'SOMEONE-ELSES-PAID-ORDER', 'OrderMerchantReference' => $merchantReference]);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'orderTrackingId=TRK-1'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'SOMEONE-ELSES-PAID-ORDER'));
});

it('rejects an IPN that points our order at someone else\'s paid order', function () {
    // The order request timed out, so no tracking id was stored...
    Http::fake([
        '*/Auth/RequestToken' => Http::response(['token' => 't', 'expiryDate' => now()->addMinutes(5)->toIso8601String(), 'error' => null, 'status' => '200']),
        '*/Transactions/SubmitOrderRequest' => fn () => throw new ConnectionException('timed out'),
        // ...and the attacker's tracking id belongs to a different, paid order.
        '*/Transactions/GetTransactionStatus*' => Http::response(pesapalStatusBody('ATTACKERSORDER', 1, 'COMPLETED')),
    ]);

    expect(fn () => pesapalCharge())->toThrow(GatewayException::class);
    $payment = Payment::sole();

    $this->postJson('/tz-payments/webhooks/pesapal', [
        'OrderTrackingId' => 'ATTACKERS-PAID-TRACKING-ID',
        'OrderMerchantReference' => $payment->merchant_reference,
    ])->assertForbidden();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('rejects an unreadable IPN', function () {
    $this->postJson('/tz-payments/webhooks/pesapal', ['foo' => 'bar'])->assertStatus(400);
});

it('answers 503 so Pesapal retries when the status cannot be checked', function () {
    fakePesapal(fn () => throw new ConnectionException('down'));
    $merchantReference = pesapalCharge()->merchantReference;

    $this->postJson('/tz-payments/webhooks/pesapal', ['OrderTrackingId' => 'TRK-1', 'OrderMerchantReference' => $merchantReference])
        ->assertStatus(503);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

// IPN registration command

it('registers the IPN URL and prints the id for .env', function () {
    fakePesapal();

    $this->artisan('tz-payments:pesapal-register-ipn')
        ->expectsOutputToContain('PESAPAL_IPN_ID=e32182ca-0983-4fa0-91bc-c3bb813ba750')
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/URLSetup/RegisterIPN')
        && $r['url'] === route('tz-payments.webhook', 'pesapal')
        && $r['ipn_notification_type'] === 'POST');
});

it('registers a custom URL with GET', function () {
    fakePesapal();

    $this->artisan('tz-payments:pesapal-register-ipn', ['url' => 'https://shop.test/ipn', '--method' => 'get'])->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/URLSetup/RegisterIPN')
        && $r['url'] === 'https://shop.test/ipn' && $r['ipn_notification_type'] === 'GET');
});

it('lists registered IPN URLs', function () {
    fakePesapal();

    $this->artisan('tz-payments:pesapal-register-ipn', ['--list' => true])
        ->expectsOutputToContain('IPN-1')
        ->assertSuccessful();
});

it('refuses an invalid method', function () {
    Http::fake();

    $this->artisan('tz-payments:pesapal-register-ipn', ['--method' => 'PUT'])->assertFailed();

    Http::assertNothingSent();
});
