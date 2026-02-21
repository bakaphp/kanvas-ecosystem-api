<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Azul\Client;
use Kanvas\Exceptions\ValidationException;

class TestAzulMTLS extends Command
{
    protected $signature = 'azul:test-mtls';

    protected $description = 'Test Azul mTLS certificate connection';

    public function handle(): int
    {
        $this->info('Testing Azul mTLS connection...');

        try {
            $app = Apps::first();

            if (! $app) {
                $this->error('No app found in database');
                return 1;
            }

            $company = $app->company ?? $app->companies()->first();

            if (! $company) {
                $this->error('No company found');
                return 1;
            }

            $this->line("App: {$app->name}");
            $this->line("Company: {$company->name}");

            $this->info('Initializing Azul client...');
            $config = $this->readEnvConfig();
            $config['verify_ssl'] = false;
            $this->line("Debug - AUTH1: " . (! empty($config['auth1']) ? '(set)' : 'NOT SET'));
            $this->line("Debug - AUTH2: " . (! empty($config['auth2']) ? '(set)' : 'NOT SET'));
            $this->line("Debug - Cert path: " . ($config['cert_path'] ?? 'NOT SET'));
            $this->line("Debug - Key path: " . ($config['key_path'] ?? 'NOT SET'));
            $this->newLine();

            $client = new Client($app, $company, $config);
            $this->line('✓ Client initialized successfully');

            // Test connection
            $this->info('Testing mTLS connection to pruebas.azul.com.do...');
            $response = $client->getGuzzleClient()->get('https://pruebas.azul.com.do');

            $this->line('✓ Connection successful!');
            // $this->info("Status: {$response->getStatusCode()}");

            $this->newLine();
            $this->comment('=== Response ===');
            $body = $response->getBody()->getContents();
            $this->line(substr($body, 0, 500) . (strlen($body) > 500 ? '...' : ''));

            return 0;
        } catch (ValidationException $e) {
            $this->error("Configuration Error: {$e->getMessage()}");
            return 1;
        } catch (ConnectException $e) {
            $this->error("Connection Failed (possible IP whitelist issue): {$e->getMessage()}");
            return 1;
        } catch (RequestException $e) {
            $msg = $e->getMessage();
            if ($e->getResponse()) {
                $msg .= " | Status: {$e->getResponse()->getStatusCode()}";
                $msg .= " | Body: " . $e->getResponse()->getBody();
            }
            $this->error("Request Failed: $msg");
            return 1;
        } catch (Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * Read Azul configuration from .env file
     */
    private function readEnvConfig(): array
    {
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            return [];
        }

        $config = [];
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            if (str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, '\'"');

            // Map AZUL_* env vars to config array keys
            match ($key) {
                'AZUL_AUTH1' => $config['auth1'] = $value,
                'AZUL_AUTH2' => $config['auth2'] = $value,
                'AZUL_BASE_URL' => $config['base_url'] = $value,
                'AZUL_CERT_PATH' => $config['cert_path'] = $value,
                'AZUL_KEY_PATH' => $config['key_path'] = $value,
                'AZUL_CA_PATH' => $config['ca_path'] = $value,
                'AZUL_VERIFY_SSL' => $config['verify_ssl'] = $value === 'false' ? false : ($value === 'true' ? true : $value),
                default => null,
            };
        }

        return $config;
    }
}
