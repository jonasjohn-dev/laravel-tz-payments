<?php

namespace JonasJohn\TzPayments\Console;

use Illuminate\Console\Command;
use JonasJohn\TzPayments\Drivers\PesapalGateway;
use JonasJohn\TzPayments\Exceptions\TzPaymentsException;
use JonasJohn\TzPayments\TzPaymentsManager;

class PesapalRegisterIpnCommand extends Command
{
    protected $signature = 'tz-payments:pesapal-register-ipn
        {url? : The URL Pesapal should notify (default: this app\'s Pesapal webhook route)}
        {--method=POST : How Pesapal calls the URL: GET or POST}
        {--list : Only list the IPN URLs already registered}';

    protected $description = 'Register your IPN URL with Pesapal and get the IPN id for PESAPAL_IPN_ID';

    public function handle(TzPaymentsManager $manager): int
    {
        $gateway = $manager->gateway('pesapal');

        if (! $gateway instanceof PesapalGateway) {
            $this->error('The "pesapal" gateway is not the Pesapal driver.');

            return self::FAILURE;
        }

        try {
            return $this->option('list') ? $this->list($gateway) : $this->register($gateway);
        } catch (TzPaymentsException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function register(PesapalGateway $gateway): int
    {
        $method = $this->option('method');
        $method = is_string($method) ? strtoupper($method) : '';

        if (! in_array($method, ['GET', 'POST'], true)) {
            $this->error('The --method option must be GET or POST.');

            return self::FAILURE;
        }

        $url = $this->argument('url');
        $url = is_string($url) && $url !== '' ? $url : route('tz-payments.webhook', 'pesapal');

        if (! str_starts_with($url, 'https://')) {
            $this->warn("Pesapal must be able to reach {$url} from the internet. Use a public https URL in production.");
        }

        $result = $gateway->registerIpn($url, $method);

        $this->info('IPN URL registered with Pesapal.');
        $this->newLine();
        $this->line('Add this line to your .env file:');
        $this->newLine();
        $this->line('    PESAPAL_IPN_ID='.$result['ipn_id']);

        return self::SUCCESS;
    }

    private function list(PesapalGateway $gateway): int
    {
        $ipns = $gateway->registeredIpns();

        if ($ipns === []) {
            $this->info('No IPN URLs are registered yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['IPN id', 'URL', 'Created'],
            array_map(fn (array $ipn): array => [
                (string) ($ipn['ipn_id'] ?? ''),
                (string) ($ipn['url'] ?? ''),
                (string) ($ipn['created_date'] ?? ''),
            ], $ipns),
        );

        return self::SUCCESS;
    }
}
