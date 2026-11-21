<?php

namespace JonasJohn\TzPayments\Drivers;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JonasJohn\TzPayments\Contracts\HandlesWebhooks;
use JonasJohn\TzPayments\Data\GatewayResponse;
use JonasJohn\TzPayments\Data\PaymentRequest;
use JonasJohn\TzPayments\Data\StatusRequest;
use JonasJohn\TzPayments\Data\WebhookEvent;
use JonasJohn\TzPayments\Enums\Capability;
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Exceptions\GatewayException;
use JonasJohn\TzPayments\Exceptions\InvalidRequestException;
use JonasJohn\TzPayments\Exceptions\WebhookException;
use JonasJohn\TzPayments\Support\Currency;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Pesapal API 3.0 hosted checkout. The customer pays on Pesapal's page by
 * mobile money, card or bank.
 *
 * Pesapal's IPN carries no status and no signature, so every IPN is confirmed
 * with GetTransactionStatus before anything changes.
 */
class PesapalGateway extends HttpGateway implements HandlesWebhooks
{
    protected const URLS = [
        'sandbox' => 'https://cybqa.pesapal.com/pesapalv3/api',
        'live' => 'https://pay.pesapal.com/v3/api',
    ];

    /** Tokens last 5 minutes; renew a little early. */
    protected const FALLBACK_TOKEN_SECONDS = 240;

    protected const MAX_DESCRIPTION = 100;

    protected function name(): string
    {
        return 'pesapal';
    }

    public function supports(Capability $capability): bool
    {
        return $capability === Capability::HostedCheckout || $capability === Capability::StatusLookup;
    }

    public function charge(PaymentRequest $request): GatewayResponse
    {
        if ($request->phone === null && $request->customerEmail === null) {
            throw InvalidRequestException::missingField($this->name(), 'phone or customer_email');
        }

        $callbackUrl = $request->callbackUrl ?? $this->optionalConfig('callback_url')
            ?? throw InvalidRequestException::missingField($this->name(), 'callback_url');

        $payload = array_filter([
            'id' => $request->merchantReference,
            'currency' => $request->currency,
            'amount' => $this->amount($request->amount, $request->currency),
            'description' => mb_substr($request->description ?? "Payment {$request->reference}", 0, self::MAX_DESCRIPTION),
            'callback_url' => $callbackUrl,
            'cancellation_url' => $request->options['cancellation_url'] ?? null,
            'redirect_mode' => $request->options['redirect_mode'] ?? null,
            'branch' => $request->options['branch'] ?? null,
            'notification_id' => $this->requireConfig('ipn_id'),
            'billing_address' => $this->billingAddress($request),
        ], fn ($value) => $value !== null);

        $response = $this->authorized(
            fn (PendingRequest $http): Response => $http->post($this->url('/Transactions/SubmitOrderRequest'), $payload),
            moneyMoves: true,
        );

        $body = $this->body($response);

        if ($response->serverError()) {
            throw GatewayException::ambiguous($this->name(), $response->status(), $body);
        }

        $trackingId = $body['order_tracking_id'] ?? null;
        $redirectUrl = $body['redirect_url'] ?? null;

        if (! $response->successful() || $this->hasError($body) || ! is_string($trackingId) || ! is_string($redirectUrl)) {
            throw GatewayException::requestFailed($this->name(), $response->status(), $body, $this->errorMessage($body));
        }

        return new GatewayResponse(
            status: PaymentStatus::Pending,
            gatewayReference: $trackingId,
            redirectUrl: $redirectUrl,
            message: isset($body['message']) ? (string) $body['message'] : null,
            raw: $body,
        );
    }

    public function status(StatusRequest $request): GatewayResponse
    {
        // Pesapal can only look an order up by its tracking id. Without one
        // (for example after a timeout) the order is treated as still pending.
        if ($request->gatewayReference === null) {
            return new GatewayResponse(PaymentStatus::Pending, message: 'No Pesapal order tracking id is known yet.');
        }

        $response = $this->authorized(
            fn (PendingRequest $http): Response => $http->get($this->url('/Transactions/GetTransactionStatus'), [
                'orderTrackingId' => $request->gatewayReference,
            ]),
            moneyMoves: false,
        );

        $body = $this->body($response);

        if (! $response->successful() || ! array_key_exists('status_code', $body)) {
            throw GatewayException::requestFailed($this->name(), $response->status(), $body, $this->errorMessage($body));
        }

        $currency = isset($body['currency']) ? strtoupper((string) $body['currency']) : null;
        $amount = $body['amount'] ?? null;

        return new GatewayResponse(
            status: $this->mapStatus($body['status_code'], $body['payment_status_description'] ?? null),
            gatewayStatus: isset($body['payment_status_description']) ? (string) $body['payment_status_description'] : null,
            gatewayReference: $request->gatewayReference,
            message: isset($body['description']) ? (string) $body['description'] : null,
            raw: $body,
            merchantReference: isset($body['merchant_reference']) ? (string) $body['merchant_reference'] : null,
            amount: $currency !== null && is_scalar($amount) ? Currency::fromDecimal((string) $amount, $currency) : null,
            currency: $currency,
        );
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $trackingId = $request->input('OrderTrackingId');
        $merchantReference = $request->input('OrderMerchantReference');

        if (! is_string($trackingId) || $trackingId === '') {
            throw WebhookException::malformed('OrderTrackingId is missing.');
        }

        // Not verified: the IPN has no signature and no status. The package
        // confirms it with GetTransactionStatus.
        return new WebhookEvent(
            merchantReference: is_string($merchantReference) && $merchantReference !== '' ? $merchantReference : null,
            gatewayReference: $trackingId,
            raw: $request->all(),
        );
    }

