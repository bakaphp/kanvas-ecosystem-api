<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

final class AzulCertificate
{
    private function __construct(
        private readonly string $certPem,
        private readonly string $keyPem,
        private readonly ?string $caPem,
        private readonly ?string $keyPassword,
        private readonly bool $verifySsl,
    ) {
    }

    public static function fromApp(AppInterface $app, array $config = []): self
    {
        $verify = $app->get(ConfigurationEnum::AZUL_VERIFY_SSL->value)
            ?? $config['verify_ssl']
            ?? true;

        $cert = self::read($app, $config, ConfigurationEnum::AZUL_CERT);
        $key = self::read($app, $config, ConfigurationEnum::AZUL_KEY);

        if ($cert === null || $key === null) {
            throw new ValidationException(
                'Azul configuration is missing: AZUL_CERT and AZUL_KEY are required (run azul:import-cert)'
            );
        }

        return new self(
            certPem: $cert,
            keyPem: $key,
            caPem: self::read($app, $config, ConfigurationEnum::AZUL_CA),
            keyPassword: $app->get(ConfigurationEnum::AZUL_KEY_PASSWORD->value) ?? $config['key_password'] ?? null,
            verifySsl: ! in_array($verify, [false, 0, '0', 'false'], true),
        );
    }

    public function guzzleOptions(): array
    {
        $curl = [
            CURLOPT_SSLCERT_BLOB => $this->certPem,
            CURLOPT_SSLKEY_BLOB => $this->keyPem,
        ];

        if (! empty($this->keyPassword)) {
            $curl[CURLOPT_KEYPASSWD] = $this->keyPassword;
        }

        if (! $this->verifySsl) {
            return ['verify' => false, 'curl' => $curl];
        }

        if ($this->caPem !== null) {
            $curl[CURLOPT_CAINFO_BLOB] = $this->caPem;
        }

        return ['verify' => true, 'curl' => $curl];
    }

    public static function decodePem(string $value): ?string
    {
        $value = trim($value);

        if (! str_contains($value, '-----BEGIN')) {
            $value = (string) base64_decode($value, true);
        }

        return str_contains($value, '-----BEGIN') ? rtrim($value) . "\n" : null;
    }

    private static function read(AppInterface $app, array $config, ConfigurationEnum $key): ?string
    {
        $configKey = strtolower(substr($key->value, strlen('AZUL_')));

        $value = $app->get($key->value)
            ?? $config[$configKey]
            ?? $app->get(ConfigurationEnum::from($key->value . '_PATH')->value)
            ?? $config[$configKey . '_path']
            ?? null;

        if (empty($value)) {
            return null;
        }

        return self::toPem(trim((string) $value), $key);
    }

    private static function toPem(string $value, ConfigurationEnum $key): string
    {
        $pem = self::decodePem($value);

        if ($pem !== null) {
            return $pem;
        }

        if (str_contains($value, "\n") || strlen($value) > 4096) {
            throw new ValidationException("Azul {$key->value} does not contain valid PEM material");
        }

        return self::readFile($value, $key);
    }

    private static function readFile(string $path, ConfigurationEnum $key): string
    {
        $resolved = self::resolvePath($path);

        if (! file_exists($resolved)) {
            throw new ValidationException("Azul {$key->value} file not found at: {$resolved}");
        }

        $pem = self::decodePem((string) file_get_contents($resolved));

        if ($pem === null) {
            throw new ValidationException("Azul {$key->value} file at {$resolved} does not contain PEM material");
        }

        return $pem;
    }

    private static function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/') || (strlen($path) > 1 && $path[1] === ':')) {
            return $path;
        }

        return base_path($path);
    }
}
