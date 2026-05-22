<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PayWay;

use Kanvas\Exceptions\ValidationException;

final class PayWayEncryptor
{
    // IV is mandated by the PayWay vendor (PDF §Anexos — Encriptado de Datos). Identical
    // across all merchants — the per-Company secret is what makes ciphertexts unique.
    private const string IV = 'fedcba9876543210';
    private const string CIPHER = 'aes-256-cbc';

    public static function encrypt(string $plaintext, string $secretKey): string
    {
        if (trim($secretKey) === '') {
            throw new ValidationException('PayWay secret key must not be empty.');
        }

        $paddedKey = str_pad(substr($secretKey, 0, 32), 32, ' ');

        if (strlen($paddedKey) !== 32) {
            throw new ValidationException('PayWay padded key must be exactly 32 bytes.');
        }

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $paddedKey, OPENSSL_RAW_DATA, self::IV);

        if ($ciphertext === false) {
            throw new ValidationException('PayWay encryption failed: ' . openssl_error_string());
        }

        return base64_encode($ciphertext);
    }
}
