<?php

namespace JonasJohn\TzPayments\Exceptions;

class InvalidRequestException extends TzPaymentsException
{
    public static function invalidPhone(string $phone): self
    {
        return new self("The phone number [{$phone}] is not a valid Tanzanian mobile number.");
    }

    public static function invalidAmount(): self
    {
        return new self('The amount must be a whole number greater than zero. Decimals are not accepted.');
    }

    public static function invalidCurrency(string $currency): self
    {
        return new self("The currency [{$currency}] must be a three-letter ISO 4217 code, such as TZS.");
    }

    public static function invalidEmail(string $email): self
    {
        return new self("The customer email [{$email}] is not a valid email address.");
    }

    public static function invalidOperator(string $operator): self
    {
        return new self("The operator [{$operator}] is not supported.");
    }

    public static function invalidReference(): self
    {
        return new self('The reference must be a non-empty string of at most 100 characters.');
    }

    public static function unsupportedCurrency(string $gateway, string $currency): self
    {
        return new self("The [{$gateway}] gateway does not accept the currency [{$currency}].");
    }

    public static function amountTooHigh(string $gateway, int $max): self
    {
        return new self("The [{$gateway}] gateway accepts at most {$max} per payment.");
    }

    public static function unsupportedOption(string $gateway, string $option, string $value): self
    {
        return new self("The [{$gateway}] gateway does not accept [{$value}] for the [{$option}] option.");
    }

    public static function missingField(string $gateway, string $field): self
    {
        return new self("The [{$gateway}] gateway requires the [{$field}] field.");
    }

    public static function missingConfig(string $gateway, string $key): self
    {
        return new self("The [{$gateway}] gateway is missing the [{$key}] configuration value. Check config/tz-payments.php and your .env file.");
    }
}
