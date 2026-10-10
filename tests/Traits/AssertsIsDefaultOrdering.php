<?php

declare(strict_types=1);

namespace Tests\Traits;

/**
 * Both records are scoped by id so rows other tests leave behind can't decide the order. The
 * non-default one must be created last: checking ASC as well as DESC is what fails when orderBy
 * is ignored, since DESC alone matches plain id order.
 */
trait AssertsIsDefaultOrdering
{
    /**
     * $query declares `$ids: Mixed!` and `$order: SortOrder!` and selects `id` and `is_default`.
     */
    protected function assertOrdersByIsDefault(
        string $query,
        string $dataPath,
        int $defaultId,
        int $nonDefaultId,
        array $headers = []
    ): void {
        $expectations = [
            'DESC' => [$defaultId, $nonDefaultId],
            'ASC' => [$nonDefaultId, $defaultId],
        ];

        foreach ($expectations as $order => $expectedIds) {
            $rows = $this->graphQL(
                $query,
                ['ids' => [$defaultId, $nonDefaultId], 'order' => $order],
                [],
                $headers
            )->assertSuccessful()->json($dataPath);

            $this->assertSame(array_map('strval', $expectedIds), array_column($rows, 'id'), "IS_DEFAULT {$order}");
            $this->assertSame(
                [$order === 'DESC', $order === 'ASC'],
                array_map(fn (array $row) => (bool) $row['is_default'], $rows)
            );
        }
    }

    /**
     * $query declares `$ids: Mixed!` and `$value: Mixed!` and selects `id` and `is_default`.
     */
    protected function assertFiltersByIsDefault(
        string $query,
        string $dataPath,
        int $defaultId,
        int $nonDefaultId,
        array $headers = []
    ): void {
        foreach ([[true, $defaultId], [false, $nonDefaultId]] as [$value, $expectedId]) {
            $rows = $this->graphQL(
                $query,
                ['ids' => [$defaultId, $nonDefaultId], 'value' => $value],
                [],
                $headers
            )->assertSuccessful()->json($dataPath);

            $this->assertSame([(string) $expectedId], array_column($rows, 'id'));
            $this->assertSame($value, (bool) $rows[0]['is_default']);
        }
    }
}
