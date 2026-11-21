<?php

namespace JonasJohn\TzPayments\Support;

use JonasJohn\TzPayments\Exceptions\TzPaymentsException;
use JonasJohn\TzPayments\Models\Payment;

/**
 * Reads the configured payment model and checks it is usable.
 */
final class ModelResolver
{
    /**
     * @return class-string<Payment>
     */
    public static function paymentModel(): string
    {
        $model = config('tz-payments.database.model', Payment::class);

        if (! is_string($model) || ! is_a($model, Payment::class, true)) {
            throw new TzPaymentsException('The tz-payments.database.model setting must be '.Payment::class.' or a class that extends it.');
        }

        return $model;
    }
}
