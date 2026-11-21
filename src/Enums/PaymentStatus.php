<?php

namespace JonasJohn\TzPayments\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Reversed = 'reversed';

    /**
     * A final status is never replaced, except Succeeded, which may still
     * become Reversed when the gateway returns the money to the customer.
     */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => $next !== self::Pending,
            self::Succeeded => $next === self::Reversed,
            default => false,
        };
    }
}
