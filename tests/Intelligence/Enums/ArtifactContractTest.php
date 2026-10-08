<?php

declare(strict_types=1);

namespace Tests\Intelligence\Enums;

use Kanvas\AdminLinks\Enums\AdminLinkIdentifierEnum;
use Kanvas\Intelligence\Agents\Enums\ArtifactComponentEnum;
use Kanvas\Intelligence\Agents\Enums\ArtifactEntityTypeEnum;
use Tests\TestCaseUnit;

/**
 * The admin owns the artifact contract and exports the part this API mirrors by hand into
 * `tests/fixtures/admin-artifact-contract.json` (`pnpm gen:artifact-contract` in kanvas-admin-v2, then
 * copy the file here). A mismatch is silent in production: a block the admin can draw and this API does
 * not know is stripped from the reply, and one this API lets through with a filter the admin refuses
 * renders as an error card. So the mirror is held to the export.
 */
final class ArtifactContractTest extends TestCaseUnit
{
    public function testEveryRecordTypeOfTheAdminIsKnownHereAndNoOther(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys($this->contract()['entity']['types']),
            ArtifactEntityTypeEnum::values()
        );
    }

    public function testEachRecordTypeReadsTheSameIdentifierAsItsAdminPage(): void
    {
        foreach ($this->contract()['entity']['types'] as $value => $spec) {
            $type = ArtifactEntityTypeEnum::from($value);

            $this->assertSame(
                $spec['idKind'],
                $this->kindName($type->identifier()),
                $value . ' reads a different identifier than its admin page'
            );
            $this->assertSame(
                $spec['needsParent'],
                $type->section() === null,
                $value . ': only a route that hangs off another record has no section'
            );
        }
    }

    public function testTheListableTypesAreTheAdminsListableTypes(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys($this->contract()['records']['types']),
            ArtifactEntityTypeEnum::listable()
        );
    }

    public function testEveryListTakesExactlyTheAdminsFilters(): void
    {
        foreach ($this->contract()['records']['types'] as $value => $filters) {
            $mirrored = ArtifactEntityTypeEnum::from($value)->filters();

            $this->assertSame(
                $this->sorted(array_keys($filters)),
                $this->sorted(array_keys($mirrored)),
                $value . ' lists do not take the same filters as the admin'
            );

            foreach ($filters as $key => $spec) {
                $rule = $mirrored[$key];
                $path = $value . '.' . $key;

                $this->assertSame($this->ruleType($spec['kind']), $rule['type'], $path . ' takes another kind');
                $this->assertSame($spec['many'], ($rule['many'] ?? false) === true, $path . ' vs. taking a list');

                if ($spec['kind'] === 'enum') {
                    $this->assertEqualsCanonicalizing($spec['values'], $rule['values'], $path . ' allows other values');
                }
            }
        }
    }

    public function testTheListLimitsAreTheAdmins(): void
    {
        $records = $this->contract()['records'];
        $props = ArtifactComponentEnum::RECORDS->props();

        $this->assertSame($records['maxRows'], $props['limit']['max']);
        $this->assertSame($records['maxRows'], ArtifactComponentEnum::MAX_RECORDS_ROWS);
        $this->assertSame($records['maxSearchLength'], $props['search']['maxLength']);
    }

    public function testTheMetricVocabularyIsTheAdminsCatalog(): void
    {
        $metric = $this->contract()['metric'];
        $props = ArtifactComponentEnum::METRIC->props();

        $this->assertEqualsCanonicalizing($metric['ids'], $props['metric']['values']);
        $this->assertEqualsCanonicalizing($metric['windows'], $props['window']['values']);
        $this->assertEqualsCanonicalizing($metric['as'], $props['as']['values']);
        $this->assertEqualsCanonicalizing($metric['chartKinds'], $props['chartKind']['values']);
        $this->assertSame($metric['maxLimit'], $props['limit']['max']);
    }

    public function testTheToolDescriptionNamesEveryTypeFilterAndMetricFamily(): void
    {
        $entity = ArtifactComponentEnum::ENTITY->usage();
        $records = ArtifactComponentEnum::RECORDS->usage();
        $metric = ArtifactComponentEnum::METRIC->usage();

        foreach (ArtifactEntityTypeEnum::values() as $type) {
            $this->assertStringContainsString($type, $entity);
        }

        foreach ($this->contract()['records']['types'] as $type => $filters) {
            foreach (array_keys($filters) as $key) {
                $this->assertStringContainsString($key, $records, $type . '.' . $key . ' is not described');
            }
        }

        foreach (ArtifactComponentEnum::metrics() as $id) {
            [$family, $name] = explode('.', $id, 2);

            $this->assertStringContainsString($family . '.{', $metric);
            $this->assertStringContainsString($name, $metric);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function contract(): array
    {
        $path = base_path('tests/fixtures/admin-artifact-contract.json');

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function kindName(AdminLinkIdentifierEnum $identifier): string
    {
        return match ($identifier) {
            AdminLinkIdentifierEnum::ID => 'id',
            AdminLinkIdentifierEnum::UUID => 'uuid',
            AdminLinkIdentifierEnum::SLUG => 'slug',
            AdminLinkIdentifierEnum::EITHER => 'either',
        };
    }

    private function ruleType(string $kind): string
    {
        return match ($kind) {
            'id' => 'record_id',
            'text' => 'string',
            'enum' => 'enum',
            'boolean' => 'bool',
        };
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
