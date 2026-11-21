<?php

namespace JonasJohn\TzPayments\Support;

/**
 * Converts whole-number amounts in a currency's smallest unit to the decimal
 * strings gateways expect.
 *
 * Decimals reflect the smallest unit in practical use, not always ISO 4217:
 * TZS is handled in whole shillings (0), USD in cents (2).
 */
final class Currency
{
    private const DEFAULT_DECIMALS = 2;

    public static function decimals(string $currency): int
    {
        $map = (array) config('tz-payments.currencies', ['TZS' => 0, 'USD' => 2]);

        return (int) ($map[strtoupper($currency)] ?? self::DEFAULT_DECIMALS);
    }

    /**
     * 1050 USD becomes "10.50"; 5000 TZS becomes "5000".
     */
    public static function toDecimal(int $amount, string $currency): string
    {
        $decimals = self::decimals($currency);

        if ($decimals === 0) {
            return (string) $amount;
        }

        $sign = $amount < 0 ? '-' : '';
        $digits = str_pad((string) abs($amount), $decimals + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$decimals).'.'.substr($digits, -$decimals);
    }

    /**
     * "10.50" USD becomes 1050. Used to read amounts back from gateways.
     * Returns null when the value has more decimals than the currency allows.
     */
    public static function fromDecimal(string|int|float $value, string $currency): ?int
    {
        $decimals = self::decimals($currency);
        $value = trim((string) $value);

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m) !== 1) {
            return null;
        }

        $fraction = rtrim($m[3] ?? '', '0');

        if (strlen($fraction) > $decimals) {
            return null;
        }

        $minor = (int) ($m[2].str_pad($fraction, $decimals, '0'));

        return $m[1] === '-' ? -$minor : $minor;
    }
}
