<?php

namespace JonasJohn\TzPayments\Support;

use Closure;
use Illuminate\Support\Str;

final class Reference
{
    /**
     * 20 letters and digits fits every supported gateway, including
     * ClickPesa's limit of 20 alphanumeric characters.
     */
    public const LENGTH = 20;

    private const PREFIX = 'TZP';

    private static ?Closure $generator = null;

    /**
     * Generate the unique reference sent to the gateway for one payment attempt.
     */
    public static function generate(): string
    {
        if (self::$generator !== null) {
            return (string) (self::$generator)();
        }

        return self::PREFIX.strtoupper(Str::random(self::LENGTH - strlen(self::PREFIX)));
    }

    /**
     * Use a custom generator. It must return unique, alphanumeric references
     * of at most 20 characters. Pass null to restore the default.
     */
    public static function generateUsing(?callable $generator): void
    {
        self::$generator = $generator === null ? null : Closure::fromCallable($generator);
    }

    /**
     * Strip everything except letters and digits, then cut to the given length.
     */
    public static function sanitize(string $value, int $maxLength = self::LENGTH): string
    {
        return substr(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '', 0, $maxLength);
    }
}
