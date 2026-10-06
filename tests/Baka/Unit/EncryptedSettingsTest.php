<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Contracts\HashTableInterface;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

class EncryptedSettingsTest extends TestCase
{
    private const KEY = 'TEST_SECRET_SETTING';

    protected function tearDown(): void
    {
        app(Apps::class)->del(self::KEY);

        parent::tearDown();
    }

    public function testSecretRoundTripsBackToPlaintextOnRead(): void
    {
        $app = app(Apps::class);
        $secret = "-----BEGIN PRIVATE KEY-----\nMIIabc123\n-----END PRIVATE KEY-----\n";

        $app->setEncrypted(self::KEY, $secret);

        $this->assertSame($secret, $app->get(self::KEY));
    }

    public function testStoredFormIsCiphertextInBothDbAndRedis(): void
    {
        $app = app(Apps::class);
        $app->setEncrypted(self::KEY, 'super-secret-token');

        $rawRedis = Redis::hGet($app->getSettingsRedisPrimaryKey(), self::KEY);
        $this->assertIsString($rawRedis);
        $this->assertStringStartsWith(HashTableInterface::SECRET_PREFIX, $rawRedis);
        $this->assertStringNotContainsString('super-secret-token', $rawRedis);

        $app->reCacheSettings();
        $fromDb = $app->getAllSettings(fromRedis: false)[self::KEY] ?? null;
        $this->assertStringStartsWith(HashTableInterface::SECRET_PREFIX, (string) $fromDb);
    }

    public function testIsSecretDistinguishesEncryptedFromPlainSettings(): void
    {
        $app = app(Apps::class);

        $app->setEncrypted(self::KEY, 'hidden');
        $this->assertTrue($app->isSecret(self::KEY));

        $app->set(self::KEY, 'visible');
        $this->assertFalse($app->isSecret(self::KEY));
    }

    public function testPlainSettingsAreUnaffected(): void
    {
        $app = app(Apps::class);
        $app->set(self::KEY, 'plain-value');

        $this->assertSame('plain-value', $app->get(self::KEY));
        $this->assertFalse($app->isSecret(self::KEY));
    }

    public function testSurvivesARedisFlushByFallingBackToTheEncryptedDbRow(): void
    {
        $app = app(Apps::class);
        $secret = 'value-that-outlives-redis';

        $app->setEncrypted(self::KEY, $secret);
        Redis::del($app->getSettingsRedisPrimaryKey());

        $this->assertSame($secret, $app->get(self::KEY));
    }

    public function testSecretIsNeverListedAmongPublicSettings(): void
    {
        $app = app(Apps::class);
        $app->setEncrypted(self::KEY, 'hidden');

        $this->assertArrayNotHasKey(self::KEY, $app->getAllSettings(onlyPublicSettings: true, fromRedis: false));
    }

    public function testMissingKeyReturnsTheDefaultAndIsNotSecret(): void
    {
        $app = app(Apps::class);
        $app->del(self::KEY);
        Redis::hDel($app->getSettingsRedisPrimaryKey(), self::KEY);

        $this->assertFalse($app->isSecret(self::KEY));
        $this->assertSame('fallback', $app->get(self::KEY, 'fallback'));
    }

    public function testNullIsRejectedAndWritesNothing(): void
    {
        $app = app(Apps::class);
        $app->del(self::KEY);

        $this->assertFalse($app->setEncrypted(self::KEY, null));
        $this->assertNull($app->get(self::KEY));
    }

    public function testOnlyPrefixedStringsCountAsEncrypted(): void
    {
        $this->assertTrue(Apps::isEncryptedValue(HashTableInterface::SECRET_PREFIX . 'abc'));
        $this->assertFalse(Apps::isEncryptedValue('plain'));
        $this->assertFalse(Apps::isEncryptedValue(null));
        $this->assertFalse(Apps::isEncryptedValue([HashTableInterface::SECRET_PREFIX . 'abc']));
    }

    public function testArrayValuesAreJsonEncodedThenEncrypted(): void
    {
        $app = app(Apps::class);
        $payload = ['token' => 'abc', 'scopes' => ['read', 'write']];

        $app->setEncrypted(self::KEY, $payload);

        $this->assertSame($payload, $app->get(self::KEY));
    }
}
