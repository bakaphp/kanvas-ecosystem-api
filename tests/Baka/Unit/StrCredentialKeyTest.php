<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use Tests\TestCase;

final class StrCredentialKeyTest extends TestCase
{
    public function testMatchesSecretLikeKeysRegardlessOfCaseOrSeparator(): void
    {
        $keys = [
            'TWILIO_AUTH_TOKEN',
            'api_key',
            'apiKey',
            'client-key',
            'private.key',
            'Authorization',
            'db_password',
            'smtp_passwd',
            'access_key_id',
            'oauth_credentials',
            'webhook_secret',
        ];

        foreach ($keys as $key) {
            $this->assertTrue(Str::isCredentialKey($key), $key);
        }
    }

    public function testOrdinaryConfigurationKeysAreNotCredentials(): void
    {
        $keys = [
            'region',
            'twilio_from_phone_number',
            'is_active',
            'business_hours',
            'agent_reach_out_default_agent_id',
            'ai-agent-user-id',
        ];

        foreach ($keys as $key) {
            $this->assertFalse(Str::isCredentialKey($key), $key);
        }
    }
}
