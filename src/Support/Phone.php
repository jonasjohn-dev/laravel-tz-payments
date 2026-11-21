<?php

namespace JonasJohn\TzPayments\Support;

use JonasJohn\TzPayments\Enums\Operator;
use JonasJohn\TzPayments\Exceptions\InvalidRequestException;
use Stringable;

final class Phone implements Stringable
{
    private function __construct(
        public readonly string $number,
    ) {}

    /**
     * Accepts 0754123456, 754123456, 255754123456, +255754123456 and
     * 00255754123456, with optional spaces, dashes, dots or brackets.
     *
     * @throws InvalidRequestException
     */
    public static function parse(string $value): self
    {
        $digits = preg_replace('/[\s\-.()]/', '', trim($value)) ?? '';

        if (str_starts_with($digits, '+')) {
            $digits = substr($digits, 1);
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $number = match (true) {
            strlen($digits) === 10 && str_starts_with($digits, '0') => '255'.substr($digits, 1),
            strlen($digits) === 9 => '255'.$digits,
            default => $digits,
        };

        if (preg_match('/^255[67]\d{8}$/', $number) !== 1) {
            throw InvalidRequestException::invalidPhone($value);
        }

        return new self($number);
    }

    public static function isValid(string $value): bool
    {
        try {
            self::parse($value);

            return true;
        } catch (InvalidRequestException) {
            return false;
        }
    }

    /** 255754123456 */
    public function international(): string
    {
        return $this->number;
    }

    /** +255754123456 */
    public function withPlus(): string
    {
        return '+'.$this->number;
    }

    /** 0754123456 */
    public function local(): string
    {
        return '0'.substr($this->number, 3);
    }

    public function operator(): ?Operator
    {
        return Operator::fromPrefix(substr($this->number, 3, 2));
    }

    public function __toString(): string
    {
        return $this->number;
    }
}
