<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Kanvas\Connectors\NetSuite\Concerns\NormalizesImportIds;
use PHPUnit\Framework\TestCase;

final class NormalizesImportIdsTest extends TestCase
{
    public function testBlankIdsAreDroppedAndRepeatsCollapse(): void
    {
        $this->assertSame(['12', 'A-7'], $this->uniqueIds([12, ' 12 ', null, '', '  ', 'A-7', 'A-7']));
    }

    public function testAnIdOfZeroIsKept(): void
    {
        $this->assertSame(['0', '5'], $this->uniqueIds(['0', 0, '5']));
    }

    /**
     * @return list<string>
     */
    private function uniqueIds(array $ids): array
    {
        return new class () {
            use NormalizesImportIds {
                uniqueIds as public;
            }
        }->uniqueIds($ids);
    }
}
