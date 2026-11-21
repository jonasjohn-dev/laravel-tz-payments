<?php

namespace JonasJohn\TzPayments\Support;

use Illuminate\Contracts\Events\Dispatcher;
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Events\PaymentFailed;
use JonasJohn\TzPayments\Events\PaymentReversed;
use JonasJohn\TzPayments\Events\PaymentStatusChanged;
use JonasJohn\TzPayments\Events\PaymentSucceeded;
use JonasJohn\TzPayments\Models\Payment;

/**
 * The only place a payment's status changes.
 *
 * The row is locked inside a transaction, so duplicate or concurrent webhooks
 * cannot apply the same change twice, and events fire only on a real change.
 */
final class PaymentUpdater
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Extra columns to store, such as gateway_reference or message.
     *                                            Without a status change, only empty columns are filled,
     *                                            except gateway_status, which always holds the latest value.
     * @return bool Whether the status changed.
     */
    public function apply(Payment $payment, PaymentStatus $status, array $attributes = []): bool
    {
        $previous = $payment->status;

        $changed = $payment->getConnection()->transaction(function () use ($payment, $status, $attributes, &$previous): bool {
            /** @var Payment $locked */
            $locked = $payment->newQuery()->lockForUpdate()->findOrFail($payment->getKey());
            $previous = $locked->status;
            $changed = $previous->canTransitionTo($status);

            foreach ($attributes as $key => $value) {
                $alwaysUpdate = $key === 'gateway_status';

                if ($value !== null && ($changed || $alwaysUpdate || $locked->getAttribute($key) === null)) {
                    $locked->setAttribute($key, $value);
                }
            }

            if ($changed) {
                $locked->status = $status;

                if ($status === PaymentStatus::Succeeded) {
                    $locked->paid_at ??= $locked->freshTimestamp();
                }
            }

            if ($locked->isDirty()) {
                $locked->save();
            }

            $payment->setRawAttributes($locked->getAttributes(), true);

            return $changed;
        });

        if ($changed) {
            $this->dispatchEvents($payment, $previous);
        }

        return $changed;
    }

    private function dispatchEvents(Payment $payment, PaymentStatus $previous): void
    {
        $this->events->dispatch(new PaymentStatusChanged($payment, $previous));

        $event = match ($payment->status) {
            PaymentStatus::Succeeded => new PaymentSucceeded($payment),
            PaymentStatus::Failed, PaymentStatus::Cancelled, PaymentStatus::Expired => new PaymentFailed($payment),
            PaymentStatus::Reversed => new PaymentReversed($payment),
            PaymentStatus::Pending => null,
        };

        if ($event !== null) {
            $this->events->dispatch($event);
        }
    }
}
