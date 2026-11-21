<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use JonasJohn\TzPayments\Data\PaymentRequest;
use JonasJohn\TzPayments\Enums\Capability;
use JonasJohn\TzPayments\Enums\Operator;
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Events\PaymentFailed;
use JonasJohn\TzPayments\Events\PaymentInitiated;
use JonasJohn\TzPayments\Events\PaymentSucceeded;
use JonasJohn\TzPayments\Exceptions\DuplicatePaymentException;
use JonasJohn\TzPayments\Exceptions\GatewayException;
use JonasJohn\TzPayments\Exceptions\UnsupportedOperationException;
use JonasJohn\TzPayments\Facades\TzPay;
use JonasJohn\TzPayments\Models\Payment;

beforeEach(function () {
    $this->gateway = TzPay::fake();
});

it('stores a pending payment and returns it', function () {
    Event::fake([PaymentInitiated::class]);

    $response = TzPay::charge(['amount' => 5000, 'phone' => '0754123456', 'reference' => 'ORDER-1001']);

    expect($response->status)->toBe(PaymentStatus::Pending)
        ->and($response->gateway)->toBe('clickpesa')
        ->and($response->reference)->toBe('ORDER-1001')
        ->and($response->merchantReference)->toMatch('/^[A-Z0-9]{20}$/')
        ->and($response->amount)->toBe(5000)
        ->and($response->gatewayReference)->toBe('FAKE-'.$response->merchantReference);

    $payment = Payment::sole();
    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->phone)->toBe('255754123456')
        ->and($payment->operator)->toBe(Operator::Vodacom)
        ->and($payment->currency)->toBe('TZS');

    TzPay::assertCharged(fn (PaymentRequest $r) => $r->amount === 5000 && $r->reference === 'ORDER-1001');
    Event::assertDispatched(PaymentInitiated::class);
});

it('uses the gateway named in the call', function () {
    $response = TzPay::charge(['amount' => 1000, 'phone' => '0754123456'], 'azampay');

    expect($response->gateway)->toBe('azampay')
        ->and(Payment::sole()->gateway)->toBe('azampay');
});

it('refuses to charge a reference that is already pending', function () {
    TzPay::charge(['amount' => 1000, 'phone' => '0754123456', 'reference' => 'ORDER-1']);

    expect(fn () => TzPay::charge(['amount' => 1000, 'phone' => '0754123456', 'reference' => 'ORDER-1']))
        ->toThrow(DuplicatePaymentException::class);

    TzPay::assertChargedTimes(1);
});

it('refuses to charge a reference that already succeeded', function () {
    $this->gateway->chargeWillReturn(PaymentStatus::Succeeded);
    TzPay::charge(['amount' => 1000, 'phone' => '0754123456', 'reference' => 'ORDER-1']);

    expect(fn () => TzPay::charge(['amount' => 1000, 'phone' => '0754123456', 'reference' => 'ORDER-1']))
        ->toThrow(DuplicatePaymentException::class);
});

it('allows a new attempt after a failed one', function () {
    TzPay::charge(['amount' => 1000, 'phone' => '0754123456', 'reference' => 'ORDER-1']);
    $this->gateway->setStatus('ORDER-1', PaymentStatus::Failed);
    TzPay::status('ORDER-1');

    $retry = TzPay::charge(['amount' => 1000, 'phone' => '0754123456', 'reference' => 'ORDER-1']);

    expect(Payment::count())->toBe(2)
        ->and(TzPay::status('ORDER-1')->merchantReference)->toBe($retry->merchantReference);
});

it('marks the payment failed when the gateway rejects it', function () {
    Event::fake([PaymentFailed::class, PaymentInitiated::class]);
    $this->gateway->chargeWillFail(GatewayException::requestFailed('fake', 422, ['error' => 'bad number']));

    expect(fn () => TzPay::charge(['amount' => 1000, 'phone' => '0754123456']))->toThrow(GatewayException::class);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed);
    Event::assertDispatchedTimes(PaymentFailed::class, 1);
    Event::assertNotDispatched(PaymentInitiated::class);
});

it('keeps the payment pending when the gateway times out, because it may still complete', function () {
    Event::fake([PaymentFailed::class]);
    $this->gateway->chargeWillFail(GatewayException::timeout('fake', new ConnectionException('timed out')));

    expect(fn () => TzPay::charge(['amount' => 1000, 'phone' => '0754123456']))->toThrow(GatewayException::class);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
    Event::assertNotDispatched(PaymentFailed::class);
});

it('fires initiated then succeeded when the gateway completes at once', function () {
    Event::fake([PaymentInitiated::class, PaymentSucceeded::class]);
    $this->gateway->chargeWillReturn(PaymentStatus::Succeeded);

    $response = TzPay::charge(['amount' => 1000, 'phone' => '0754123456']);

    expect($response->isSuccessful())->toBeTrue()
        ->and(Payment::sole()->paid_at)->not->toBeNull();
    Event::assertDispatchedTimes(PaymentInitiated::class, 1);
    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
});

it('returns the redirect URL for hosted checkouts', function () {
    $this->gateway->chargeWillRedirectTo('https://pay.example.test/checkout/123');

    $response = TzPay::charge(['amount' => 1000, 'customer_email' => 'buyer@example.com']);

    expect($response->requiresRedirect())->toBeTrue()
        ->and($response->redirectUrl)->toBe('https://pay.example.test/checkout/123');
});

it('refuses to charge through a gateway that cannot take payments', function () {
    $this->gateway->withoutCapability(Capability::UssdPush, Capability::HostedCheckout);

    expect(fn () => TzPay::charge(['amount' => 1000, 'phone' => '0754123456']))
        ->toThrow(UnsupportedOperationException::class);

    expect(Payment::count())->toBe(0);
});

it('records nothing when no charge is made', function () {
    TzPay::assertNothingCharged();
});
