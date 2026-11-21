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
use stdClass;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * ClickPesa collections: USSD push (TZS), card payments (USD) and hosted
 * checkout links (TZS or USD).
 *
 * ClickPesa has no sandbox: every call is live and moves real money.
 */
class ClickPesaGateway extends HttpGateway implements HandlesWebhooks
{
    protected const BASE_URL = 'https://api.clickpesa.com/third-parties';

    /** Tokens last one hour. Only one may be created every 3 minutes, so cache it for most of that hour. */
    protected const TOKEN_SECONDS = 3300;

    public const CHANNEL_USSD = 'ussd';

    public const CHANNEL_CARD = 'card';

    public const CHANNEL_CHECKOUT = 'checkout';

    protected function name(): string
    {
        return 'clickpesa';
    }

    protected function isLive(): bool
    {
        return true;
    }

    public function supports(Capability $capability): bool
    {
        return true;
    }

    public function charge(PaymentRequest $request): GatewayResponse
    {
        return match ($this->channel($request)) {
            self::CHANNEL_USSD => $this->ussdPush($request),
            self::CHANNEL_CARD => $this->cardPayment($request),
            default => $this->checkoutLink($request),
        };
    }

    public function status(StatusRequest $request): GatewayResponse
    {
        $response = $this->authorized(
            fn (PendingRequest $http): Response => $http->get($this->url('/payments/'.rawurlencode($request->merchantReference))),
            moneyMoves: false,
        );

        // Nothing has been paid against this reference yet, for example an unopened checkout link.
        if ($response->status() === 404) {
            return new GatewayResponse(PaymentStatus::Pending, message: 'ClickPesa has no payment for this reference yet.');
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body)) {
            throw GatewayException::requestFailed($this->name(), $response->status(), is_array($body) ? $body : [], $this->errorMessage($response));
        }

        $attempts = array_values(array_filter(array_is_list($body) ? $body : [$body], 'is_array'));
        $attempt = $this->decisiveAttempt($attempts);

        if ($attempt === null) {
            return new GatewayResponse(PaymentStatus::Pending, raw: $body);
        }

        $gatewayStatus = strtoupper((string) ($attempt['status'] ?? ''));

        return new GatewayResponse(
            status: $this->mapStatus($gatewayStatus),
            gatewayStatus: $gatewayStatus !== '' ? $gatewayStatus : null,
            gatewayReference: isset($attempt['id']) ? (string) $attempt['id'] : null,
            message: isset($attempt['message']) ? (string) $attempt['message'] : null,
            raw: $body,
            merchantReference: isset($attempt['orderReference']) ? (string) $attempt['orderReference'] : null,
        );
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $data = $request->all();
        $event = strtoupper((string) ($data['event'] ?? ''));
        $payment = $data['data'] ?? null;

        if ($event === '' || ! is_array($payment)) {
            throw WebhookException::malformed('event or data is missing.');
        }

        $verified = $this->verifyChecksum($request, $data);

        // Payout and deposit events are not payments this package started.
        if (! str_starts_with($event, 'PAYMENT')) {
            return new WebhookEvent(merchantReference: null, raw: $data);
        }

        $reference = $payment['orderReference'] ?? null;
        $gatewayStatus = strtoupper((string) ($payment['status'] ?? ''));

        $status = match (true) {
            $event === 'PAYMENT FAILED' => PaymentStatus::Failed,
            $gatewayStatus !== '' => $this->mapStatus($gatewayStatus),
            default => null,
        };

