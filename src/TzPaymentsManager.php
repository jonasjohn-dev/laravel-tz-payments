<?php

namespace JonasJohn\TzPayments;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use JonasJohn\TzPayments\Contracts\Gateway;
use JonasJohn\TzPayments\Data\PaymentRequest;
use JonasJohn\TzPayments\Data\PaymentResponse;
use JonasJohn\TzPayments\Drivers\AzamPayGateway;
use JonasJohn\TzPayments\Exceptions\WebhookException;
use JonasJohn\TzPayments\Testing\FakeGateway;
use Symfony\Component\HttpFoundation\Response;

/**
 * The entry point behind the TzPay facade. Resolves gateway drivers using
 * Laravel's Manager pattern, so custom gateways can be added with extend().
 */
class TzPaymentsManager extends Manager
{
    protected ?FakeGateway $fake = null;

    public function getDefaultDriver(): string
    {
        return $this->config->get('tz-payments.default', 'clickpesa');
    }

    public function gateway(?string $name = null): Gateway
    {
        return $this->driver($name);
    }

    /**
     * @param  string|null  $driver
     */
    public function driver($driver = null): Gateway
    {
        return $this->fake ?? parent::driver($driver);
    }

    /**
     * Start a payment.
     *
     * @param  array<string, mixed>|PaymentRequest  $payment
     */
    public function charge(array|PaymentRequest $payment, ?string $gateway = null): PaymentResponse
    {
        $name = $gateway ?? $this->getDefaultDriver();

        if (is_array($payment)) {
            $payment = PaymentRequest::fromArray($payment, $this->config->get('tz-payments.currency', 'TZS'));
        }

        return $this->processor()->charge($name, $this->gateway($name), $payment);
    }

    /**
     * Get the current status of the latest payment attempt for a reference.
     */
    public function status(string $reference): PaymentResponse
    {
        $payment = $this->processor()->find($reference);

        return $this->processor()->refresh($payment, fn (): Gateway => $this->gateway($payment->gateway));
    }

    public function handleWebhook(string $gateway, Request $request): Response
    {
        if (! $this->hasGateway($gateway)) {
            throw WebhookException::unknownGateway($gateway);
        }

        return $this->processor()->handleWebhook($gateway, $this->gateway($gateway), $request);
    }

    public function hasGateway(string $name): bool
    {
        return $this->fake !== null
            || isset($this->customCreators[$name])
            || method_exists($this, 'create'.Str::studly($name).'Driver');
    }

    /**
     * Replace every gateway with a fake that records charges and never calls a real gateway.
     */
    public function fake(): FakeGateway
    {
        return $this->fake = new FakeGateway;
    }

    public function isFaking(): bool
    {
        return $this->fake !== null;
    }

    protected function createAzampayDriver(): AzamPayGateway
    {
        return new AzamPayGateway(
            $this->gatewayConfig('azampay'),
            $this->container->make(HttpFactory::class),
            $this->container->make('cache')->store(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatewayConfig(string $name): array
    {
        return array_merge(
            ['timeout' => (int) $this->config->get('tz-payments.timeout', 30)],
            (array) $this->config->get("tz-payments.gateways.{$name}", []),
        );
    }

    protected function processor(): PaymentProcessor
    {
        return $this->container->make(PaymentProcessor::class);
    }
}
