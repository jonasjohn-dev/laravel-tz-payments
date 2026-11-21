<?php

use JonasJohn\TzPayments\Support\Reference;

it('generates unique 20-character alphanumeric references', function () {
    $references = array_map(fn () => Reference::generate(), range(1, 500));

    foreach ($references as $reference) {
        expect($reference)->toMatch('/^[A-Z0-9]{20}$/');
    }

    expect(array_unique($references))->toHaveCount(500);
});

it('accepts a custom generator and can restore the default', function () {
    Reference::generateUsing(fn () => 'CUSTOM1');
    expect(Reference::generate())->toBe('CUSTOM1');

    Reference::generateUsing(null);
    expect(Reference::generate())->toStartWith('TZP');
});

it('sanitises references to letters and digits', function () {
    expect(Reference::sanitize('ORDER-1001/INV #2026'))->toBe('ORDER1001INV2026')
        ->and(Reference::sanitize('ABCDEFGHIJ-KLMNOPQRSTUVWXYZ'))->toBe('ABCDEFGHIJKLMNOPQRST')
        ->and(Reference::sanitize('A-B-C', 2))->toBe('AB');
});
