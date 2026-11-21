<?php

namespace JonasJohn\TzPayments\Contracts;

use JonasJohn\TzPayments\Data\GatewayResponse;
use JonasJohn\TzPayments\Data\PaymentRequest;
use JonasJohn\TzPayments\Data\StatusRequest;
use JonasJohn\TzPayments\Enums\Capability;
use JonasJohn\TzPayments\Exceptions\GatewayException;
use JonasJohn\TzPayments\Exceptions\InvalidRequestException;
use JonasJohn\TzPayments\Exceptions\UnsupportedOperationException;

/**
 * The contract every payment gateway driver implements.
 */
interface Gateway
{
    public function supports(Capability $capability): bool;

    /**
     * Start a payment. Must never retry automatically: a retry could charge the customer twice.
     * Throw GatewayException::timeout() when the outcome is unknown.
     *
     * @throws GatewayException
     * @throws InvalidRequestException
     */
    public function charge(PaymentRequest $request): GatewayResponse;

    /**
     * Ask the gateway for the current status of a payment.
     *
     * @throws GatewayException
     * @throws UnsupportedOperationException When the gateway has no status lookup.
     */
    public function status(StatusRequest $request): GatewayResponse;
}
