<?php

namespace JonasJohn\TzPayments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JonasJohn\TzPayments\Exceptions\GatewayException;
use JonasJohn\TzPayments\Exceptions\WebhookException;
use JonasJohn\TzPayments\TzPaymentsManager;
use Symfony\Component\HttpFoundation\Response;

class WebhookController
{
    public function __invoke(Request $request, string $gateway, TzPaymentsManager $manager): Response
    {
        try {
            return $manager->handleWebhook($gateway, $request);
        } catch (WebhookException $e) {
            return new JsonResponse(['message' => $e->getMessage()], $e->status);
        } catch (GatewayException $e) {
            // The status lookup failed. A 5xx makes the gateway retry the webhook later.
            report($e);

            return new JsonResponse(['message' => 'The payment status could not be confirmed. Please retry.'], 503);
        }
    }
}
