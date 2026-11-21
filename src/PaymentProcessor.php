<?php

namespace JonasJohn\TzPayments;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use JonasJohn\TzPayments\Contracts\Gateway;
use JonasJohn\TzPayments\Contracts\HandlesWebhooks;
use JonasJohn\TzPayments\Data\PaymentRequest;
use JonasJohn\TzPayments\Data\PaymentResponse;
use JonasJohn\TzPayments\Data\StatusRequest;
use JonasJohn\TzPayments\Data\WebhookEvent;
use JonasJohn\TzPayments\Enums\Capability;
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Events\PaymentInitiated;
use JonasJohn\TzPayments\Exceptions\DuplicatePaymentException;
use JonasJohn\TzPayments\Exceptions\GatewayException;
use JonasJohn\TzPayments\Exceptions\PaymentNotFoundException;
use JonasJohn\TzPayments\Exceptions\UnsupportedOperationException;
use JonasJohn\TzPayments\Exceptions\WebhookException;
use JonasJohn\TzPayments\Models\Payment;
use JonasJohn\TzPayments\Support\PaymentUpdater;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the charge, status and webhook flows the same way for every gateway.
 */
final class PaymentProcessor
{
    /**
     * @param  class-string<Payment>  $model
     */
    public function __construct(
        private readonly PaymentUpdater $updater,
        private readonly Dispatcher $events,
        private readonly Cache $cache,
        private readonly string $model = Payment::class,
        private readonly int $lockSeconds = 60,
    ) {}

    public function charge(string $gatewayName, Gateway $gateway, PaymentRequest $request): PaymentResponse
    {
        if (! $gateway->supports(Capability::UssdPush) && ! $gateway->supports(Capability::HostedCheckout)) {
            throw UnsupportedOperationException::charging($gatewayName);
        }

        return $this->withChargeLock($request->reference, function () use ($gatewayName, $gateway, $request): PaymentResponse {
            if ($this->query()->where('reference', $request->reference)
                ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Succeeded->value])
                ->exists()) {
                throw DuplicatePaymentException::active($request->reference);
            }

            // Saved before calling the gateway, so a fast webhook always finds it.
            $payment = $this->query()->create([
                'gateway' => $gatewayName,
                'reference' => $request->reference,
                'merchant_reference' => $request->merchantReference,
                'status' => PaymentStatus::Pending,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'phone' => $request->phone?->international(),
                'operator' => $request->operator,
                'description' => $request->description,
                'customer_name' => $request->customerName,
                'customer_email' => $request->customerEmail,
                'meta' => $request->meta ?: null,
            ]);

            try {
                $result = $gateway->charge($request);
            } catch (GatewayException $e) {
                if (! $e->outcomeUnknown) {
                    $this->updater->apply($payment, PaymentStatus::Failed, ['message' => $e->getMessage()]);
                }

                throw $e;
            }

            $payment->forceFill(array_filter([
                'gateway_reference' => $result->gatewayReference,
                'redirect_url' => $result->redirectUrl,
                'message' => $result->message,
            ], fn ($value) => $value !== null))->save();

            $this->events->dispatch(new PaymentInitiated($payment));

            if ($result->status !== PaymentStatus::Pending) {
                $this->updater->apply($payment, $result->status);
            }

            return $payment->toResponse($result->message, $result->raw);
        });
    }

    /**
     * @throws PaymentNotFoundException
     */
    public function find(string $reference): Payment
    {
        return $this->query()->where('reference', $reference)->latest('id')->first()
            ?? throw PaymentNotFoundException::forReference($reference);
    }

    /**
     * Final payments are answered from the database. Pending ones are checked
     * with the gateway when it supports status lookup.
     *
     * @param  Closure(): Gateway  $resolveGateway
     */
    public function refresh(Payment $payment, Closure $resolveGateway): PaymentResponse
    {
        if ($payment->isFinal()) {
            return $payment->toResponse();
        }

        $gateway = $resolveGateway();

        if (! $gateway->supports(Capability::StatusLookup)) {
            return $payment->toResponse();
        }

        $result = $gateway->status(StatusRequest::fromPayment($payment));

        $this->updater->apply($payment, $result->status, [
            'gateway_reference' => $result->gatewayReference,
            'message' => $result->message,
        ]);

        return $payment->toResponse($result->message, $result->raw);
    }

    /**
     * @throws WebhookException
     * @throws GatewayException When the status lookup fails; the gateway should retry the webhook.
     */
    public function handleWebhook(string $gatewayName, Gateway $gateway, Request $request): Response
    {
        if (! $gateway instanceof HandlesWebhooks) {
            throw WebhookException::unknownGateway($gatewayName);
        }

        $event = $gateway->parseWebhook($request);
        $payment = $this->findForWebhook($gatewayName, $event);

        // Unknown payment: acknowledge so the gateway stops retrying, change nothing.
        if ($payment === null) {
            return $gateway->acknowledgeWebhook($event);
        }

        if ($event->verified && $event->status !== null) {
            $status = $event->status;
            $message = $event->message;
        } elseif ($gateway->supports(Capability::StatusLookup)) {
            // Never trust an unverified claim: ask the gateway itself.
            $result = $gateway->status(StatusRequest::fromPayment($payment));
            $status = $result->status;
            $message = $result->message;
        } else {
            throw WebhookException::unverifiable($gatewayName);
        }

        $this->updater->apply($payment, $status, [
            'gateway_reference' => $event->gatewayReference,
            'message' => $message,
        ]);

        return $gateway->acknowledgeWebhook($event);
    }

    private function findForWebhook(string $gatewayName, WebhookEvent $event): ?Payment
    {
        if ($event->merchantReference === null && $event->gatewayReference === null) {
            return null;
        }

        return $this->query()
            ->where('gateway', $gatewayName)
            ->where(function (Builder $query) use ($event): void {
                if ($event->merchantReference !== null) {
                    $query->orWhere('merchant_reference', $event->merchantReference);
                }

                if ($event->gatewayReference !== null) {
                    $query->orWhere('gateway_reference', $event->gatewayReference);
                }
            })
            ->first();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withChargeLock(string $reference, Closure $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        $lock = $store->lock('tz-payments:charge:'.sha1($reference), $this->lockSeconds);

        if (! $lock->get()) {
            throw DuplicatePaymentException::inProgress($reference);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return Builder<Payment>
     */
    private function query(): Builder
    {
        return (new $this->model)->newQuery();
    }
}
