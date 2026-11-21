<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JonasJohn\TzPayments\Drivers\ClickPesaChecksum;
use JonasJohn\TzPayments\Drivers\ClickPesaGateway;
use JonasJohn\TzPayments\Enums\Capability;
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Events\PaymentSucceeded;
use JonasJohn\TzPayments\Exceptions\GatewayException;
use JonasJohn\TzPayments\Exceptions\InvalidRequestException;
use JonasJohn\TzPayments\Facades\TzPay;
use JonasJohn\TzPayments\Models\Payment;

/** One entry of ClickPesa's "query payment status" array. */
function clickpesaPayment(string $orderReference, string $status, array $overrides = []): array
{
    return array_merge([
        'id' => 'CP-PAY-1',
        'status' => $status,
        'paymentReference' => 'PAYREF1',
        'paymentPhoneNumber' => '255754123456',
        'orderReference' => $orderReference,
        'collectedAmount' => '2000',
        'collectedCurrency' => 'TZS',
        'message' => 'Payment '.strtolower($status),
        'updatedAt' => '2026-11-21T10:00:00.000Z',
        'createdAt' => '2026-11-21T09:59:00.000Z',
        'customer' => ['customerName' => 'Asha Juma', 'customerPhoneNumber' => '255754123456', 'customerEmail' => null],
        'clientId' => 'test-client-id',
    ], $overrides);
}

/** A "PAYMENT RECEIVED" webhook shaped like ClickPesa's documented example. */
function clickpesaWebhook(string $orderReference, string $event = 'PAYMENT RECEIVED', string $status = 'SUCCESS', ?string $checksumKey = null): array
{
    $webhook = [
        'event' => $event,
        'data' => [
            'id' => 'CP-PAY-1',
            'status' => $status,
            'paymentReference' => 'PAYREF1',
            'paymentPhoneNumber' => '255754123456',
            'orderReference' => $orderReference,
            'collectedAmount' => '2000',
            'collectedCurrency' => 'TZS',
            'message' => 'Payment received',
            'channel' => 'TIGO-PESA',
            'updatedAt' => '2026-11-21T10:00:00.000Z',
            'createdAt' => '2026-11-21T09:59:00.000Z',
            'customer' => ['customerName' => 'Asha Juma', 'customerEmail' => '', 'customerPhoneNumber' => '255754123456'],
        ],
    ];

    if ($checksumKey !== null) {
        $webhook['checksum'] = ClickPesaChecksum::sign($webhook, $checksumKey);
        $webhook['checksumMethod'] = 'canonical';
    }

    return $webhook;
}

/**
 * @param  array<string, mixed>|Closure  $status
 */
function fakeClickPesa(array $ussd = [], int $ussdStatus = 200, array|Closure $status = [], string $token = 'Bearer cp-test-token'): void
{
    Http::fake([
        '*/third-parties/generate-token' => Http::response(['success' => true, 'token' => $token]),
        '*/payments/initiate-ussd-push-request' => Http::response($ussd ?: [
            'id' => 'CP-PAY-1',
            'status' => 'PROCESSING',
            'channel' => 'TIGO-PESA',
            'orderReference' => 'set-by-test',
            'collectedAmount' => '2000',
            'collectedCurrency' => 'TZS',
            'createdAt' => '2026-11-21T09:59:00.000Z',
            'clientId' => 'test-client-id',
        ], $ussdStatus),
        '*/payments/initiate-card-payment' => Http::response(['cardPaymentLink' => 'https://pay.clickpesa.test/card/abc', 'clientId' => 'test-client-id']),
        '*/checkout-link/generate-checkout-url' => Http::response(['checkoutLink' => 'https://checkout.clickpesa.test/xyz', 'clientId' => 'test-client-id']),
        // Status lookups only (GET). This pattern also matches the POST endpoints above.
        '*/third-parties/payments/*' => function (Request $request) use ($status) {
            if ($request->method() !== 'GET') {
                return null;
            }

            return $status instanceof Closure ? $status($request) : Http::response($status);
        },
    ]);
}

