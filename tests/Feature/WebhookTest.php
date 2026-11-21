<?php

use Illuminate\Support\Facades\Event;
use JonasJohn\TzPayments\Enums\Capability;
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Events\PaymentReversed;
use JonasJohn\TzPayments\Events\PaymentSucceeded;
use JonasJohn\TzPayments\Facades\TzPay;
use JonasJohn\TzPayments\Models\Payment;

beforeEach(function () {
    $this->gateway = TzPay::fake();
    $this->payment = TzPay::charge(['amount' => 1000, 'phone' => '0754123456', 'reference' => 'ORDER-1']);
});

function sendWebhook(string $merchantReference, string $status, string $gateway = 'clickpesa')
{
    return test()->postJson("/tz-payments/webhooks/{$gateway}", [
        'merchant_reference' => $merchantReference,
        'status' => $status,
    ]);
}

it('applies a verified webhook and fires the event once, even when sent twice', function () {
    Event::fake([PaymentSucceeded::class]);

    sendWebhook($this->payment->merchantReference, 'succeeded')->assertOk();
    sendWebhook($this->payment->merchantReference, 'succeeded')->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Succeeded);
    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
});

it('acknowledges and ignores a webhook for an unknown payment', function () {
    sendWebhook('UNKNOWN', 'succeeded')->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('trusts the status API, not the claim, when the webhook is unverified', function () {
    $this->gateway->withoutWebhookVerification();

    sendWebhook($this->payment->merchantReference, 'succeeded')->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending)
        ->and($this->gateway->statusChecks())->toBe(1);
});

it('applies an unverified webhook once the status API confirms it', function () {
    $this->gateway->withoutWebhookVerification()->setStatus('ORDER-1', PaymentStatus::Succeeded);

    sendWebhook($this->payment->merchantReference, 'succeeded')->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Succeeded);
});

it('rejects an unverified webhook when there is no way to confirm it', function () {
    $this->gateway->withoutWebhookVerification()->withoutCapability(Capability::StatusLookup);

    sendWebhook($this->payment->merchantReference, 'succeeded')->assertForbidden();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('never lets a later webhook undo a succeeded payment', function () {
    sendWebhook($this->payment->merchantReference, 'succeeded');
    sendWebhook($this->payment->merchantReference, 'failed')->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Succeeded);
});

it('records a reversal after success', function () {
    Event::fake([PaymentReversed::class]);

    sendWebhook($this->payment->merchantReference, 'succeeded');
    sendWebhook($this->payment->merchantReference, 'reversed');

    expect(Payment::sole()->status)->toBe(PaymentStatus::Reversed);
    Event::assertDispatchedTimes(PaymentReversed::class, 1);
});

it('only matches payments of the gateway the webhook came in on', function () {
    sendWebhook($this->payment->merchantReference, 'succeeded', 'azampay')->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('rejects an unreadable webhook with 400', function () {
    $this->postJson('/tz-payments/webhooks/clickpesa', ['foo' => 'bar'])->assertStatus(400);
});
