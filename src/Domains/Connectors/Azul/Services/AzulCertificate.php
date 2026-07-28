<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * Resolves the Azul mTLS material into Guzzle client options.
 *
 * AZUL_CERT / AZUL_KEY / AZUL_CA each hold either the PEM itself — raw, base64 or encrypted —
 * or a path to it. Whatever the source, the material is handed to curl as an in-memory blob,
 * so a deploy that wipes the container filesystem cannot take the certificate with it.
 *
 * Guzzle's own `cert` / `ssl_key` options map to CURLOPT_SSLCERT / CURLOPT_SSLKEY, which
 * accept a path only — passing PEM content there fails. The blob options must go through
 * the raw `curl` option array instead, which CurlFactory merges last.
 */
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

        $cert = self::read($app, $config, ConfigurationEnum::AZUL_CERT, ConfigurationEnum::AZUL_CERT_PATH, 'cert');
        $key = self::read($app, $config, ConfigurationEnum::AZUL_KEY, ConfigurationEnum::AZUL_KEY_PATH, 'key');

        if ($cert === null || $key === null) {
            throw new ValidationException(
                'Azul configuration is missing: AZUL_CERT and AZUL_KEY are required (run azul:import-cert)'
            );
        }

        return new self(
            certPem: $cert,
            keyPem: $key,
            caPem: self::read($app, $config, ConfigurationEnum::AZUL_CA, ConfigurationEnum::AZUL_CA_PATH, 'ca'),
            keyPassword: $app->get(ConfigurationEnum::AZUL_KEY_PASSWORD->value) ?? $config['key_password'] ?? null,
            // Settings come back as strings, so the stored form of "off" varies.
            verifySsl: ! in_array($verify, [false, 0, '0', 'false'], true),
        );
    }

    /**
     * Guzzle client options carrying the client certificate and the server-verification setting.
     */
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

    private static function read(
        AppInterface $app,
        array $config,
        ConfigurationEnum $key,
        ConfigurationEnum $legacyKey,
        string $configKey
    ): ?string {
        $value = $app->get($key->value)
            ?? $config[$configKey]
            ?? $app->get($legacyKey->value)
            ?? $config[$configKey . '_path']
            ?? null;

        if (empty($value)) {
            return null;
        }

        $pem = self::toPem(trim((string) $value), $key);

        // curl rejects a blob whose final line lacks the trailing newline.
        return rtrim($pem) . "\n";
    }

    /**
     * The value may be the PEM itself (raw, base64 or encrypted by azul:import-cert),
     * or a path to a file holding it.
     */
    private static function toPem(string $value, ConfigurationEnum $key): string
    {
        if (str_contains($value, '-----BEGIN')) {
            return $value;
        }

        try {
            $decrypted = Crypt::decryptString($value);

            if (str_contains($decrypted, '-----BEGIN')) {
                return $decrypted;
            }
        } catch (DecryptException) {
            // Not encrypted — fall through to the remaining forms.
        }

        $decoded = base64_decode($value, true);

        if ($decoded !== false && str_contains($decoded, '-----BEGIN')) {
            return $decoded;
        }

        // A path is a short single line; anything else was meant to be content and is malformed,
        // so say so rather than reporting a nonsensical missing file.
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

        $contents = (string) file_get_contents($resolved);

        if (! str_contains($contents, '-----BEGIN')) {
            throw new ValidationException("Azul {$key->value} file at {$resolved} does not contain PEM material");
        }

        return $contents;
    }

    private static function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/') || (strlen($path) > 1 && $path[1] === ':')) {
            return $path;
        }

        return base_path($path);
    }
}
