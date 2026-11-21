<?php

use Illuminate\Support\Facades\Event;
use JonasJohn\TzPayments\Enums\Capability;
use JonasJohn\TzPayments\Events\WebhookHandled;
use JonasJohn\TzPayments\Facades\TzPay;
use JonasJohn\TzPayments\Models\Payment;
use JonasJohn\TzPayments\Models\WebhookCall;

beforeEach(function () {
    $this->gateway = TzPay::fake();
    $this->payment = TzPay::charge(['amount' => 1000, 'phone' => '0754123456']);
});

it('logs a processed webhook against its payment', function () {
    $this->postJson('/tz-payments/webhooks/clickpesa', [
        'merchant_reference' => $this->payment->merchantReference,
        'status' => 'succeeded',
    ])->assertOk();

    $call = WebhookCall::sole();
    expect($call->status)->toBe(WebhookCall::STATUS_PROCESSED)
        ->and($call->gateway)->toBe('clickpesa')
        ->and($call->payment_id)->toBe(Payment::sole()->id)
        ->and($call->payload['status'])->toBe('succeeded');
});

it('logs ignored and rejected webhooks too', function () {
    $this->postJson('/tz-payments/webhooks/clickpesa', ['merchant_reference' => 'UNKNOWN', 'status' => 'succeeded']);
    $this->postJson('/tz-payments/webhooks/clickpesa', ['nothing' => 'useful']);

    expect(WebhookCall::pluck('status')->all())
        ->toBe([WebhookCall::STATUS_IGNORED, WebhookCall::STATUS_REJECTED]);
});

it('hides secrets in the logged payload, at any depth', function () {
    $this->postJson('/tz-payments/webhooks/clickpesa', [
        'merchant_reference' => $this->payment->merchantReference,
        'status' => 'succeeded',
        'password' => 'hunter2',
        'nested' => ['API_KEY' => 'abc', 'keep' => 'me'],
    ]);

    $payload = WebhookCall::sole()->payload;
    expect($payload['password'])->toBe('[redacted]')
        ->and($payload['nested']['API_KEY'])->toBe('[redacted]')
        ->and($payload['nested']['keep'])->toBe('me');
});

it('can be switched off', function () {
    config(['tz-payments.webhooks.log' => false]);

    $this->postJson('/tz-payments/webhooks/clickpesa', [
        'merchant_reference' => $this->payment->merchantReference,
        'status' => 'succeeded',
    ])->assertOk();

    expect(WebhookCall::count())->toBe(0);
});

it('prunes calls older than the retention period', function () {
    $this->postJson('/tz-payments/webhooks/clickpesa', ['merchant_reference' => 'UNKNOWN']);
    WebhookCall::query()->update(['created_at' => now()->subDays(31)]);
    $this->postJson('/tz-payments/webhooks/clickpesa', ['merchant_reference' => 'UNKNOWN']);

    $this->artisan('model:prune', ['--model' => [WebhookCall::class]]);

    expect(WebhookCall::count())->toBe(1);
});

it('fires WebhookHandled for verified webhooks only', function () {
    Event::fake([WebhookHandled::class]);
    $this->gateway->withoutWebhookVerification()->withoutCapability(Capability::StatusLookup);

    $this->postJson('/tz-payments/webhooks/clickpesa', [
        'merchant_reference' => $this->payment->merchantReference,
        'status' => 'succeeded',
    ])->assertForbidden();

    Event::assertNotDispatched(WebhookHandled::class);
});

it('fires WebhookHandled with the gateway, payment and event', function () {
    Event::fake([WebhookHandled::class]);

    $this->postJson('/tz-payments/webhooks/clickpesa', [
        'merchant_reference' => $this->payment->merchantReference,
        'status' => 'succeeded',
    ]);

    Event::assertDispatched(WebhookHandled::class, fn (WebhookHandled $e) => $e->gateway === 'clickpesa'
        && $e->payment->merchant_reference === $this->payment->merchantReference
        && $e->event->verified);
});

it('always keeps the latest raw gateway status', function () {
    $send = fn (string $raw) => $this->postJson('/tz-payments/webhooks/clickpesa', [
        'merchant_reference' => $this->payment->merchantReference,
        'status' => 'succeeded',
        'gateway_status' => $raw,
    ]);

    $send('SUCCESS');
    $send('SETTLED');

    expect(Payment::sole()->gateway_status)->toBe('SETTLED')
        ->and(TzPay::status($this->payment->reference)->gatewayStatus)->toBe('SETTLED');
});
