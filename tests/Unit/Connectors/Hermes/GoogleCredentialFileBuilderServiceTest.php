<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Hermes;

use Kanvas\Connectors\Hermes\Services\GoogleCredentialFileBuilderService;
use Kanvas\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

class GoogleCredentialFileBuilderServiceTest extends TestCase
{
    public function testBuildsBothFilesWithCorrectShape(): void
    {
        $files = GoogleCredentialFileBuilderService::buildFiles([
            'client_id' => 'client-abc',
            'client_secret' => 'secret-def',
            'refresh_token' => 'refresh-xyz',
            'access_token' => 'access-123',
            'expiry' => '2026-06-03T15:00:00Z',
            'scopes' => [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/drive.readonly',
            ],
        ]);

        $this->assertArrayHasKey('google_token.json', $files);
        $this->assertArrayHasKey('google_client_secret.json', $files);

        $token = json_decode($files['google_token.json'], true);
        $this->assertSame('access-123', $token['token']);
        $this->assertSame('refresh-xyz', $token['refresh_token']);
        $this->assertSame('client-abc', $token['client_id']);
        $this->assertSame('secret-def', $token['client_secret']);
        $this->assertSame('https://oauth2.googleapis.com/token', $token['token_uri']);
        $this->assertSame('2026-06-03T15:00:00Z', $token['expiry']);
        $this->assertSame(
            [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/drive.readonly',
            ],
            $token['scopes']
        );

        $clientSecret = json_decode($files['google_client_secret.json'], true);
        $this->assertSame('client-abc', $clientSecret['installed']['client_id']);
        $this->assertSame('secret-def', $clientSecret['installed']['client_secret']);
        $this->assertSame('https://oauth2.googleapis.com/token', $clientSecret['installed']['token_uri']);
        $this->assertSame('https://accounts.google.com/o/oauth2/auth', $clientSecret['installed']['auth_uri']);
        $this->assertSame(['http://localhost'], $clientSecret['installed']['redirect_uris']);
    }

    public function testAccessTokenAndExpiryAreOptionalAndDefaultNull(): void
    {
        $files = GoogleCredentialFileBuilderService::buildFiles([
            'client_id' => 'cid',
            'client_secret' => 'csecret',
            'refresh_token' => 'rtok',
        ]);

        $token = json_decode($files['google_token.json'], true);
        $this->assertNull($token['token']);
        $this->assertNull($token['expiry']);
        $this->assertSame([], $token['scopes']);
    }

    public function testScopesDedupedAndNonStringsDropped(): void
    {
        $files = GoogleCredentialFileBuilderService::buildFiles([
            'client_id' => 'cid',
            'client_secret' => 'csecret',
            'refresh_token' => 'rtok',
            'scopes' => [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.readonly',
                '',
                null,
                42,
                'https://www.googleapis.com/auth/drive.readonly',
            ],
        ]);

        $token = json_decode($files['google_token.json'], true);
        $this->assertSame(
            [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/drive.readonly',
            ],
            $token['scopes']
        );
    }

    public function testMissingClientIdThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('client_id');

        GoogleCredentialFileBuilderService::buildFiles([
            'client_secret' => 'csecret',
            'refresh_token' => 'rtok',
        ]);
    }

    public function testMissingRefreshTokenThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('refresh_token');

        GoogleCredentialFileBuilderService::buildFiles([
            'client_id' => 'cid',
            'client_secret' => 'csecret',
        ]);
    }

    public function testEmptyStringFieldsAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('client_secret');

        GoogleCredentialFileBuilderService::buildFiles([
            'client_id' => 'cid',
            'client_secret' => '',
            'refresh_token' => 'rtok',
        ]);
    }

    public function testOutputIsValidJson(): void
    {
        $files = GoogleCredentialFileBuilderService::buildFiles([
            'client_id' => 'cid',
            'client_secret' => 'csecret',
            'refresh_token' => 'rtok',
        ]);

        foreach ($files as $name => $contents) {
            $decoded = json_decode($contents, true);
            $this->assertIsArray($decoded, "{$name} should be valid JSON");
        }
    }
}
