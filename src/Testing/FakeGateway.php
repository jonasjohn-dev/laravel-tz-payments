<?php

namespace JonasJohn\TzPayments\Testing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JonasJohn\TzPayments\Contracts\Gateway;
use JonasJohn\TzPayments\Contracts\HandlesWebhooks;
use JonasJohn\TzPayments\Data\GatewayResponse;
use JonasJohn\TzPayments\Data\PaymentRequest;
use JonasJohn\TzPayments\Data\StatusRequest;
use JonasJohn\TzPayments\Data\WebhookEvent;
use JonasJohn\TzPayments\Enums\Capability;
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Exceptions\UnsupportedOperationException;
use JonasJohn\TzPayments\Exceptions\WebhookException;
use PHPUnit\Framework\Assert as PHPUnit;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A gateway for tests. It records charges, lets the test decide every outcome,
 * and never makes a network call.
 *
 * Its webhooks accept JSON like {"merchant_reference": "...", "status": "succeeded", "gateway_status": "SETTLED"}.
 */
class FakeGateway implements Gateway, HandlesWebhooks
{
    /** @var list<PaymentRequest> */
    protected array $charges = [];

    /** @var array<string, PaymentStatus> */
    protected array $statuses = [];

    /** @var array<string, bool> */
    protected array $unsupported = [];

    protected int $statusChecks = 0;

    protected PaymentStatus $chargeStatus = PaymentStatus::Pending;

    protected ?string $redirectUrl = null;

    protected ?Throwable $chargeException = null;

    protected bool $verifiesWebhooks = true;

    public function supports(Capability $capability): bool
    {
        return ! isset($this->unsupported[$capability->value]);
    }

    public function charge(PaymentRequest $request): GatewayResponse
    {
        if ($this->chargeException !== null) {
            throw $this->chargeException;
        }

        $this->charges[] = $request;

        return new GatewayResponse(
            status: $this->chargeStatus,
            gatewayReference: 'FAKE-'.$request->merchantReference,
            redirectUrl: $this->redirectUrl,
        );
    }

    public function status(StatusRequest $request): GatewayResponse
    {
        if (! $this->supports(Capability::StatusLookup)) {
            throw UnsupportedOperationException::forCapability('fake', Capability::StatusLookup);
        }

        $this->statusChecks++;

        return new GatewayResponse(
            status: $this->statuses[$request->reference]
                ?? $this->statuses[$request->merchantReference]
                ?? PaymentStatus::Pending,
            gatewayReference: $request->gatewayReference,
        );
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $reference = $request->input('merchant_reference');

        if (! is_string($reference) || $reference === '') {
            throw WebhookException::malformed('merchant_reference is missing.');
        }

        return new WebhookEvent(
            merchantReference: $reference,
            status: PaymentStatus::tryFrom((string) $request->input('status')),
            verified: $this->verifiesWebhooks,
            gatewayStatus: $request->input('gateway_status'),
            raw: $request->all(),
        );
    }

    public function acknowledgeWebhook(WebhookEvent $event): Response
    {
        return new JsonResponse(['received' => true]);
    }

    // Configuring outcomes

    /** Status returned by every charge (default Pending). */
    public function chargeWillReturn(PaymentStatus $status): static
    {
        $this->chargeStatus = $status;

        return $this;
    }

    /** Make every charge return a hosted checkout URL. */
    public function chargeWillRedirectTo(string $url): static
    {
        $this->redirectUrl = $url;

        return $this;
    }

    /** Make every charge throw, for example GatewayException::timeout('fake'). */
    public function chargeWillFail(Throwable $exception): static
    {
        $this->chargeException = $exception;

        return $this;
    }

    /** Status the gateway reports for a reference (yours or the merchant reference). */
    public function setStatus(string $reference, PaymentStatus $status): static
    {
        $this->statuses[$reference] = $status;

        return $this;
    }

    public function withoutCapability(Capability ...$capabilities): static
    {
        foreach ($capabilities as $capability) {
            $this->unsupported[$capability->value] = true;
        }

        return $this;
    }

    /** Treat webhooks as unverified, so the status lookup decides. */
    public function withoutWebhookVerification(): static
    {
        $this->verifiesWebhooks = false;

        return $this;
    }

    // Inspecting and asserting

    /**
     * @param  (callable(PaymentRequest): bool)|null  $callback
     * @return list<PaymentRequest>
     */
    public function charged(?callable $callback = null): array
    {
        return $callback === null
            ? $this->charges
            : array_values(array_filter($this->charges, $callback));
    }

    public function statusChecks(): int
    {
        return $this->statusChecks;
    }

    /**
     * @param  (callable(PaymentRequest): bool)|null  $callback
     */
    public function assertCharged(?callable $callback = null): void
    {
        PHPUnit::assertNotEmpty($this->charged($callback), 'The expected charge was not made.');
    }

    /**
     * @param  callable(PaymentRequest): bool  $callback
     */
    public function assertNotCharged(callable $callback): void
    {
        PHPUnit::assertEmpty($this->charged($callback), 'An unexpected charge was made.');
    }

    public function assertNothingCharged(): void
    {
        PHPUnit::assertEmpty($this->charges, 'Charges were made unexpectedly.');
    }

    public function assertChargedTimes(int $times): void
    {
        PHPUnit::assertCount($times, $this->charges, "Expected {$times} charges, got ".count($this->charges).'.');
    }
}
