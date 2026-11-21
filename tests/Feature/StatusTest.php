<?php

use Illuminate\Support\Facades\Event;
use JonasJohn\TzPayments\Enums\Capability;
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Events\PaymentSucceeded;
use JonasJohn\TzPayments\Exceptions\PaymentNotFoundException;
use JonasJohn\TzPayments\Facades\TzPay;
use JonasJohn\TzPayments\Models\Payment;

beforeEach(function () {
    $this->gateway = TzPay::fake();
    TzPay::charge(['amount' => 1000, 'phone' => '0754123456', 'reference' => 'ORDER-1']);
});

it('throws for an unknown reference', function () {
    TzPay::status('NOPE');
})->throws(PaymentNotFoundException::class);

it('asks the gateway while the payment is pending and stores the answer', function () {
    Event::fake([PaymentSucceeded::class]);
    $this->gateway->setStatus('ORDER-1', PaymentStatus::Succeeded);

    $response = TzPay::status('ORDER-1');

    expect($response->status)->toBe(PaymentStatus::Succeeded)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Succeeded);
    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
});

it('answers a final payment from the database without calling the gateway', function () {
    $this->gateway->setStatus('ORDER-1', PaymentStatus::Failed);
    TzPay::status('ORDER-1');

    $this->gateway->setStatus('ORDER-1', PaymentStatus::Succeeded);

    expect(TzPay::status('ORDER-1')->status)->toBe(PaymentStatus::Failed)
        ->and($this->gateway->statusChecks())->toBe(1);
});

it('returns the stored status when the gateway has no status lookup', function () {
    $this->gateway->withoutCapability(Capability::StatusLookup);

    expect(TzPay::status('ORDER-1')->status)->toBe(PaymentStatus::Pending)
        ->and($this->gateway->statusChecks())->toBe(0);
});
