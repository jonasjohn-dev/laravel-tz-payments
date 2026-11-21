<?php

namespace JonasJohn\TzPayments\Exceptions;

class WebhookException extends TzPaymentsException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $reason): self
    {
        return new self("The webhook could not be read: {$reason}", 400);
    }

    public static function unauthentic(string $reason): self
    {
        return new self("The webhook is not authentic: {$reason}", 403);
    }

    public static function unverifiable(string $gateway): self
    {
        return new self("The [{$gateway}] webhook could not be verified, so it was rejected.", 403);
    }

    public static function unknownGateway(string $gateway): self
    {
        return new self("No gateway named [{$gateway}] is configured.", 404);
    }
}
