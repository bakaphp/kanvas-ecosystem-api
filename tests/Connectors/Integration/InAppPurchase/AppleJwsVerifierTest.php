<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\InAppPurchase;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Exceptions;
use Imdhemy\AppStore\Jws\AppStoreJwsVerifier;
use Imdhemy\AppStore\Jws\JsonWebSignature;
use Imdhemy\AppStore\Jws\JwsVerifier;
use Imdhemy\AppStore\Jws\Parser;
use Kanvas\Connectors\InAppPurchase\Services\AppleJwsVerifier;
use Lcobucci\JWT\Validation\ConstraintViolation;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * The chain here is not Apple's, so the library's chain check is faked to pass and only the purpose OID
 * and validity checks AppleJwsVerifier adds on top are under test.
 */
class AppleJwsVerifierTest extends TestCase
{
    private const string LEAF_EXTENSIONS = 'leaf_ext';
    private const string INTERMEDIATE_EXTENSIONS = 'intermediate_ext';
    private const string ROOT_EXTENSIONS = 'root_ext';
    private const string PLAIN_LEAF_EXTENSIONS = 'plain_leaf_ext';
    private const string PLAIN_CA_EXTENSIONS = 'plain_ca_ext';

    private string $opensslConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->opensslConfig = tempnam(sys_get_temp_dir(), 'apple-jws-') . '.cnf';
        file_put_contents($this->opensslConfig, implode("\n", [
            '[ req ]',
            'distinguished_name = dn',
            '[ dn ]',
            '[ ' . self::LEAF_EXTENSIONS . ' ]',
            'basicConstraints = CA:FALSE',
            AppleJwsVerifier::LEAF_OID . ' = ASN1:NULL',
            '[ ' . self::INTERMEDIATE_EXTENSIONS . ' ]',
            'basicConstraints = CA:TRUE',
            AppleJwsVerifier::INTERMEDIATE_OID . ' = ASN1:NULL',
            '[ ' . self::ROOT_EXTENSIONS . ' ]',
            'basicConstraints = CA:TRUE',
            '[ ' . self::PLAIN_LEAF_EXTENSIONS . ' ]',
            'basicConstraints = CA:FALSE',
            '[ ' . self::PLAIN_CA_EXTENSIONS . ' ]',
            'basicConstraints = CA:TRUE',
            '',
        ]));

        $this->chainVerifierReturns(true);
    }

    protected function tearDown(): void
    {
        @unlink($this->opensslConfig);

        parent::tearDown();
    }

    public function testAcceptsChainWithAppleOidsValidAtSignedDate(): void
    {
        $this->assertTrue($this->verify($this->chain(), now()->getTimestampMs()));
    }

    public function testFallsBackToNowWithoutSignedDate(): void
    {
        $this->assertTrue($this->verify($this->chain(), null));
    }

    public function testRejectsLeafWithoutReceiptSigningOid(): void
    {
        $this->assertFalse($this->verify(
            $this->chain(leafExtensions: self::PLAIN_LEAF_EXTENSIONS),
            now()->getTimestampMs()
        ));
    }

    public function testRejectsIntermediateWithoutWwdrOid(): void
    {
        $this->assertFalse($this->verify(
            $this->chain(intermediateExtensions: self::PLAIN_CA_EXTENSIONS),
            now()->getTimestampMs()
        ));
    }

    public function testRejectsLeafExpiredAtSignedDate(): void
    {
        $this->assertFalse($this->verify($this->chain(leafDays: 1), now()->addDays(3)->getTimestampMs()));
    }

    public function testRejectsWhenTheLibraryChainCheckFails(): void
    {
        $this->chainVerifierReturns(false);

        $this->assertFalse($this->verify($this->chain(), now()->getTimestampMs()));
    }

    public function testSignatureMismatchIsRejectedWithoutReporting(): void
    {
        Exceptions::fake();
        $this->chainVerifierThrows(new ConstraintViolation('Token signature mismatch'));

        $this->assertFalse($this->verify($this->chain(), now()->getTimestampMs()));
        Exceptions::assertNothingReported();
    }

    public function testUnexpectedFailureIsReportedAndRejected(): void
    {
        Exceptions::fake();
        $this->chainVerifierThrows(new RuntimeException('Unparseable x5c chain'));

        $this->assertFalse($this->verify($this->chain(), now()->getTimestampMs()));
        Exceptions::assertReported(
            fn (RuntimeException $e): bool => $e->getMessage() === 'Unparseable x5c chain'
        );
    }

    /**
     * @param list<string> $x5c leaf first
     */
    private function verify(array $x5c, ?int $signedDateMs): bool
    {
        $claims = $signedDateMs === null ? ['transactionId' => '1'] : ['signedDate' => $signedDateMs];
        $header = JWT::urlsafeB64Encode((string) json_encode(['alg' => 'ES256', 'x5c' => $x5c]));
        $payload = JWT::urlsafeB64Encode((string) json_encode($claims));

        return app(AppleJwsVerifier::class)->verify(
            Parser::toJws("{$header}.{$payload}." . JWT::urlsafeB64Encode('unsigned'))
        );
    }

    /**
     * @return list<string> base64 DER, leaf first
     */
    private function chain(
        string $leafExtensions = self::LEAF_EXTENSIONS,
        string $intermediateExtensions = self::INTERMEDIATE_EXTENSIONS,
        int $leafDays = 30
    ): array {
        [$rootKey, $root] = $this->certificate('Test Root CA', self::ROOT_EXTENSIONS, 30);
        [$intermediateKey, $intermediate] = $this->certificate(
            'Test WWDR CA',
            $intermediateExtensions,
            30,
            [$root, $rootKey]
        );
        [, $leaf] = $this->certificate(
            'Test App Store Signing',
            $leafExtensions,
            $leafDays,
            [$intermediate, $intermediateKey]
        );

        return array_map(static function (OpenSSLCertificate $certificate): string {
            openssl_x509_export($certificate, $pem);

            return (string) preg_replace('/-----[A-Z ]+-----|\s/', '', $pem);
        }, [$leaf, $intermediate, $root]);
    }

    /**
     * @param array{0: OpenSSLCertificate, 1: OpenSSLAsymmetricKey}|null $issuer
     * @return array{0: OpenSSLAsymmetricKey, 1: OpenSSLCertificate}
     */
    private function certificate(
        string $commonName,
        string $extensions,
        int $days,
        ?array $issuer = null
    ): array {
        $options = ['config' => $this->opensslConfig, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $commonName], $key, $options);
        $certificate = openssl_csr_sign(
            $csr,
            $issuer[0] ?? null,
            $issuer[1] ?? $key,
            $days,
            $options + ['x509_extensions' => $extensions]
        );

        return [$key, $certificate];
    }

    private function chainVerifierReturns(bool $result): void
    {
        $this->app->instance(AppStoreJwsVerifier::class, new class ($result) implements JwsVerifier {
            public function __construct(
                private readonly bool $result
            ) {
            }

            public function verify(JsonWebSignature $jws): bool
            {
                return $this->result;
            }
        });
    }

    private function chainVerifierThrows(Throwable $exception): void
    {
        $this->app->instance(AppStoreJwsVerifier::class, new class ($exception) implements JwsVerifier {
            public function __construct(
                private readonly Throwable $exception
            ) {
            }

            public function verify(JsonWebSignature $jws): bool
            {
                throw $this->exception;
            }
        });
    }
}
