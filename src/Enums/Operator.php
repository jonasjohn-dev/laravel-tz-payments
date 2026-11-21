<?php

namespace JonasJohn\TzPayments\Enums;

enum Operator: string
{
    case Vodacom = 'vodacom';
    case Airtel = 'airtel';
    case Yas = 'yas';
    case Halotel = 'halotel';
    case Ttcl = 'ttcl';

    /**
     * Detect the operator from the two digits after the 255 country code.
     * Prefixes reflect original allocation; pass an explicit operator to override.
     */
    public static function fromPrefix(string $prefix): ?self
    {
        return match ($prefix) {
            '74', '75', '76' => self::Vodacom,
            '68', '69', '78' => self::Airtel,
            '65', '67', '71', '77' => self::Yas,
            '61', '62' => self::Halotel,
            '73' => self::Ttcl,
            default => null,
        };
    }

    public function walletName(): string
    {
        return match ($this) {
            self::Vodacom => 'M-Pesa',
            self::Airtel => 'Airtel Money',
            self::Yas => 'Mixx by Yas',
            self::Halotel => 'HaloPesa',
            self::Ttcl => 'T-Pesa',
        };
    }
}
