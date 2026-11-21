<?php

use JonasJohn\TzPayments\Events\PaymentSucceeded;
use JonasJohn\TzPayments\Facades\TzPay;
use JonasJohn\TzPayments\Models\Payment;
use JonasJohn\TzPayments\Testing\FakeGateway;

it('registers a custom gateway with extend()', function () {
    $gateway = new FakeGateway;
    TzPay::extend('selcom', fn ($app) => $gateway);

    TzPay::charge(['amount' => 1000, 'phone' => '0754123456'], 'selcom');

    $gateway->assertChargedTimes(1);
    expect(Payment::sole()->gateway)->toBe('selcom')
        ->and(TzPay::hasGateway('selcom'))->toBeTrue();
});

it('answers 404 for a webhook to an unknown gateway', function () {
    $this->postJson('/tz-payments/webhooks/nope', [])->assertNotFound();
});

it('registers the webhook route under the configured prefix', function () {
    expect(route('tz-payments.webhook', 'azampay', false))->toBe('/tz-payments/webhooks/azampay');
});

it('keeps queued events serialisable', function () {
    TzPay::fake();
    TzPay::charge(['amount' => 1000, 'phone' => '0754123456']);

    $event = unserialize(serialize(new PaymentSucceeded(Payment::sole())));

    expect($event->payment->amount)->toBe(1000);
});
