<?php

namespace JonasJohn\TzPayments\Exceptions;

/**
 * Thrown to prevent charging the same reference twice.
 */
class DuplicatePaymentException extends InvalidRequestException
{
    public static function active(string $reference): self
    {
        return new self("A payment for reference [{$reference}] is already pending or has succeeded.");
    }

    public static function inProgress(string $reference): self
    {
        return new self("A payment for reference [{$reference}] is already being started.");
    }
}
