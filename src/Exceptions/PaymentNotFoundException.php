<?php

namespace JonasJohn\TzPayments\Exceptions;

class PaymentNotFoundException extends TzPaymentsException
{
    public static function forReference(string $reference): self
    {
        return new self("No payment was found for reference [{$reference}].");
    }
}
