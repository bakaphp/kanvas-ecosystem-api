<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Azul\Concerns;

use Kanvas\Apps\Models\Apps;

trait BuildsAzulCertificate
{
    /**
     * @return array{0: string, 1: string} cert PEM, key PEM
     */
    protected function generateCertificate(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new(['commonName' => 'azul-test'], $key);
        $x509 = openssl_csr_sign($csr, null, $key, 365);

        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($key, $keyPem);

        return [rtrim($certPem) . "\n", rtrim($keyPem) . "\n"];
    }

    /**
     * The developer's own app row may already carry AZUL_* settings, which take precedence
     * over the $config array and would make assertions environment dependent.
     */
    protected function appWithoutSettings(): Apps
    {
        return new class () extends Apps {
            public function get(string $key, mixed $defaultValue = null): mixed
            {
                return $defaultValue;
            }
        };
    }
}
