<?php

namespace JonasJohn\TzPayments\Drivers;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use JonasJohn\TzPayments\Contracts\HandlesWebhooks;
use JonasJohn\TzPayments\Data\GatewayResponse;
use JonasJohn\TzPayments\Data\PaymentRequest;
use JonasJohn\TzPayments\Data\StatusRequest;
use JonasJohn\TzPayments\Data\WebhookEvent;
use JonasJohn\TzPayments\Enums\Capability;
use JonasJohn\TzPayments\Enums\Operator;
use JonasJohn\TzPayments\Enums\PaymentStatus;
use JonasJohn\TzPayments\Exceptions\GatewayException;
use JonasJohn\TzPayments\Exceptions\InvalidRequestException;
use JonasJohn\TzPayments\Exceptions\UnsupportedOperationException;
use JonasJohn\TzPayments\Exceptions\WebhookException;
use JonasJohn\TzPayments\Support\Currency;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * AzamPay mobile money checkout (USSD push).
 *
 * Callbacks are verified with AzamPay's RSA signature (SHA-256, PKCS#1 v1.5)
 * and, optionally, a secret token in the callback URL.
 */
class AzamPayGateway extends HttpGateway implements HandlesWebhooks
{
    /** Live URLs are not in AzamPay's published schema; override them in config if they differ. */
    protected const URLS = [
        'sandbox' => [
            'auth' => 'https://authenticator-sandbox.azampay.co.tz',
            'api' => 'https://sandbox.azampay.co.tz',
        ],
        'live' => [
            'auth' => 'https://authenticator.azampay.co.tz',
            'api' => 'https://checkout.azampay.co.tz',
        ],
    ];

    public const MAX_AMOUNT = 5_000_000;

    public const PROVIDERS = ['Airtel', 'Tigo', 'Halopesa', 'Azampesa', 'Mpesa'];

    protected const FALLBACK_TOKEN_SECONDS = 3600;

    protected const PUBLIC_KEY_SECONDS = 86400;

    protected function name(): string
    {
        return 'azampay';
    }

    public function supports(Capability $capability): bool
    {
        return $capability === Capability::UssdPush;
    }

    public function charge(PaymentRequest $request): GatewayResponse
    {
        if ($request->currency !== 'TZS') {
            throw InvalidRequestException::unsupportedCurrency($this->name(), $request->currency);
        }

        if ($request->amount > self::MAX_AMOUNT) {
            throw InvalidRequestException::amountTooHigh($this->name(), self::MAX_AMOUNT);
        }

        if ($request->phone === null) {
            throw InvalidRequestException::missingField($this->name(), 'phone');
        }

        $payload = [
            'accountNumber' => $request->phone->international(),
            'amount' => Currency::toDecimal($request->amount, 'TZS'),
            'currency' => 'TZS',
            'externalId' => $request->merchantReference,
            'provider' => $this->provider($request),
        ];

        $response = $this->authorized(
            fn (PendingRequest $http): Response => $http->post($this->url('api', '/azampay/mno/checkout'), $payload),
            moneyMoves: true,
        );

        $body = $this->body($response);

        if ($response->successful() && ($body['success'] ?? false) === true) {
            return new GatewayResponse(
                status: PaymentStatus::Pending,
                gatewayReference: isset($body['transactionId']) ? (string) $body['transactionId'] : null,
                message: isset($body['message']) ? (string) $body['message'] : null,
                raw: $body,
            );
        }

        if ($response->serverError()) {
            throw GatewayException::ambiguous($this->name(), $response->status(), $body);
        }

        $reason = $body['message'] ?? $body['title'] ?? null;

        throw GatewayException::requestFailed($this->name(), $response->status(), $body, is_string($reason) ? $reason : null);
    }

    public function status(StatusRequest $request): GatewayResponse
    {
        throw UnsupportedOperationException::forCapability($this->name(), Capability::StatusLookup);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $data = $request->all();
        $utilityRef = $data['utilityref'] ?? null;

        if (! is_string($utilityRef) || $utilityRef === '') {
            throw WebhookException::malformed('utilityref is missing.');
        }

        $this->verifyCallback($request, $data);

        $status = strtolower((string) ($data['transactionstatus'] ?? ''));
        $amount = $data['amount'] ?? null;

        return new WebhookEvent(
            merchantReference: $utilityRef,
            gatewayReference: isset($data['externalreference']) ? (string) $data['externalreference'] : null,
            status: $this->mapStatus($status),
            verified: true,
            gatewayStatus: $status !== '' ? $status : null,
            amount: is_scalar($amount) ? Currency::fromDecimal((string) $amount, 'TZS') : null,
            message: isset($data['message']) ? (string) $data['message'] : null,
            raw: $data,
        );
    }

    public function acknowledgeWebhook(WebhookEvent $event): HttpResponse
    {
        return new JsonResponse(['success' => true]);
    }

    protected function mapStatus(string $status): ?PaymentStatus
    {
        return match ($status) {
            'success', 'successful', 'completed' => PaymentStatus::Succeeded,
            'failure', 'failed' => PaymentStatus::Failed,
            'cancelled', 'canceled' => PaymentStatus::Cancelled,
            default => null,
        };
    }

