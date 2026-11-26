<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Services;

use DateTimeInterface;
use JsonException;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use RuntimeException;
use Throwable;

class EngagementUploadTokenService
{
    public const int DEFAULT_TTL_HOURS = 72;

    /**
     * @throws JsonException
     */
    public function issue(
        Engagement $engagement,
        int $maxFiles,
        ?DateTimeInterface $expiresAt = null
    ): string {
        $payload = json_encode([
            'engagement_uuid' => (string) $engagement->uuid,
            'company_id' => (int) $engagement->companies_id,
            'exp' => $expiresAt?->getTimestamp() ?? now()->addHours(self::DEFAULT_TTL_HOURS)->getTimestamp(),
            'max_files' => $maxFiles,
        ], JSON_THROW_ON_ERROR);
        $encodedPayload = self::base64UrlEncode($payload);

        return $encodedPayload . '.' . self::base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->signingKey(), true));
    }

    /**
     * @return array{engagement_uuid: string, company_id: int, exp: int, max_files: int}|null
     */
    public function verify(string $token): ?array
    {
        [$encodedPayload, $encodedSignature] = array_pad(explode('.', $token, 2), 2, null);

        if (! is_string($encodedPayload) || ! is_string($encodedSignature)) {
            return null;
        }

        try {
            $signature = self::base64UrlDecode($encodedSignature);
            $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->signingKey(), true);
            $payload = json_decode(self::base64UrlDecode($encodedPayload), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! hash_equals($expectedSignature, $signature) || ! is_array($payload)) {
            return null;
        }

        $engagementUuid = $payload['engagement_uuid'] ?? null;
        $companyId = $payload['company_id'] ?? null;
        $expiresAt = $payload['exp'] ?? null;
        $maxFiles = $payload['max_files'] ?? null;

        if (! is_string($engagementUuid)
            || ! is_numeric($companyId)
            || ! is_numeric($expiresAt)
            || ! is_numeric($maxFiles)
            || (int) $expiresAt <= now()->getTimestamp()
            || (int) $companyId < 1
            || (int) $maxFiles < 0
        ) {
            return null;
        }

        return [
            'engagement_uuid' => $engagementUuid,
            'company_id' => (int) $companyId,
            'exp' => (int) $expiresAt,
            'max_files' => (int) $maxFiles,
        ];
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decodedKey = base64_decode(substr($key, 7), true);
            if (is_string($decodedKey) && $decodedKey !== '') {
                return $decodedKey;
            }
        }

        if ($key === '') {
            throw new RuntimeException('The application signing key is not configured.');
        }

        return $key;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        if ($decoded === false) {
            throw new JsonException('Invalid token encoding.');
        }

        return $decoded;
    }
}
