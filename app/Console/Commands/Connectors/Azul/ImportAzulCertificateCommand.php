<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Azul;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;

class ImportAzulCertificateCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'azul:import-cert
                            {app_id : App id}
                            {--cert= : Path to the client certificate (PEM)}
                            {--key= : Path to the private key (PEM)}
                            {--ca= : Path to the CA bundle (PEM, optional)}';

    protected $description = 'Store the Azul mTLS certificate and key in app settings so they survive deploys';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $certPath = (string) $this->option('cert');
        $keyPath = (string) $this->option('key');

        if (empty($certPath) || empty($keyPath)) {
            $this->error('--cert and --key are both required.');

            return self::FAILURE;
        }

        $cert = $this->readPem($certPath, 'CERTIFICATE');
        $key = $this->readPem($keyPath, 'PRIVATE KEY');

        if ($cert === null || $key === null) {
            return self::FAILURE;
        }

        if (! openssl_x509_check_private_key($cert, $key)) {
            $this->error('The certificate and the private key do not match.');

            return self::FAILURE;
        }

        $app->setEncrypted(ConfigurationEnum::AZUL_CERT->value, $cert);
        $app->setEncrypted(ConfigurationEnum::AZUL_KEY->value, $key);

        if ($caPath = (string) $this->option('ca')) {
            $ca = $this->readPem($caPath, 'CERTIFICATE');

            if ($ca === null) {
                return self::FAILURE;
            }

            $app->setEncrypted(ConfigurationEnum::AZUL_CA->value, $ca);
        }

        $info = openssl_x509_parse($cert);
        $this->info("Stored Azul certificate for app {$app->getId()} ({$app->name}).");
        $this->line('  subject : ' . ($info['subject']['CN'] ?? 'unknown'));
        $this->line('  issuer  : ' . ($info['issuer']['CN'] ?? 'unknown'));
        $this->line('  expires : ' . date('Y-m-d', $info['validTo_time_t']));

        return self::SUCCESS;
    }

    private function readPem(string $path, string $expectedMarker): ?string
    {
        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return null;
        }

        $contents = trim((string) file_get_contents($path));

        if (! str_contains($contents, '-----BEGIN') || ! str_contains($contents, $expectedMarker)) {
            $this->error("{$path} does not look like a PEM {$expectedMarker}. Convert a .pfx first: openssl pkcs12 -in azul.pfx -clcerts -nokeys -out client.crt");

            return null;
        }

        return $contents . "\n";
    }
}
