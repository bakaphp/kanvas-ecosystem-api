<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Models\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettingsValuePrecisionTest extends TestCase
{
    private const KEY = 'TEST_SETTINGS_VALUE_PRECISION';

    protected function tearDown(): void
    {
        app(Apps::class)->del(self::KEY);

        parent::tearDown();
    }

    public static function numericStrings(): array
    {
        return [
            'slack client id' => ['474083765751.10750363865122'],
            'large integer' => ['18446744073709551616'],
            'leading zeros' => ['001234'],
            'scientific notation' => ['1e20'],
            'zero' => ['0'],
        ];
    }

    #[DataProvider('numericStrings')]
    public function testNumericStringsSurviveEverySettingsReadPath(string $value): void
    {
        $app = app(Apps::class);
        $app->set(self::KEY, $value);

        $stored = Settings::where('apps_id', $app->getId())->where('name', self::KEY)->firstOrFail();
        $this->assertSame($value, $stored->getRawOriginal('value'));
        $this->assertSame($value, $app->get(self::KEY));
        $this->assertSame($value, $app->getAllFromRedis()[self::KEY]);
        $this->assertSame($value, $app->getAllSettings(fromRedis: false)[self::KEY]);
        $this->assertSame($value, $app->getAllSettings(publicFormat: true)[self::KEY]['value']);

        $page = $app->getAllSettingsFromRedisPaginated(limit: 100000);
        $this->assertSame($value, array_column($page['results'], 'value', 'name')[self::KEY]);

        Redis::hDel($app->getSettingsRedisPrimaryKey(), self::KEY);
        $this->assertSame($value, $app->get(self::KEY));
        $this->assertSame($value, Redis::hGet($app->getSettingsRedisPrimaryKey(), self::KEY));

        $app->reCacheSettings();
        $this->assertSame($value, $app->get(self::KEY));
    }

    public function testEncryptedNumericStringPreservesPrecision(): void
    {
        $app = app(Apps::class);
        $value = '474083765751.10750363865122';
        $app->setEncrypted(self::KEY, $value);

        $this->assertSame($value, $app->get(self::KEY));

        Redis::hDel($app->getSettingsRedisPrimaryKey(), self::KEY);
        $this->assertSame($value, $app->get(self::KEY));
    }

    public function testJsonArraysAndObjectsStillDecode(): void
    {
        $app = app(Apps::class);
        foreach (['{"enabled":true,"count":2}', '["read","write"]'] as $json) {
            $app->set(self::KEY, $json);
            $expected = json_decode($json, true);

            $this->assertSame($expected, $app->get(self::KEY));
            $this->assertSame($expected, $app->getAllSettings(fromRedis: false)[self::KEY]);

            Redis::hDel($app->getSettingsRedisPrimaryKey(), self::KEY);
            $this->assertSame($expected, $app->get(self::KEY));
        }
    }
}
