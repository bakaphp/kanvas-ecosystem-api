<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Services;

class AuthService
{
    public function __construct(
        private ?string $publicKey = null,
        private ?string $privateKey = null,
        private ?string $directPostUsername = null,
        private ?string $directPostPassword = null,
        private ?string $dealerId = null,
        private ?string $vendorName = null
    ) {
        $this->publicKey = $publicKey ?? '';
        $this->privateKey = $privateKey ?? '';

        $this->directPostUsername = $directPostUsername ?? '';
        $this->directPostPassword = $directPostPassword ?? '';

        $this->dealerId = $dealerId ?? '';
        $this->vendorName = $vendorName ?? '';
    }

    public function createSignature(string $body): string
    {
        $hash = hash_hmac('sha256', $body, $this->privateKey, true);
        $hashString = base64_encode($hash);

        return $this->publicKey . ':' . $hashString;
    }

    public function getHeaders(string $body): array
    {
        return [
            'Authentication' => $this->createSignature($body),
            'Content-Type' => 'application/xml',
        ];
    }

    public function getHMACHeaders(string $xmlBody): array
    {
        $hash = hash_hmac('sha256', $xmlBody, $this->privateKey, true);
        $hashString = base64_encode($hash);

        return [
            'Authentication' => $this->publicKey . ':' . $hashString,
            'Content-Type' => 'application/xml',
        ];
    }

    /**
     * Get headers for Direct Post authentication (Leads API)
     */
    public function getDirectPostHeaders(): array
    {
        $token = $this->directPostUsername . ':' . $this->directPostPassword;

        return [
            'Authorization' => $token,
            'Content-Type' => 'application/xml',
        ];
    }

    public function getDealerId(): string
    {
        return $this->dealerId;
    }

    public function getVendorName(): string
    {
        return $this->vendorName;
    }
}
