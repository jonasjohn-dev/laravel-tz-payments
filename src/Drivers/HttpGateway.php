<?php

namespace JonasJohn\TzPayments\Drivers;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use JonasJohn\TzPayments\Contracts\Gateway;
use JonasJohn\TzPayments\Exceptions\GatewayException;
use JonasJohn\TzPayments\Exceptions\InvalidRequestException;

/**
 * Shared plumbing for drivers that talk to a gateway over HTTP.
 *
 * Requests are never retried automatically: a retried charge could take the
 * customer's money twice.
 */
abstract class HttpGateway implements Gateway
{
    /**
     * @param  array<string, mixed>  $config  This gateway's config, plus the global "timeout".
     */
    public function __construct(
        protected readonly array $config,
        protected readonly HttpFactory $http,
        protected readonly Cache $cache,
    ) {}

    /** The gateway's name, used in error messages and cache keys. */
    abstract protected function name(): string;

    protected function isLive(): bool
    {
        return ($this->config['environment'] ?? 'sandbox') === 'live';
    }

    /**
     * @throws InvalidRequestException
     */
    protected function requireConfig(string $key): string
    {
        $value = $this->config[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw InvalidRequestException::missingConfig($this->name(), $key);
        }

        return $value;
    }

    protected function optionalConfig(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function request(): PendingRequest
    {
        return $this->http->acceptJson()->asJson()->timeout((int) ($this->config['timeout'] ?? 30));
    }

    /**
     * Send a request that may start a payment. A connection problem leaves the
     * outcome unknown, so the payment must stay pending.
     *
     * @param  Closure(): Response  $send
     *
     * @throws GatewayException
     */
    protected function sendPaymentRequest(Closure $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $e) {
            throw GatewayException::timeout($this->name(), $e);
        }
    }

    /**
     * Send a request that cannot move money, such as getting a token or a key.
     *
     * @param  Closure(): Response  $send
     *
     * @throws GatewayException
     */
    protected function sendSafeRequest(Closure $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $e) {
            throw GatewayException::unreachable($this->name(), $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * A cache key unique to this gateway, environment and account.
     */
    protected function cacheKey(string $suffix, string $account): string
    {
        $environment = $this->isLive() ? 'live' : 'sandbox';

        return 'tz-payments:'.$this->name().':'.$environment.':'.sha1($account).':'.$suffix;
    }
}