function sentTo(string $path): Closure
{
    return fn (Request $r) => str_ends_with($r->url(), $path);
}

beforeEach(function () {
    config([
        'tz-payments.default' => 'clickpesa',
        'tz-payments.gateways.clickpesa' => [
            'client_id' => 'test-client-id',
            'api_key' => 'super-secret-value',
        ],
    ]);
});

// USSD push

it('sends a USSD push in the format ClickPesa expects', function () {
    fakeClickPesa();

    $response = TzPay::charge(['amount' => 2000, 'phone' => '0754123456', 'reference' => 'ORDER-1']);

    expect($response->status)->toBe(PaymentStatus::Pending)
        ->and($response->gatewayReference)->toBe('CP-PAY-1')
        ->and($response->gatewayStatus)->toBe('PROCESSING')
        ->and($response->requiresRedirect())->toBeFalse();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.clickpesa.com/third-parties/generate-token'
        && $r->hasHeader('client-id', 'test-client-id') && $r->hasHeader('api-key', 'super-secret-value'));

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.clickpesa.com/third-parties/payments/initiate-ussd-push-request'
        && $r->hasHeader('Authorization', 'Bearer cp-test-token')
        && $r['amount'] === '2000'
        && $r['currency'] === 'TZS'
        && $r['orderReference'] === $response->merchantReference
        && $r['phoneNumber'] === '255754123456'
        && ! isset($r['checksum']));
});

it('adds the Bearer prefix if the token comes without it', function () {
    fakeClickPesa(token: 'raw-token');

    TzPay::charge(['amount' => 2000, 'phone' => '0754123456']);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/initiate-ussd-push-request') && $r->hasHeader('Authorization', 'Bearer raw-token'));
});

it('marks the payment failed when ClickPesa fails the push at once', function () {
    fakeClickPesa(['id' => 'CP-PAY-1', 'status' => 'FAILED', 'orderReference' => 'x']);

    expect(TzPay::charge(['amount' => 2000, 'phone' => '0754123456'])->status)->toBe(PaymentStatus::Failed)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Failed);
});

it('signs requests when a checksum key is configured', function () {
    config(['tz-payments.gateways.clickpesa.checksum_key' => 'checksum-secret']);
    fakeClickPesa();

    TzPay::charge(['amount' => 2000, 'phone' => '0754123456']);

    Http::assertSent(function (Request $r) {
        if (! str_ends_with($r->url(), '/initiate-ussd-push-request')) {
            return false;
        }

        $body = $r->data();

        return $body['checksum'] === ClickPesaChecksum::sign($body, 'checksum-secret');
    });
});

it('reuses the cached token, because ClickPesa allows only one new token every 3 minutes', function () {
    fakeClickPesa();

    TzPay::charge(['amount' => 2000, 'phone' => '0754123456']);
    TzPay::charge(['amount' => 2000, 'phone' => '0754123456']);

    Http::assertSentCount(3);
});

// Card and checkout

it('creates a card payment link for USD', function () {
    fakeClickPesa();

    $response = TzPay::charge([
        'amount' => 1050,
        'currency' => 'USD',
        'phone' => '0754123456',
        'customer_name' => 'Asha Juma',
        'customer_email' => 'asha@example.com',
        'options' => ['channel' => 'card'],
    ]);

    expect($response->requiresRedirect())->toBeTrue()
        ->and($response->redirectUrl)->toBe('https://pay.clickpesa.test/card/abc');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payments/initiate-card-payment')
        && $r['amount'] === '10.50'
        && $r['currency'] === 'USD'
        && $r['customer'] === ['fullName' => 'Asha Juma', 'email' => 'asha@example.com', 'phoneNumber' => '255754123456']);
});