    /**
     * Pesapal expects its own notification echoed back with status 200.
     */
    public function acknowledgeWebhook(WebhookEvent $event): HttpResponse
    {
        return new JsonResponse([
            'orderNotificationType' => (string) ($event->raw['OrderNotificationType'] ?? 'IPNCHANGE'),
            'orderTrackingId' => $event->gatewayReference,
            'orderMerchantReference' => $event->merchantReference,
            'status' => 200,
        ]);
    }

    /**
     * Register a URL that Pesapal will notify. Returns Pesapal's answer,
     * including the "ipn_id" to put in PESAPAL_IPN_ID.
     *
     * @return array<string, mixed>
     */
    public function registerIpn(string $url, string $method = 'POST'): array
    {
        $response = $this->authorized(
            fn (PendingRequest $http): Response => $http->post($this->url('/URLSetup/RegisterIPN'), [
                'url' => $url,
                'ipn_notification_type' => strtoupper($method),
            ]),
            moneyMoves: false,
        );

        $body = $this->body($response);

        if (! $response->successful() || $this->hasError($body) || ! isset($body['ipn_id'])) {
            throw GatewayException::requestFailed($this->name(), $response->status(), $body, $this->errorMessage($body));
        }

        return $body;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function registeredIpns(): array
    {
        $response = $this->authorized(
            fn (PendingRequest $http): Response => $http->get($this->url('/URLSetup/GetIpnList')),
            moneyMoves: false,
        );

        $body = $response->json();

        if (! $response->successful() || ! is_array($body)) {
            throw GatewayException::requestFailed($this->name(), $response->status(), reason: 'could not list IPN URLs');
        }

        return array_values(array_filter($body, 'is_array'));
    }

    protected function mapStatus(mixed $code, mixed $description): PaymentStatus
    {
        return match ((int) $code) {
            1 => PaymentStatus::Succeeded,
            2 => PaymentStatus::Failed,
            3 => PaymentStatus::Reversed,
            // 0 = INVALID: nobody has paid yet. That is pending, never failed.
            default => strtoupper((string) $description) === 'COMPLETED' ? PaymentStatus::Succeeded : PaymentStatus::Pending,
        };
    }

    protected function amount(int $amount, string $currency): int|float
    {
        $decimal = Currency::toDecimal($amount, $currency);

        return str_contains($decimal, '.') ? (float) $decimal : (int) $decimal;
    }

    /**
     * @return array<string, string>
     */
    protected function billingAddress(PaymentRequest $request): array
    {
        $names = preg_split('/\s+/', trim((string) $request->customerName), 2) ?: [];

        return array_filter([
            'phone_number' => $request->phone?->local(),
            'email_address' => $request->customerEmail,
            'country_code' => 'TZ',
            'first_name' => $names[0] ?? null,
            'last_name' => $names[1] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function hasError(array $body): bool
    {
        $error = $body['error'] ?? null;

        return is_array($error) ? array_filter($error) !== [] : ! empty($error);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function errorMessage(array $body): ?string
    {
        $error = $body['error'] ?? null;
        $message = is_array($error) ? ($error['message'] ?? $error['code'] ?? null) : ($body['message'] ?? null);

        return is_string($message) && $message !== '' ? $message : null;
    }

    /**
     * @param  Closure(PendingRequest): Response  $send
     */
    protected function authorized(Closure $send, bool $moneyMoves): Response
    {
        return $this->retryOnceIfUnauthorized(function (bool $freshToken) use ($send, $moneyMoves): Response {
            $request = $this->request()->withToken($this->token($freshToken));

            return $moneyMoves
                ? $this->sendPaymentRequest(fn (): Response => $send($request))
                : $this->sendSafeRequest(fn (): Response => $send($request));
        });
    }

    protected function token(bool $refresh = false): string
    {
        $consumerKey = $this->requireConfig('consumer_key');

        return $this->cachedToken($consumerKey, $refresh, function () use ($consumerKey): array {
            $credentials = [
                'consumer_key' => $consumerKey,
                'consumer_secret' => $this->requireConfig('consumer_secret'),
            ];

            $response = $this->sendSafeRequest(
                fn (): Response => $this->request()->post($this->url('/Auth/RequestToken'), $credentials),
            );

            $body = $this->body($response);
            $token = $body['token'] ?? null;

            if (! $response->successful() || $this->hasError($body) || ! is_string($token) || $token === '') {
                // The response body is left out on purpose: it is not needed and must never leak.
                throw GatewayException::requestFailed(
                    $this->name(),
                    $response->status(),
                    reason: $this->errorMessage($body) ?? 'could not get an access token',
                );
            }

            return [$token, $this->secondsUntil($body['expiryDate'] ?? null, self::FALLBACK_TOKEN_SECONDS, margin: 30)];
        });
    }

    protected function url(string $path): string
    {
        $base = $this->optionalConfig('base_url') ?? self::URLS[$this->isLive() ? 'live' : 'sandbox'];

        return rtrim($base, '/').$path;
    }
}
