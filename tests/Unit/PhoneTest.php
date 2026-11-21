<?php

use JonasJohn\TzPayments\Enums\Operator;
use JonasJohn\TzPayments\Exceptions\InvalidRequestException;
use JonasJohn\TzPayments\Support\Phone;

it('normalises every common format to 255XXXXXXXXX', function (string $input) {
    expect(Phone::parse($input)->international())->toBe('255754123456');
})->with([
    '0754123456',
    '754123456',
    '255754123456',
    '+255754123456',
    '00255754123456',
    '+255 754 123 456',
    '0754-123-456',
    '(0754) 123.456',
]);

it('rejects numbers that are not Tanzanian mobile numbers', function (string $input) {
    Phone::parse($input);
})->throws(InvalidRequestException::class)->with([
    '',
    'abc',
    '12345',
    '0854123456',
    '256754123456',
    '07541234567',
    '+2557541234',
]);

it('formats the number in local and plus forms', function () {
    $phone = Phone::parse('0754123456');

    expect($phone->local())->toBe('0754123456')
        ->and($phone->withPlus())->toBe('+255754123456')
        ->and((string) $phone)->toBe('255754123456');
});

it('detects the operator from the prefix', function (string $number, ?Operator $operator) {
    expect(Phone::parse($number)->operator())->toBe($operator);
})->with([
    ['0754123456', Operator::Vodacom],
    ['0688123456', Operator::Airtel],
    ['0784123456', Operator::Airtel],
    ['0715123456', Operator::Yas],
    ['0622123456', Operator::Halotel],
    ['0733123456', Operator::Ttcl],
    ['0799123456', null],
]);

it('reports validity without throwing', function () {
    expect(Phone::isValid('0754123456'))->toBeTrue()
        ->and(Phone::isValid('123'))->toBeFalse();
});
