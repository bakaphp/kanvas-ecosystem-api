<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

final class SettingsDeleteTest extends TestCase
{
    private const string KEY = 'TEST_SETTINGS_DELETE';

    protected function tearDown(): void
    {
        auth()->user()->getCurrentCompany()->del(self::KEY);
        app(Apps::class)->del(self::KEY);

        parent::tearDown();
    }

    public function testDeletingRemovesTheRowAndItsCachedValue(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $company->set(self::KEY, 'value');

        $this->assertTrue($company->del(self::KEY));

        $this->assertNull($company->get(self::KEY));
        $this->assertArrayNotHasKey(self::KEY, $company->getAllSettings(fromRedis: false));
    }

    /**
     * A write whose row was rolled back leaves its value cached, and that value is still read.
     */
    public function testDeletingRemovesAValueCachedWithoutARow(): void
    {
        $company = auth()->user()->getCurrentCompany();
        Redis::hSet($company->getSettingsRedisPrimaryKey(), self::KEY, 'stale');
        $this->assertSame('stale', $company->get(self::KEY));

        $this->assertTrue($company->del(self::KEY));

        $this->assertNull($company->get(self::KEY));
    }

    public function testDeletingAKeyThatIsNowhereIsANoOp(): void
    {
        $this->assertFalse(app(Apps::class)->del(self::KEY));
    }
}
