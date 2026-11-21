<?php

namespace JonasJohn\TzPayments\Events;

use Illuminate\Queue\SerializesModels;
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Models\Payment;

/**
 * Fired on every real status change, before the specific event.
 */
class PaymentStatusChanged
{
    use SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly PaymentStatus $previous,
    ) {}
}
