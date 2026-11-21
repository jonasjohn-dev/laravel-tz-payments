<?php

namespace JonasJohn\TzPayments\Facades;

use Illuminate\Support\Facades\Facade;
use JonasJohn\TzPayments\Testing\FakeGateway;
use JonasJohn\TzPayments\TzPaymentsManager;

/**
 * @method static \JonasJohn\TzPayments\Data\PaymentResponse charge(array<string, mixed>|\JonasJohn\TzPayments\Data\PaymentRequest $payment, string|null $gateway = null)
 * @method static \JonasJohn\TzPayments\Data\PaymentResponse status(string $reference)
 * @method static \JonasJohn\TzPayments\Contracts\Gateway gateway(string|null $name = null)
 * @method static bool hasGateway(string $name)
 * @method static TzPaymentsManager extend(string $driver, \Closure $callback)
 * @method static bool isFaking()
 * @method static void assertCharged(callable|null $callback = null)
 * @method static void assertNotCharged(callable $callback)
 * @method static void assertNothingCharged()
 * @method static void assertChargedTimes(int $times)
 *
 * @see TzPaymentsManager
 */
class TzPay extends Facade
{
    /**
     * Replace every gateway with a fake. Payments are still saved and events still fire.
     */
    public static function fake(): FakeGateway
    {
        return static::getFacadeRoot()->fake();
    }

    protected static function getFacadeAccessor(): string
    {
        return TzPaymentsManager::class;
    }
}
