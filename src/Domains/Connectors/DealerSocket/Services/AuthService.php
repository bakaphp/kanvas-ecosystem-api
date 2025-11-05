<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Services;

class AuthService
{
    private string $publicKey;
    private string $privateKey;

    private string $directPostUsername;
    private string $directPostPassword;

    private string $dealerId;
    private string $vendorName;

    public function __construct(
        ?string $publicKey = null,
        ?string $privateKey = null,
        ?string $directPostUsername = null,
        ?string $directPostPassword = null,
        ?string $dealerId = null,
        ?string $vendorName = null
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