    protected function provider(PaymentRequest $request): string
    {
        $explicit = $request->options['provider'] ?? null;

        if ($explicit !== null) {
            foreach (self::PROVIDERS as $provider) {
                if (strcasecmp($provider, (string) $explicit) === 0) {
                    return $provider;
                }
            }

            throw InvalidRequestException::unsupportedOption($this->name(), 'provider', (string) $explicit);
        }

        return match ($request->operator) {
            Operator::Vodacom => 'Mpesa',
            Operator::Airtel => 'Airtel',
            Operator::Yas => 'Tigo',
            Operator::Halotel => 'Halopesa',
            default => throw InvalidRequestException::unsupportedOption(
                $this->name(),
                'provider',
                $request->operator?->walletName() ?? 'unknown operator',
            ),
        };
    }

    /**
     * Valid when every configured check passes: the callback token (if set)
     * and the RSA signature (if sent). At least one must be present.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws WebhookException
     */
    protected function verifyCallback(Request $request, array $data): void
    {
        $token = $this->optionalConfig('callback_token');
        $signature = $data['signature'] ?? null;
        $hasSignature = is_string($signature) && $signature !== '';

        if ($token === null && ! $hasSignature) {
            throw WebhookException::unauthentic('the callback has no signature and no callback token is configured.');
        }

        if ($token !== null && ! hash_equals($token, (string) $request->query('token', ''))) {
            throw WebhookException::unauthentic('the callback token is wrong.');
        }

        if ($hasSignature && ! $this->signatureIsValid($data, $signature)) {
            throw WebhookException::unauthentic('the signature is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function signatureIsValid(array $data, string $signature): bool
    {
        $signed = implode('', array_map(
            fn (string $field): string => (string) ($data[$field] ?? ''),
            ['utilityref', 'externalreference', 'transactionstatus', 'operator'],
        ));

        $binary = base64_decode($signature, true);

        if ($binary === false) {
            return false;
        }

        if ($this->verifyWith($this->publicKey(), $signed, $binary)) {
            return true;
        }

        // AzamPay advises re-fetching the key once before rejecting, in case it was rotated.
        return $this->verifyWith($this->publicKey(refresh: true), $signed, $binary);
    }

    protected function verifyWith(string $publicKeyPem, string $data, string $signature): bool
    {
        try {
            $key = openssl_pkey_get_public($publicKeyPem);

            return $key !== false && openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    protected function publicKey(bool $refresh = false): string
    {
        $cacheKey = $this->cacheKey('public-key', $this->requireConfig('client_id'));

        if (! $refresh) {
            $cached = $this->cache->get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $response = $this->authorized(
            fn (PendingRequest $http): Response => $http->get($this->url('api', '/azampay/v1/public-key'), ['format' => 'Pem']),
            moneyMoves: false,
        );

        $key = $this->body($response)['publicKey'] ?? null;

        if (! $response->successful() || ! is_string($key) || $key === '') {
            throw GatewayException::requestFailed($this->name(), $response->status(), reason: 'could not fetch the callback public key');
        }

        $this->cache->put($cacheKey, $key, self::PUBLIC_KEY_SECONDS);

        return $key;
    }

    /**
     * Send an authenticated request. A 401 means the request was refused before
     * anything happened, so it is safe to get a new token and try once more.
     *
     * @param  Closure(PendingRequest): Response  $send
     */
    protected function authorized(Closure $send, bool $moneyMoves): Response
    {
        $attempt = function (bool $freshToken) use ($send, $moneyMoves): Response {
            $request = $this->request()
                ->withToken($this->token($freshToken))
                ->withHeaders(['X-API-Key' => $this->requireConfig('api_key')]);

            return $moneyMoves
                ? $this->sendPaymentRequest(fn (): Response => $send($request))
                : $this->sendSafeRequest(fn (): Response => $send($request));
        };

        $response = $attempt(false);

        return $response->status() === 401 ? $attempt(true) : $response;
    }

    protected function token(bool $refresh = false): string
    {
        $clientId = $this->requireConfig('client_id');
        $cacheKey = $this->cacheKey('token', $clientId);

        if (! $refresh) {
            $cached = $this->cache->get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $credentials = [
            'appName' => $this->requireConfig('app_name'),
            'clientId' => $clientId,
            'clientSecret' => $this->requireConfig('client_secret'),
        ];

        $response = $this->sendSafeRequest(
            fn (): Response => $this->request()->post($this->url('auth', '/AppRegistration/GenerateToken'), $credentials),
        );

        $body = $this->body($response);
        $token = $body['data']['accessToken'] ?? null;

        if (! $response->successful() || ! is_string($token) || $token === '') {
            $reason = $body['message'] ?? 'could not get an access token';

            // The response body is left out on purpose: it is not needed and must never leak.
            throw GatewayException::requestFailed($this->name(), $response->status(), reason: is_string($reason) ? $reason : null);
        }

        $this->cache->put($cacheKey, $token, $this->tokenLifetime($body['data']['expire'] ?? null));

        return $token;
    }

    protected function tokenLifetime(mixed $expire): int
    {
        if (is_string($expire) && $expire !== '') {
            try {
                $seconds = (int) Carbon::now()->diffInSeconds(Carbon::parse($expire), false);

                // Renew a minute early so a token never expires mid-request.
                return max(60, $seconds - 60);
            } catch (Throwable) {
                // Unknown format: fall through to the safe default.
            }
        }

        return self::FALLBACK_TOKEN_SECONDS;
    }

    protected function url(string $type, string $path): string
    {
        $override = $this->optionalConfig($type === 'auth' ? 'auth_url' : 'base_url');
        $base = $override ?? self::URLS[$this->isLive() ? 'live' : 'sandbox'][$type];

        return rtrim($base, '/').$path;
    }
}
