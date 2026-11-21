<?php

namespace JonasJohn\TzPayments\Drivers;

use stdClass;

/**
 * ClickPesa's checksum: HMAC-SHA256 (hex) over canonical JSON, meaning object
 * keys sorted alphabetically at every depth and no extra whitespace. The
 * "checksum" and "checksumMethod" fields are left out before signing.
 */
final class ClickPesaChecksum
{
    private const EXCLUDED = ['checksum', 'checksumMethod'];

    /**
     * @param  array<array-key, mixed>|stdClass  $payload  Decode webhook bodies as objects so empty objects stay "{}".
     */
    public static function sign(array|stdClass $payload, string $key): string
    {
        return hash_hmac('sha256', self::canonicalJson($payload), $key);
    }

    /**
     * @param  array<array-key, mixed>|stdClass  $payload
     */
    public static function verify(array|stdClass $payload, string $key, string $checksum): bool
    {
        return hash_equals(self::sign($payload, $key), strtolower($checksum));
    }

    /**
     * @param  array<array-key, mixed>|stdClass  $payload
     */
    public static function canonicalJson(array|stdClass $payload): string
    {
        $payload = self::withoutExcluded($payload);

        return (string) json_encode(self::canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<array-key, mixed>|stdClass  $payload
     * @return array<array-key, mixed>|stdClass
     */
    private static function withoutExcluded(array|stdClass $payload): array|stdClass
    {
        if ($payload instanceof stdClass) {
            $payload = clone $payload;

            foreach (self::EXCLUDED as $field) {
                unset($payload->{$field});
            }

            return $payload;
        }

        return array_diff_key($payload, array_flip(self::EXCLUDED));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);

            $sorted = new stdClass;

            foreach ($properties as $key => $item) {
                $sorted->{$key} = self::canonicalize($item);
            }

            return $sorted;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::canonicalize(...), $value);
            }

            ksort($value, SORT_STRING);

            return (object) array_map(self::canonicalize(...), $value);
        }

        return $value;
    }
}
