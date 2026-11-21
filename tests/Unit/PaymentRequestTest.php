<?php

use JonasJohn\TzPayments\Data\PaymentRequest;
use JonasJohn\TzPayments\Enums\Operator;
use JonasJohn\TzPayments\Exceptions\InvalidRequestException;

it('builds a request from an array', function () {
    $request = PaymentRequest::fromArray([
        'amount' => 5000,
        'phone' => '0754123456',
        'reference' => 'ORDER-1001',
        'description' => 'Order 1001',
        'customer_email' => 'buyer@example.com',
        'meta' => ['order_id' => 1001],
    ]);

    expect($request->amount)->toBe(5000)
        ->and($request->currency)->toBe('TZS')
        ->and($request->reference)->toBe('ORDER-1001')
        ->and($request->merchantReference)->toMatch('/^[A-Z0-9]{20}$/')
        ->and($request->phone?->international())->toBe('255754123456')
        ->and($request->operator)->toBe(Operator::Vodacom)
        ->and($request->meta)->toBe(['order_id' => 1001]);
});

it('uses the merchant reference when no reference is given', function () {
    $request = PaymentRequest::fromArray(['amount' => 1000]);

    expect($request->reference)->toBe($request->merchantReference);
});

it('accepts a whole-number string amount', function () {
    expect(PaymentRequest::fromArray(['amount' => '2500'])->amount)->toBe(2500);
});

it('lets the developer override the detected operator', function () {
    $request = PaymentRequest::fromArray(['amount' => 1000, 'phone' => '0754123456', 'operator' => 'airtel']);

    expect($request->operator)->toBe(Operator::Airtel);
});

it('rejects invalid input', function (array $data) {
    PaymentRequest::fromArray($data);
})->throws(InvalidRequestException::class)->with([
    'missing amount' => [[]],
    'zero amount' => [['amount' => 0]],
    'negative amount' => [['amount' => -5]],
    'decimal amount' => [['amount' => 10.5]],
    'decimal string' => [['amount' => '10.50']],
    'bad currency' => [['amount' => 100, 'currency' => 'shilling']],
    'bad email' => [['amount' => 100, 'customer_email' => 'not-an-email']],
    'bad operator' => [['amount' => 100, 'operator' => 'unknown']],
    'bad phone' => [['amount' => 100, 'phone' => '123']],
    'empty reference' => [['amount' => 100, 'reference' => '  ']],
]);
