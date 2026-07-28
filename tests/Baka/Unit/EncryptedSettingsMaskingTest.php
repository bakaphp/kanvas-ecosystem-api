<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use App\GraphQL\Ecosystem\Queries\Config\ConfigManagement as ConfigQuery;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

class EncryptedSettingsMaskingTest extends TestCase
{
    private const SECRET_KEY = 'TEST_MASKED_SECRET';
    private const PLAIN_KEY = 'TEST_MASKED_PLAIN';

    protected function tearDown(): void
    {
        $app = app(Apps::class);
        $app->del(self::SECRET_KEY);
        $app->del(self::PLAIN_KEY);

        parent::tearDown();
    }

    public function testAdminListNeverEchoesASecretValue(): void
    {
        $app = app(Apps::class);
        $app->setEncrypted(self::SECRET_KEY, 'do-not-leak-me');
        $app->set(self::PLAIN_KEY, 'safe-to-show');

        $settings = new ConfigQuery()->getAppSetting(null, []);
        $byKey = array_column($settings, null, 'key');

        $this->assertNull($byKey[self::SECRET_KEY]['value']);
        $this->assertSame('safe-to-show', $byKey[self::PLAIN_KEY]['value']);

        $this->assertStringNotContainsString('do-not-leak-me', json_encode($settings));
    }

    public function testAdminSingleKeyLookupMasksSecretsButReturnsPlainValues(): void
    {
        $app = app(Apps::class);
        $app->setEncrypted(self::SECRET_KEY, 'do-not-leak-me');
        $app->set(self::PLAIN_KEY, 'safe-to-show');

        $query = new ConfigQuery();

        $this->assertNull($query->getAppSettingByKey(null, ['key' => self::SECRET_KEY]));
        $this->assertSame('safe-to-show', $query->getAppSettingByKey(null, ['key' => self::PLAIN_KEY]));
    }

    public function testInternalReadStillGetsThePlaintext(): void
    {
        $app = app(Apps::class);
        $app->setEncrypted(self::SECRET_KEY, 'do-not-leak-me');

        // The masking is an API-surface concern only; internal consumers need the real value.
        $this->assertSame('do-not-leak-me', $app->get(self::SECRET_KEY));
    }
}