        return new WebhookEvent(
            merchantReference: is_string($reference) && $reference !== '' ? $reference : null,
            gatewayReference: isset($payment['id']) ? (string) $payment['id'] : null,
            status: $status,
            verified: $verified,
            gatewayStatus: $gatewayStatus !== '' ? $gatewayStatus : $event,
            message: isset($payment['message']) ? (string) $payment['message'] : null,
            raw: $data,
        );
    }

    public function acknowledgeWebhook(WebhookEvent $event): HttpResponse
    {
        return new JsonResponse(['received' => true]);
    }

    /**
     * Pick how to collect: an explicit "channel" option, otherwise USSD push for
     * TZS with a phone number, otherwise the hosted checkout page.
     */
    protected function channel(PaymentRequest $request): string
    {
        $channel = $request->options['channel'] ?? null;

        if ($channel === null) {
            return $request->currency === 'TZS' && $request->phone !== null ? self::CHANNEL_USSD : self::CHANNEL_CHECKOUT;
        }

        $channel = strtolower((string) $channel);

        if (! in_array($channel, [self::CHANNEL_USSD, self::CHANNEL_CARD, self::CHANNEL_CHECKOUT], true)) {
            throw InvalidRequestException::unsupportedOption($this->name(), 'channel', $channel);
        }

        return $channel;
    }

    protected function ussdPush(PaymentRequest $request): GatewayResponse
    {
        if ($request->currency !== 'TZS') {
            throw InvalidRequestException::unsupportedCurrency($this->name().' USSD push', $request->currency);
        }

        if ($request->phone === null) {
            throw InvalidRequestException::missingField($this->name(), 'phone');
        }

        $body = $this->post('/payments/initiate-ussd-push-request', [
            'amount' => Currency::toDecimal($request->amount, 'TZS'),
            'currency' => 'TZS',
            'orderReference' => $request->merchantReference,
            'phoneNumber' => $request->phone->international(),
        ]);

        $gatewayStatus = strtoupper((string) ($body['status'] ?? 'PROCESSING'));

        return new GatewayResponse(
            status: $this->mapStatus($gatewayStatus),
            gatewayStatus: $gatewayStatus,
            gatewayReference: isset($body['id']) ? (string) $body['id'] : null,
            raw: $body,
        );
    }

    protected function cardPayment(PaymentRequest $request): GatewayResponse
    {
        if ($request->currency !== 'USD') {
            throw InvalidRequestException::unsupportedCurrency($this->name().' card payments', $request->currency);
        }

        foreach (['customer_name' => $request->customerName, 'customer_email' => $request->customerEmail, 'phone' => $request->phone] as $field => $value) {
            if ($value === null || $value === '') {
                throw InvalidRequestException::missingField($this->name().' card payments', $field);
            }
        }

        $body = $this->post('/payments/initiate-card-payment', [
            'amount' => Currency::toDecimal($request->amount, 'USD'),
            'currency' => 'USD',
            'orderReference' => $request->merchantReference,
            'customer' => [
                'fullName' => $request->customerName,
                'email' => $request->customerEmail,
                'phoneNumber' => $request->phone?->international(),
            ],
        ]);

        return $this->redirectResponse($body, 'cardPaymentLink');
    }

    protected function checkoutLink(PaymentRequest $request): GatewayResponse
    {
        if (! in_array($request->currency, ['TZS', 'USD'], true)) {
            throw InvalidRequestException::unsupportedCurrency($this->name().' checkout', $request->currency);
        }

        $body = $this->post('/checkout-link/generate-checkout-url', array_filter([
            'totalPrice' => Currency::toDecimal($request->amount, $request->currency),
            'orderReference' => $request->merchantReference,
            'orderCurrency' => $request->currency,
            'customerName' => $request->customerName,
            'customerEmail' => $request->customerEmail,
            'customerPhone' => $request->phone?->international(),
            'description' => $request->description,
        ], fn ($value) => $value !== null));

        return $this->redirectResponse($body, 'checkoutLink');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function redirectResponse(array $body, string $linkField): GatewayResponse
    {
        $link = $body[$linkField] ?? null;

        if (! is_string($link) || $link === '') {
            throw GatewayException::requestFailed($this->name(), 200, $body, "the response has no {$linkField}");
        }

        return new GatewayResponse(PaymentStatus::Pending, redirectUrl: $link, raw: $body);
    }

    /**
     * Send a request that starts a payment, adding the checksum when one is configured.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function post(string $path, array $payload): array
    {
        if (($key = $this->optionalConfig('checksum_key')) !== null) {
            $payload['checksum'] = ClickPesaChecksum::sign($payload, $key);
        }

        $response = $this->authorized(
            fn (PendingRequest $http): Response => $http->post($this->url($path), $payload),
            moneyMoves: true,
        );

        $body = $this->body($response);

        if ($response->serverError()) {
            throw GatewayException::ambiguous($this->name(), $response->status(), $body);
        }

        if (! $response->successful()) {
            throw GatewayException::requestFailed($this->name(), $response->status(), $body, $this->errorMessage($response));
        }

        return $body;
    }

    /**
     * Verified only when a checksum key is configured and the checksum matches.
     * Without a key the webhook is unverified, and the package checks the status
     * with ClickPesa instead.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws WebhookException
     */
    protected function verifyChecksum(Request $request, array $data): bool
    {
        $key = $this->optionalConfig('checksum_key');

        if ($key === null) {
            return false;
        }

        $checksum = $data['checksum'] ?? null;

        if (! is_string($checksum) || $checksum === '') {
            throw WebhookException::unauthentic('the checksum is missing.');
        }

        // Decode as objects so the canonical JSON matches what ClickPesa signed.
        $payload = json_decode($request->getContent(), false);

        if (! $payload instanceof stdClass || ! ClickPesaChecksum::verify($payload, $key, $checksum)) {
            throw WebhookException::unauthentic('the checksum is invalid.');
        }

        return true;
    }

    /**
     * With several attempts on one reference, a success wins, then anything still
     * in progress, then the most recent attempt.
     *
     * @param  list<array<string, mixed>>  $attempts
     * @return array<string, mixed>|null
     */
    protected function decisiveAttempt(array $attempts): ?array
    {
        if ($attempts === []) {
            return null;
        }

        foreach ([PaymentStatus::Succeeded, PaymentStatus::Pending] as $wanted) {
            foreach ($attempts as $attempt) {
                if ($this->mapStatus(strtoupper((string) ($attempt['status'] ?? ''))) === $wanted) {
                    return $attempt;
                }
            }
        }

        usort($attempts, fn (array $a, array $b): int => strcmp((string) ($b['updatedAt'] ?? ''), (string) ($a['updatedAt'] ?? '')));

        return $attempts[0];
    }

    protected function mapStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'SUCCESS', 'SETTLED' => PaymentStatus::Succeeded,
            'FAILED' => PaymentStatus::Failed,
            'REFUNDED', 'REVERSED' => PaymentStatus::Reversed,
            // PROCESSING, PENDING and ON-HOLD are all still in progress.
            default => PaymentStatus::Pending,
        };
    }

    protected function errorMessage(Response $response): ?string
    {
        $body = $response->json();
        $message = is_array($body) ? ($body['message'] ?? null) : null;

        if ($response->status() === 429) {
            $retry = $response->header('Retry-After') ?: (is_array($body) ? ($body['fields']['retryAfterSeconds'] ?? null) : null);

            return 'rate limited by ClickPesa'.($retry ? ", retry after {$retry} seconds" : '');
        }

        return is_string($message) && $message !== '' ? $message : null;
    }

    /**
     * @param  Closure(PendingRequest): Response  $send
     */
    protected function authorized(Closure $send, bool $moneyMoves): Response
    {
        return $this->retryOnceIfUnauthorized(function (bool $freshToken) use ($send, $moneyMoves): Response {
            // ClickPesa's token already starts with "Bearer ".
            $request = $this->request()->withHeaders(['Authorization' => $this->token($freshToken)]);

            return $moneyMoves
                ? $this->sendPaymentRequest(fn (): Response => $send($request))
                : $this->sendSafeRequest(fn (): Response => $send($request));
        });
    }

    protected function token(bool $refresh = false): string
    {
        $clientId = $this->requireConfig('client_id');

        return $this->cachedToken($clientId, $refresh, function () use ($clientId): array {
            $response = $this->sendSafeRequest(fn (): Response => $this->request()
                ->withHeaders(['client-id' => $clientId, 'api-key' => $this->requireConfig('api_key')])
                ->post($this->url('/generate-token')));

            $token = $this->body($response)['token'] ?? null;

            if (! $response->successful() || ! is_string($token) || $token === '') {
                // The response body is left out on purpose: it is not needed and must never leak.
                throw GatewayException::requestFailed(
                    $this->name(),
                    $response->status(),
                    reason: $this->errorMessage($response) ?? 'could not get an access token',
                );
            }

            return [str_starts_with($token, 'Bearer ') ? $token : 'Bearer '.$token, self::TOKEN_SECONDS];
        });
    }

    protected function url(string $path): string
    {
        return rtrim($this->optionalConfig('base_url') ?? self::BASE_URL, '/').$path;
    }
}