it('refuses card payments it cannot make before calling ClickPesa', function (array $data) {
    Http::fake();

    expect(fn () => TzPay::charge(array_merge(['options' => ['channel' => 'card']], $data)))->toThrow(InvalidRequestException::class);

    Http::assertNothingSent();
})->with([
    'TZS' => [['amount' => 2000, 'currency' => 'TZS', 'phone' => '0754123456', 'customer_name' => 'A', 'customer_email' => 'a@example.com']],
    'no name' => [['amount' => 1050, 'currency' => 'USD', 'phone' => '0754123456', 'customer_email' => 'a@example.com']],
    'no email' => [['amount' => 1050, 'currency' => 'USD', 'phone' => '0754123456', 'customer_name' => 'A']],
    'no phone' => [['amount' => 1050, 'currency' => 'USD', 'customer_name' => 'A', 'customer_email' => 'a@example.com']],
]);

it('uses the hosted checkout page when there is no phone number', function () {
    fakeClickPesa();

    $response = TzPay::charge(['amount' => 2000, 'customer_email' => 'asha@example.com', 'description' => 'Order 1']);

    expect($response->redirectUrl)->toBe('https://checkout.clickpesa.test/xyz');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout-link/generate-checkout-url')
        && $r['totalPrice'] === '2000'
        && $r['orderCurrency'] === 'TZS'
        && $r['orderReference'] === $response->merchantReference
        && $r['customerEmail'] === 'asha@example.com'
        && $r['description'] === 'Order 1');
    Http::assertNotSent(sentTo('/initiate-ussd-push-request'));
});

it('uses the hosted checkout page for USD with a phone number', function () {
    fakeClickPesa();

    TzPay::charge(['amount' => 2500, 'currency' => 'USD', 'phone' => '0754123456']);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/generate-checkout-url')
        && $r['totalPrice'] === '25.00' && $r['orderCurrency'] === 'USD' && $r['customerPhone'] === '255754123456');
});

it('lets the developer choose the checkout page even with a phone number', function () {
    fakeClickPesa();

    TzPay::charge(['amount' => 2000, 'phone' => '0754123456', 'options' => ['channel' => 'checkout']]);

    Http::assertSent(sentTo('/generate-checkout-url'));
    Http::assertNotSent(sentTo('/initiate-ussd-push-request'));
});

it('rejects what ClickPesa cannot take before calling it', function (array $data) {
    Http::fake();

    expect(fn () => TzPay::charge($data))->toThrow(InvalidRequestException::class);

    Http::assertNothingSent();
})->with([
    'unknown channel' => [['amount' => 2000, 'phone' => '0754123456', 'options' => ['channel' => 'bitcoin']]],
    'USSD in USD' => [['amount' => 2000, 'currency' => 'USD', 'phone' => '0754123456', 'options' => ['channel' => 'ussd']]],
    'USSD without phone' => [['amount' => 2000, 'options' => ['channel' => 'ussd']]],
    'checkout in KES' => [['amount' => 2000, 'currency' => 'KES']],
]);

// Failures

it('marks the payment failed when the order reference was already used', function () {
    fakeClickPesa(['message' => 'Order reference ABC already used: Create a different reference'], 409);

    expect(fn () => TzPay::charge(['amount' => 2000, 'phone' => '0754123456']))->toThrow(GatewayException::class, 'already used');

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed);
});

