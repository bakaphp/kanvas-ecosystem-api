<?php

declare(strict_types=1);

namespace Tests\Souk\Unit;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Orders\Validations\DuplicatedMetadata;
use Mockery;
use PHPUnit\Framework\TestCase;

final class DuplicatedMetadataLockKeyTest extends TestCase
{
    private function rule(bool $enabled, ?string $field = 'data.vehiclePlate', int $appId = 26): DuplicatedMetadata
    {
        $app = Mockery::mock(Apps::class);
        $app->shouldReceive('get')
            ->with(ConfigurationEnum::VALIDATE_METADATA_DUPLICATED_ENABLED->value)
            ->andReturn($enabled ? '1' : null);
        $app->shouldReceive('getId')->andReturn($appId);

        $orderType = Mockery::mock(OrderTypes::class);
        $orderType->shouldReceive('offsetExists')->andReturn(true);
        $orderType->shouldReceive('getAttribute')
            ->with('config')
            ->andReturn($field ? ['validate_metadata_duplicated_field' => $field] : []);

        return new DuplicatedMetadata($app, Mockery::mock(Companies::class), $orderType);
    }

    public function testSameVehicleProducesTheSameKeyRegardlessOfCase(): void
    {
        $rule = $this->rule(enabled: true);

        $this->assertNotNull($rule->lockKey(['data' => ['vehiclePlate' => 'GG443805']]));
        $this->assertSame(
            $rule->lockKey(['data' => ['vehiclePlate' => 'GG443805']]),
            $rule->lockKey(['data' => ['vehiclePlate' => 'gg443805']])
        );
    }

    public function testDifferentVehiclesProduceDifferentKeys(): void
    {
        $rule = $this->rule(enabled: true);

        $this->assertNotSame(
            $rule->lockKey(['data' => ['vehiclePlate' => 'GG443805']]),
            $rule->lockKey(['data' => ['vehiclePlate' => 'AB123456']])
        );
    }

    public function testDifferentAppsDoNotShareAKey(): void
    {
        $metadata = ['data' => ['vehiclePlate' => 'GG443805']];

        $this->assertNotSame(
            $this->rule(enabled: true, appId: 26)->lockKey($metadata),
            $this->rule(enabled: true, appId: 27)->lockKey($metadata)
        );
    }

    public function testNoLockWhenTheValidationIsDisabled(): void
    {
        $this->assertNull($this->rule(enabled: false)->lockKey(['data' => ['vehiclePlate' => 'GG443805']]));
    }

    public function testNoLockWhenTheOrderTypeHasNoDuplicateField(): void
    {
        $this->assertNull($this->rule(enabled: true, field: null)->lockKey(['data' => ['vehiclePlate' => 'GG443805']]));
    }

    public function testNoLockWhenTheMetadataLacksTheField(): void
    {
        $this->assertNull($this->rule(enabled: true)->lockKey(['data' => ['other' => 'x']]));
    }
}
