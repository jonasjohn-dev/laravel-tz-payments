<?php

use JonasJohn\TzPayments\Drivers\ClickPesaChecksum;

it('sorts keys at every depth and removes whitespace', function () {
    $payload = ['b' => 1, 'a' => ['d' => 'x', 'c' => [2, 1]], 'url' => 'https://x.test/a'];

    expect(ClickPesaChecksum::canonicalJson($payload))->toBe('{"a":{"c":[2,1],"d":"x"},"b":1,"url":"https://x.test/a"}');
});

it('leaves out checksum and checksumMethod', function () {
    $payload = ['amount' => '1000', 'checksum' => 'abc', 'checksumMethod' => 'canonical'];

    expect(ClickPesaChecksum::canonicalJson($payload))->toBe('{"amount":"1000"}');
});

it('signs with HMAC-SHA256 as lowercase hex', function () {
    $payload = ['orderReference' => 'ORDER1', 'amount' => '1000', 'currency' => 'TZS'];
    $expected = hash_hmac('sha256', '{"amount":"1000","currency":"TZS","orderReference":"ORDER1"}', 'secret-key');

    expect(ClickPesaChecksum::sign($payload, 'secret-key'))->toBe($expected)
        ->and($expected)->toMatch('/^[a-f0-9]{64}$/');
});

it('keeps empty objects and unicode exactly as sent', function () {
    $decoded = json_decode('{"z":{},"name":"Zuhura Ñ","list":[]}', false);

    expect(ClickPesaChecksum::canonicalJson($decoded))->toBe('{"list":[],"name":"Zuhura Ñ","z":{}}');
});

it('verifies in constant time and accepts upper-case hex', function () {
    $payload = ['a' => '1'];
    $checksum = ClickPesaChecksum::sign($payload, 'k');

    expect(ClickPesaChecksum::verify($payload, 'k', strtoupper($checksum)))->toBeTrue()
        ->and(ClickPesaChecksum::verify($payload, 'other', $checksum))->toBeFalse();
});
