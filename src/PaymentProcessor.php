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
use JonasJohn\TzPayments\Events\WebhookHandled;
use JonasJohn\TzPayments\Exceptions\DuplicatePaymentException;
use JonasJohn\TzPayments\Exceptions\GatewayException;
use JonasJohn\TzPayments\Exceptions\PaymentNotFoundException;
use JonasJohn\TzPayments\Exceptions\UnsupportedOperationException;
use JonasJohn\TzPayments\Exceptions\WebhookException;
use JonasJohn\TzPayments\Models\Payment;
use JonasJohn\TzPayments\Models\WebhookCall;
use JonasJohn\TzPayments\Support\PaymentUpdater;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the charge, status and webhook flows the same way for every gateway.
 */
final class PaymentProcessor
{
    /**
     * @param  class-string<Payment>  $model
     * @param  list<string>  $redact  Payload keys hidden before a webhook is logged.
     */
    public function __construct(
        private readonly PaymentUpdater $updater,
        private readonly Dispatcher $events,
        private readonly Cache $cache,
        private readonly string $model = Payment::class,
        private readonly int $lockSeconds = 60,
        private readonly bool $logWebhooks = true,
        private readonly array $redact = [],
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
                'gateway_status' => $result->gatewayStatus,
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
            'gateway_status' => $result->gatewayStatus,
            'message' => $result->message,
        ]);

        return $payment->toResponse($result->message, $result->raw);
    }

    /**
     * Every webhook is logged, verified, then applied once.
     *
     * @throws WebhookException
     * @throws GatewayException When the status lookup fails; the gateway should retry the webhook.
     */
    public function handleWebhook(string $gatewayName, Gateway $gateway, Request $request): Response
    {
        if (! $gateway instanceof HandlesWebhooks) {
            throw WebhookException::unknownGateway($gatewayName);
        }

        $call = $this->logWebhook($gatewayName, $request);

        try {
            $event = $gateway->parseWebhook($request);
            $payment = $this->findForWebhook($gatewayName, $event);

            // Unknown payment: acknowledge so the gateway stops retrying, change nothing.
            if ($payment === null) {
                $this->finishLog($call, WebhookCall::STATUS_IGNORED, 'No matching payment.');

                return $gateway->acknowledgeWebhook($event);
            }

            if ($event->verified && $event->status !== null) {
                $status = $event->status;
                $gatewayStatus = $event->gatewayStatus;
                $message = $event->message;
            } elseif ($gateway->supports(Capability::StatusLookup)) {
                // Never trust an unverified claim: ask the gateway itself.
                $result = $gateway->status(StatusRequest::fromPayment($payment));
                $status = $result->status;
                $gatewayStatus = $result->gatewayStatus;
                $message = $result->message;
            } else {
                throw WebhookException::unverifiable($gatewayName);
            }

            $this->updater->apply($payment, $status, [
                'gateway_reference' => $event->gatewayReference,
                'gateway_status' => $gatewayStatus,
                'message' => $message,
            ]);

            $this->finishLog($call, WebhookCall::STATUS_PROCESSED, null, $payment);
            $this->events->dispatch(new WebhookHandled($gatewayName, $payment, $event));

            return $gateway->acknowledgeWebhook($event);
        } catch (WebhookException $e) {
            $this->finishLog($call, WebhookCall::STATUS_REJECTED, $e->getMessage());

            throw $e;
        } catch (GatewayException $e) {
            $this->finishLog($call, WebhookCall::STATUS_FAILED, $e->getMessage());

            throw $e;
        }
    }

    private function logWebhook(string $gatewayName, Request $request): ?WebhookCall
    {
        if (! $this->logWebhooks) {
            return null;
        }

        return WebhookCall::query()->create([
            'gateway' => $gatewayName,
            'status' => WebhookCall::STATUS_RECEIVED,
            'payload' => $this->redact($request->all()),
        ]);
    }

    private function finishLog(?WebhookCall $call, string $status, ?string $message = null, ?Payment $payment = null): void
    {
        $call?->forceFill([
            'status' => $status,
            'message' => $message,
            'payment_id' => $payment?->getKey(),
        ])->save();
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function redact(array $payload): array
    {
        $hidden = array_map('strtolower', $this->redact);

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $hidden, true)) {
                $payload[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
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
