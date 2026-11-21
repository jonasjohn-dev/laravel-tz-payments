<?php

use JonasJohn\TzPayments\Support\Currency;

it('formats whole-number amounts for gateways', function (int $amount, string $currency, string $expected) {
    expect(Currency::toDecimal($amount, $currency))->toBe($expected);
})->with([
    [5000, 'TZS', '5000'],
    [1050, 'USD', '10.50'],
    [5, 'USD', '0.05'],
    [100, 'usd', '1.00'],
    [1999, 'KES', '19.99'],
]);

it('reads decimal amounts back into whole numbers', function (string|int $value, string $currency, ?int $expected) {
    expect(Currency::fromDecimal($value, $currency))->toBe($expected);
})->with([
    ['5000', 'TZS', 5000],
    ['5000.00', 'TZS', 5000],
    ['10.50', 'USD', 1050],
    ['10.5', 'USD', 1050],
    [20, 'USD', 2000],
    ['10.505', 'USD', null],
    ['5000.50', 'TZS', null],
    ['abc', 'USD', null],
]);

it('reads decimals from config', function () {
    config(['tz-payments.currencies.KWD' => 3]);

    expect(Currency::toDecimal(1500, 'KWD'))->toBe('1.500');
});