it('keeps the payment pending when ClickPesa fails after receiving the request', function () {
    fakeClickPesa(['message' => 'Internal error'], 500);

    expect(fn () => TzPay::charge(['amount' => 2000, 'phone' => '0754123456']))->toThrow(GatewayException::class);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('keeps the payment pending when the push times out', function () {
    Http::fake([
        '*/generate-token' => Http::response(['success' => true, 'token' => 'Bearer t']),
        '*/initiate-ussd-push-request' => fn () => throw new ConnectionException('timed out'),
    ]);

    expect(fn () => TzPay::charge(['amount' => 2000, 'phone' => '0754123456']))->toThrow(GatewayException::class);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('explains a token rate limit and starts no payment', function () {
    Http::fake([
        '*/generate-token' => Http::response(['message' => 'Too many requests', 'fields' => ['retryAfterSeconds' => 120]], 429, ['Retry-After' => '120']),
    ]);

    expect(fn () => TzPay::charge(['amount' => 2000, 'phone' => '0754123456']))
        ->toThrow(GatewayException::class, 'retry after 120 seconds');

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed);
    Http::assertNotSent(sentTo('/initiate-ussd-push-request'));
});

it('never puts credentials in error messages', function () {
    Http::fake(['*/generate-token' => Http::response(['message' => 'Invalid client details'], 403)]);

    try {
        TzPay::charge(['amount' => 2000, 'phone' => '0754123456']);
    } catch (GatewayException $e) {
        expect($e->getMessage())->not->toContain('super-secret-value')
            ->and(json_encode($e->response))->not->toContain('super-secret-value');
    }

    expect($e ?? null)->toBeInstanceOf(GatewayException::class);
});

it('declares USSD push, hosted checkout and status lookup', function () {
    $gateway = TzPay::gateway('clickpesa');

    expect($gateway)->toBeInstanceOf(ClickPesaGateway::class)
        ->and($gateway->supports(Capability::UssdPush))->toBeTrue()
        ->and($gateway->supports(Capability::HostedCheckout))->toBeTrue()
        ->and($gateway->supports(Capability::StatusLookup))->toBeTrue();
});

// Status

it('maps every ClickPesa status', function (string $clickpesa, PaymentStatus $expected) {
    $reference = '';
    fakeClickPesa(status: function () use (&$reference, $clickpesa) {
        return Http::response([clickpesaPayment($reference, $clickpesa)]);
    });
    $reference = TzPay::charge(['amount' => 2000, 'phone' => '0754123456', 'reference' => 'ORDER-1'])->merchantReference;

    expect(TzPay::status('ORDER-1')->status)->toBe($expected)
        ->and(Payment::sole()->gateway_status)->toBe($clickpesa);

    Http::assertSent(fn (Request $r) => $r->url() === "https://api.clickpesa.com/third-parties/payments/{$reference}");
})->with([
    ['SUCCESS', PaymentStatus::Succeeded],
    ['SETTLED', PaymentStatus::Succeeded],
    ['PROCESSING', PaymentStatus::Pending],
    ['PENDING', PaymentStatus::Pending],
    ['ON-HOLD', PaymentStatus::Pending],
    ['FAILED', PaymentStatus::Failed],
    ['REFUNDED', PaymentStatus::Reversed],
    ['REVERSED', PaymentStatus::Reversed],
]);

it('treats an unknown reference as not paid yet', function () {
    fakeClickPesa(status: fn () => Http::response(['message' => 'Invalid or missing payment: X'], 404));
    TzPay::charge(['amount' => 2000, 'customer_email' => 'a@example.com', 'reference' => 'ORDER-1']);

    expect(TzPay::status('ORDER-1')->status)->toBe(PaymentStatus::Pending);
});

it('counts a success when the customer failed once and then paid', function () {
    $reference = '';
    fakeClickPesa(status: function () use (&$reference) {
        return Http::response([
            clickpesaPayment($reference, 'FAILED', ['id' => 'A', 'updatedAt' => '2026-11-21T10:00:00Z']),
            clickpesaPayment($reference, 'SUCCESS', ['id' => 'B', 'updatedAt' => '2026-11-21T10:05:00Z']),
        ]);
    });
    $reference = TzPay::charge(['amount' => 2000, 'customer_email' => 'a@example.com', 'reference' => 'ORDER-1'])->merchantReference;

    expect(TzPay::status('ORDER-1')->status)->toBe(PaymentStatus::Succeeded);
});

it('refuses a status answer about a different order', function () {
    fakeClickPesa(status: fn () => Http::response([clickpesaPayment('SOMEONEELSE', 'SUCCESS')]));
    TzPay::charge(['amount' => 2000, 'phone' => '0754123456', 'reference' => 'ORDER-1']);

    expect(fn () => TzPay::status('ORDER-1'))->toThrow(GatewayException::class, 'does not match');

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

// Webhooks

it('confirms a webhook with ClickPesa when no checksum key is set', function () {
    Event::fake([PaymentSucceeded::class]);
    $reference = '';
    fakeClickPesa(status: function () use (&$reference) {
        return Http::response([clickpesaPayment($reference, 'SUCCESS')]);
    });
    $reference = TzPay::charge(['amount' => 2000, 'phone' => '0754123456'])->merchantReference;

    $this->postJson('/tz-payments/webhooks/clickpesa', clickpesaWebhook($reference))->assertOk();
    $this->postJson('/tz-payments/webhooks/clickpesa', clickpesaWebhook($reference))->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Succeeded);
    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
});

it('does not trust an unsigned success claim that ClickPesa does not confirm', function () {
    $reference = '';
    fakeClickPesa(status: function () use (&$reference) {
        return Http::response([clickpesaPayment($reference, 'PROCESSING')]);
    });
    $reference = TzPay::charge(['amount' => 2000, 'phone' => '0754123456'])->merchantReference;

    $this->postJson('/tz-payments/webhooks/clickpesa', clickpesaWebhook($reference))->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('applies a webhook with a valid checksum without asking ClickPesa', function () {
    config(['tz-payments.gateways.clickpesa.checksum_key' => 'checksum-secret']);
    fakeClickPesa();
    $reference = TzPay::charge(['amount' => 2000, 'phone' => '0754123456'])->merchantReference;

    $this->postJson('/tz-payments/webhooks/clickpesa', clickpesaWebhook($reference, checksumKey: 'checksum-secret'))->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Succeeded);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'GET');
});

it('applies a signed PAYMENT FAILED webhook', function () {
    config(['tz-payments.gateways.clickpesa.checksum_key' => 'checksum-secret']);
    fakeClickPesa();
    $reference = TzPay::charge(['amount' => 2000, 'phone' => '0754123456'])->merchantReference;

    $this->postJson('/tz-payments/webhooks/clickpesa', clickpesaWebhook($reference, 'PAYMENT FAILED', 'FAILED', 'checksum-secret'))->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed);
});

it('rejects a webhook with a wrong or missing checksum', function (?string $signWith) {
    config(['tz-payments.gateways.clickpesa.checksum_key' => 'checksum-secret']);
    fakeClickPesa();
    $reference = TzPay::charge(['amount' => 2000, 'phone' => '0754123456'])->merchantReference;

    $this->postJson('/tz-payments/webhooks/clickpesa', clickpesaWebhook($reference, checksumKey: $signWith))->assertForbidden();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
})->with(['wrong key' => 'attacker-key', 'missing' => null]);

it('rejects a signed webhook that was changed after signing', function () {
    config(['tz-payments.gateways.clickpesa.checksum_key' => 'checksum-secret']);
    fakeClickPesa();
    $reference = TzPay::charge(['amount' => 2000, 'phone' => '0754123456'])->merchantReference;
    $webhook = clickpesaWebhook($reference, 'PAYMENT FAILED', 'FAILED', 'checksum-secret');
    $webhook['event'] = 'PAYMENT RECEIVED';
    $webhook['data']['status'] = 'SUCCESS';

    $this->postJson('/tz-payments/webhooks/clickpesa', $webhook)->assertForbidden();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('ignores payout and deposit events', function () {
    fakeClickPesa();
    TzPay::charge(['amount' => 2000, 'phone' => '0754123456']);

    $this->postJson('/tz-payments/webhooks/clickpesa', ['event' => 'PAYOUT INITIATED', 'data' => ['orderReference' => 'PAYOUT1', 'status' => 'SUCCESS']])
        ->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('rejects an unreadable webhook', function () {
    $this->postJson('/tz-payments/webhooks/clickpesa', ['foo' => 'bar'])->assertStatus(400);
});
