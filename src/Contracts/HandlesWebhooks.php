<?php

namespace JonasJohn\TzPayments\Contracts;

use Illuminate\Http\Request;
use JonasJohn\TzPayments\Data\WebhookEvent;
use JonasJohn\TzPayments\Exceptions\WebhookException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Implemented by drivers whose gateway sends webhooks.
 */
interface HandlesWebhooks
{
    /**
     * Read the webhook. Mark the event verified only when its authenticity was
     * proven, for example by a valid signature or checksum.
     *
     * @throws WebhookException When the webhook is unreadable or its signature is invalid.
     */
    public function parseWebhook(Request $request): WebhookEvent;

    /**
     * The reply the gateway expects once the webhook has been handled.
     */
    public function acknowledgeWebhook(WebhookEvent $event): Response;
}
