<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use RuntimeException;
use Tests\TestCase;

final class IdMapRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem'];

    private const string MODEL = 'Tests\\BrushCrazy\\FakeLookupModel';

    public function testLookupReturnsTheSeededRowOfThatCompanyOnly(): void
    {
        $companyId = random_int(800_000_000, 900_000_000);
        $this->seedLookup($companyId, 41, 'type:lesson');
        $this->seedLookup($companyId + 1, 42, 'type:lesson');

        $this->assertSame(41, new IdMapRepository()->lookup($companyId, self::MODEL, 'type:lesson'));
        $this->assertSame(42, new IdMapRepository()->lookup($companyId + 1, self::MODEL, 'type:lesson'));
    }

    public function testLookupFailsLoudlyWhenTheSeedHasNotRun(): void
    {
        $this->expectException(RuntimeException::class);

        new IdMapRepository()->lookup(random_int(800_000_000, 900_000_000), self::MODEL, 'type:lesson');
    }

    public function testADeletedCustomFieldIsNotALookupHit(): void
    {
        $companyId = random_int(800_000_000, 900_000_000);
        $this->seedLookup(
            $companyId,
            43,
            'status:open',
            isDeleted: 1
        );

        $this->expectException(RuntimeException::class);

        new IdMapRepository()->lookup($companyId, self::MODEL, 'status:open');
    }

    private function seedLookup(
        int $companyId,
        int $entityId,
        string $key,
        int $isDeleted = 0
    ): void {
        DB::connection('ecosystem')->table('apps_custom_fields')->insert([
            'companies_id' => $companyId,
            'users_id' => 0,
            'model_name' => self::MODEL,
            'entity_id' => $entityId,
            'name' => CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY->value,
            'label' => CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY->value,
            'value' => $key,
            'created_at' => now(),
            'is_deleted' => $isDeleted,
        ]);
    }
}
