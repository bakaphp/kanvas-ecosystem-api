<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Azul;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Connectors\Azul\Services\AzulCertificate;
use Kanvas\Exceptions\ValidationException;
use Tests\Connectors\Integration\Azul\Concerns\BuildsAzulCertificate;
use Tests\TestCase;

class AzulCertificateTest extends TestCase
{
    use BuildsAzulCertificate;

    private string $certPem;
    private string $keyPem;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->certPem, $this->keyPem] = $this->generateCertificate();
    }

    public function testCertificateIsSentAsCurlBlobsAndNeverAsFilePaths(): void
    {
        $options = $this->resolve(['cert' => $this->certPem, 'key' => $this->keyPem]);

        $this->assertSame($this->certPem, $options['curl'][CURLOPT_SSLCERT_BLOB]);
        $this->assertSame($this->keyPem, $options['curl'][CURLOPT_SSLKEY_BLOB]);

        $this->assertArrayNotHasKey('cert', $options);
        $this->assertArrayNotHasKey('ssl_key', $options);
    }

    public function testAcceptsBase64EncodedPem(): void
    {
        $options = $this->resolve([
            'cert' => base64_encode($this->certPem),
            'key' => base64_encode($this->keyPem),
        ]);

        $this->assertSame($this->certPem, $options['curl'][CURLOPT_SSLCERT_BLOB]);
        $this->assertSame($this->keyPem, $options['curl'][CURLOPT_SSLKEY_BLOB]);
    }

    public function testAcceptsAFilePathAndLoadsItAsABlob(): void
    {
        $certFile = $this->writeTempFile($this->certPem);
        $keyFile = $this->writeTempFile($this->keyPem);

        try {
            $options = $this->resolve(['cert' => $certFile, 'key' => $keyFile]);

            $this->assertSame($this->certPem, $options['curl'][CURLOPT_SSLCERT_BLOB]);
            $this->assertSame($this->keyPem, $options['curl'][CURLOPT_SSLKEY_BLOB]);
        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }
    }

    public function testLegacyPathKeysAreStillHonoured(): void
    {
        $certFile = $this->writeTempFile($this->certPem);
        $keyFile = $this->writeTempFile($this->keyPem);

        try {
            $options = $this->resolve(['cert_path' => $certFile, 'key_path' => $keyFile]);

            $this->assertSame($this->certPem, $options['curl'][CURLOPT_SSLCERT_BLOB]);
            $this->assertSame($this->keyPem, $options['curl'][CURLOPT_SSLKEY_BLOB]);
        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }
    }

    public function testResolvesFromEncryptedAppSettings(): void
    {
        $app = app(Apps::class);
        $previousCert = $app->get(ConfigurationEnum::AZUL_CERT->value);
        $previousKey = $app->get(ConfigurationEnum::AZUL_KEY->value);

        $app->setEncrypted(ConfigurationEnum::AZUL_CERT->value, $this->certPem);
        $app->setEncrypted(ConfigurationEnum::AZUL_KEY->value, $this->keyPem);

        try {
            $options = AzulCertificate::fromApp($app)->guzzleOptions();

            $this->assertSame($this->certPem, $options['curl'][CURLOPT_SSLCERT_BLOB]);
            $this->assertSame($this->keyPem, $options['curl'][CURLOPT_SSLKEY_BLOB]);
        } finally {
            $this->restore($app, ConfigurationEnum::AZUL_CERT, $previousCert);
            $this->restore($app, ConfigurationEnum::AZUL_KEY, $previousKey);
        }
    }

    public function testCaBundleBecomesCainfoBlob(): void
    {
        $options = $this->resolve([
            'cert' => $this->certPem,
            'key' => $this->keyPem,
            'ca' => $this->certPem,
        ]);

        $this->assertSame($this->certPem, $options['curl'][CURLOPT_CAINFO_BLOB]);
        $this->assertTrue($options['verify']);
    }

    public function testVerifySslFalseDisablesVerificationButKeepsClientCertificate(): void
    {
        $options = $this->resolve([
            'cert' => $this->certPem,
            'key' => $this->keyPem,
            'verify_ssl' => false,
        ]);

        $this->assertFalse($options['verify']);
        $this->assertArrayHasKey(CURLOPT_SSLCERT_BLOB, $options['curl']);
    }

    public function testKeyPasswordIsForwardedToCurl(): void
    {
        $options = $this->resolve([
            'cert' => $this->certPem,
            'key' => $this->keyPem,
            'key_password' => 'secret',
        ]);

        $this->assertSame('secret', $options['curl'][CURLOPT_KEYPASSWD]);
    }

    public function testMissingFileIsReportedWithItsPath(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('AZUL_CERT file not found at');

        $this->resolve(['cert' => '/does/not/exist.crt', 'key' => $this->keyPem]);
    }

    public function testMalformedContentIsNotMistakenForAPath(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('AZUL_CERT does not contain valid PEM material');

        $this->resolve(['cert' => "not a certificate\nnor a path\n", 'key' => $this->keyPem]);
    }

    public function testMissingCertificateIsReported(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('AZUL_CERT and AZUL_KEY are required');

        $this->resolve([]);
    }

    public function testDecodePemAcceptsRawAndBase64AndNormalizesTheTrailingNewline(): void
    {
        $this->assertSame($this->certPem, AzulCertificate::decodePem("  {$this->certPem}\n\n"));
        $this->assertSame($this->certPem, AzulCertificate::decodePem(base64_encode($this->certPem)));
    }

    public function testDecodePemRejectsAnythingThatIsNotPem(): void
    {
        $this->assertNull(AzulCertificate::decodePem('not a certificate'));
        $this->assertNull(AzulCertificate::decodePem(base64_encode('still not a certificate')));
        $this->assertNull(AzulCertificate::decodePem('storage/app/certs/azul/client.crt'));
    }

    private function resolve(array $config): array
    {
        $config += [
            'cert' => null,
            'key' => null,
            'ca' => null,
            'cert_path' => null,
            'key_path' => null,
            'ca_path' => null,
            'key_password' => null,
            'verify_ssl' => true,
        ];

        return AzulCertificate::fromApp($this->appWithoutSettings(), $config)->guzzleOptions();
    }

    private function restore(Apps $app, ConfigurationEnum $key, mixed $previous): void
    {
        if ($previous === null) {
            $app->del($key->value);

            return;
        }

        $app->set($key->value, $previous);
    }

    private function writeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'azul-cert-');
        file_put_contents($path, $contents);

        return $path;
    }
}
