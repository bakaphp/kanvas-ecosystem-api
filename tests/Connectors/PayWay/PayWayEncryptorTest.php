<?php

declare(strict_types=1);

namespace Tests\Connectors\PayWay;

use Kanvas\Connectors\PayWay\PayWayEncryptor;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

class PayWayEncryptorTest extends TestCase
{
    public function testEncryptShortStringWithStandardKey(): void
    {
        $secret = 'TEST_SECRET_KEY_FOR_PAYWAY_FIXTURE';
        $plain = '1.00';

        $encrypted = PayWayEncryptor::encrypt($plain, $secret);

        // Fixture generated offline via the PDF PHP example:
        // str_pad(substr($secret, 0, 32), 32, ' ') + OPENSSL_RAW_DATA + IV 'fedcba9876543210'
        $this->assertSame('JRAoHQVECciJLJhsM9zP7A==', $encrypted);
        $this->assertNotEmpty($encrypted);
    }

    public function testEncryptCardNumberProducesCorrectLength(): void
    {
        // 16-byte plaintext → 32-byte ciphertext (16 + 16 PKCS7 block) → 44-char base64
        $secret = 'VALOR_PROPORCIONADO_POR_PAYWAY';
        $plain = '4111111111111111';

        $encrypted = PayWayEncryptor::encrypt($plain, $secret);

        $this->assertSame(44, strlen($encrypted));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/]+=*$/', $encrypted);
    }

    public function testEncryptThrowsOnEmptySecret(): void
    {
        $this->expectException(ValidationException::class);
        PayWayEncryptor::encrypt('1.00', '');
    }
}
