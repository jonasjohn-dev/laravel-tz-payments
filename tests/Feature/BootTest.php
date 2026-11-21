<?php

it('boots a Laravel application under Testbench', function () {
    expect(app()->version())->toBeString()->not->toBeEmpty();
});
