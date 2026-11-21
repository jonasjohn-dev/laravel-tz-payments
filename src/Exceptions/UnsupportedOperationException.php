<?php

namespace JonasJohn\TzPayments\Exceptions;

use JonasJohn\TzPayments\Enums\Capability;

class UnsupportedOperationException extends TzPaymentsException
{
    public static function forCapability(string $gateway, Capability $capability): self
    {
        return new self("The [{$gateway}] gateway does not support [{$capability->value}].");
    }

    public static function charging(string $gateway): self
    {
        return new self("The [{$gateway}] gateway supports neither USSD push nor hosted checkout, so it cannot take payments.");
    }
}
