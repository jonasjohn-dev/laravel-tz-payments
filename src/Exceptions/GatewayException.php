<?php

namespace JonasJohn\TzPayments\Exceptions;

use Throwable;

class GatewayException extends TzPaymentsException
{
    /**
     * @param  array<string, mixed>  $response  The gateway's response body. Must never contain credentials.
     * @param  bool  $outcomeUnknown  True when the request may have reached the gateway (for example a
     *                                timeout), so the payment could still complete and must stay pending.
     */
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly array $response = [],
        public readonly bool $outcomeUnknown = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public static function requestFailed(string $gateway, int $httpStatus, array $response = [], ?string $reason = null): self
    {
        $message = "The [{$gateway}] gateway rejected the request with HTTP {$httpStatus}".($reason ? ": {$reason}" : '.');

        return new self($message, $httpStatus, $response);
    }

    public static function timeout(string $gateway, ?Throwable $previous = null): self
    {
        return new self(
            "No response from the [{$gateway}] gateway. The payment may still complete, so it was left pending.",
            outcomeUnknown: true,
            previous: $previous,
        );
    }
}
