<?php

use JonasJohn\TzPayments\Enums\PaymentStatus;

it('lets a pending payment move to any other status', function (PaymentStatus $next) {
    expect(PaymentStatus::Pending->canTransitionTo($next))->toBeTrue();
})->with([PaymentStatus::Succeeded, PaymentStatus::Failed, PaymentStatus::Cancelled, PaymentStatus::Expired, PaymentStatus::Reversed]);

it('never changes a final status, except a reversal after success', function () {
    expect(PaymentStatus::Succeeded->canTransitionTo(PaymentStatus::Reversed))->toBeTrue()
        ->and(PaymentStatus::Succeeded->canTransitionTo(PaymentStatus::Failed))->toBeFalse()
        ->and(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Succeeded))->toBeFalse()
        ->and(PaymentStatus::Expired->canTransitionTo(PaymentStatus::Succeeded))->toBeFalse()
        ->and(PaymentStatus::Reversed->canTransitionTo(PaymentStatus::Succeeded))->toBeFalse()
        ->and(PaymentStatus::Pending->canTransitionTo(PaymentStatus::Pending))->toBeFalse();
});

it('marks every status except pending as final', function () {
    expect(PaymentStatus::Pending->isFinal())->toBeFalse()
        ->and(PaymentStatus::Succeeded->isFinal())->toBeTrue()
        ->and(PaymentStatus::Failed->isFinal())->toBeTrue();
});
