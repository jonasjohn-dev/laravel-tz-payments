<?php

namespace JonasJohn\TzPayments;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use JonasJohn\TzPayments\Console\PesapalRegisterIpnCommand;
use JonasJohn\TzPayments\Support\ModelResolver;
use JonasJohn\TzPayments\Support\PaymentUpdater;

class TzPaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tz-payments.php', 'tz-payments');

        $this->app->singleton(TzPaymentsManager::class, fn (Application $app) => new TzPaymentsManager($app));
        $this->app->alias(TzPaymentsManager::class, 'tz-payments');

        // Not a singleton: it must always use the current event dispatcher and
        // cache, so Event::fake() keeps working after the first payment.
        $this->app->bind(PaymentProcessor::class, function (Application $app): PaymentProcessor {
            $events = $app->make('events');
            $config = $app->make('config');

            return new PaymentProcessor(
                updater: new PaymentUpdater($events),
                events: $events,
                cache: $app->make('cache')->store(),
                model: ModelResolver::paymentModel(),
                logWebhooks: (bool) $config->get('tz-payments.webhooks.log', true),
                redact: array_values(array_filter((array) $config->get('tz-payments.webhooks.redact', []), 'is_string')),
            );
        });
    }

    public function boot(): void
    {
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([PesapalRegisterIpnCommand::class]);

            $this->publishes([
                __DIR__.'/../config/tz-payments.php' => config_path('tz-payments.php'),
            ], 'tz-payments-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_tz_payments_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_tz_payments_table.php'),
            ], 'tz-payments-migrations');
        }
    }

    private function registerRoutes(): void
    {
        if (! config('tz-payments.webhooks.enabled', true)) {
            return;
        }

        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::prefix(config('tz-payments.webhooks.prefix', 'tz-payments/webhooks'))
            ->middleware(config('tz-payments.webhooks.middleware', []))
            ->group(__DIR__.'/../routes/webhooks.php');
    }
}
