<?php

declare(strict_types=1);

namespace Tests\Intelligence\Enums;

use Kanvas\AdminLinks\Enums\AdminLinkIdentifierEnum;
use Kanvas\Intelligence\Agents\Enums\ArtifactComponentEnum;
use Kanvas\Intelligence\Agents\Enums\ArtifactEntityTypeEnum;
use Kanvas\Intelligence\Agents\Services\ArtifactBlockService;
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
    public function testEveryComponentOfTheAdminIsKnownHereAndNoOther(): void
    {
        $this->assertEqualsCanonicalizing($this->contract()['components'], ArtifactComponentEnum::values());
    }

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

    /**
     * Wider than what the page reads, and the two must not be confused: refusing an order's uuid
     * strips a card the admin draws, and letting a category's uuid through draws one that reads nothing.
     */
    public function testACardIsFoundByWhatTheAdminCanReadItBy(): void
    {
        foreach ($this->contract()['entity']['types'] as $value => $spec) {
            $type = ArtifactEntityTypeEnum::from($value);

            $this->assertEqualsCanonicalizing(
                $spec['lookup'],
                array_map($this->kindName(...), $type->lookups()),
                $value . ' is found by something else in the admin'
            );
        }
    }

    /**
     * The tool swaps the id it is given for the one the record's page reads. If the card could not
     * find the record by that, every looked-up card of the type would be refused right after.
     */
    public function testWhatTheToolRewritesAnIdToIsSomethingTheCardReads(): void
    {
        foreach (ArtifactEntityTypeEnum::cases() as $type) {
            $page = $type->identifier();
            $written = $page === AdminLinkIdentifierEnum::EITHER ? AdminLinkIdentifierEnum::UUID : $page;

            $this->assertContains($written, $type->lookups(), $type->value);
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
        $records = $this->contract()['records'];

        foreach ($records['types'] as $value => $spec) {
            $filters = $spec['filters'];
            $mirrored = ArtifactEntityTypeEnum::from($value)->filters();

            $this->assertSame(
                $this->sorted(array_keys($filters)),
                $this->sorted(array_keys($mirrored)),
                $value . ' lists do not take the same filters as the admin'
            );

            foreach ($filters as $key => $filter) {
                $rule = $mirrored[$key];
                $path = $value . '.' . $key;

                $this->assertSame($this->ruleType($filter['kind']), $rule['type'], $path . ' takes another kind');
                $this->assertSame($filter['many'], ($rule['many'] ?? false) === true, $path . ' vs. taking a list');

                if ($filter['kind'] === 'enum') {
                    $this->assertEqualsCanonicalizing($filter['values'], $rule['values'], $path . ' allows other values');
                }

                if ($filter['kind'] === 'text') {
                    $this->assertSame($records['maxTextLength'], $rule['maxLength'], $path . ' is capped elsewhere');
                }
            }
        }
    }

    public function testTheListsThatCannotBeSearchedAreTheAdmins(): void
    {
        foreach ($this->contract()['records']['types'] as $value => $spec) {
            $this->assertSame(
                $spec['searchable'],
                ArtifactEntityTypeEnum::from($value)->searchable(),
                $value . ' lists: the admin and this API disagree on whether they can be searched'
            );
        }
    }

    public function testTheListLimitsAreTheAdmins(): void
    {
        $records = $this->contract()['records'];
        $props = ArtifactComponentEnum::RECORDS->props();

        $this->assertSame($records['maxRows'], $props['limit']['max']);
        $this->assertSame($records['maxRows'], ArtifactComponentEnum::MAX_RECORDS_ROWS);
        $this->assertSame($records['maxSearchLength'], $props['search']['maxLength']);
        $this->assertSame($records['maxFilterValues'], ArtifactComponentEnum::MAX_FILTER_VALUES);
        $this->assertSame($records['maxTextLength'], ArtifactEntityTypeEnum::MAX_FILTER_TEXT);
        $this->assertSame('/' . $records['integerId'] . '/D', ArtifactBlockService::INTEGER_ID_PATTERN);
    }

    /**
     * The admin refuses to draw text or a list past its own sizes. Checked here first, a callout that
     * runs long is an error the model can shorten instead of a card that never appears.
     */
    public function testTextAndListSizesAreTheAdmins(): void
    {
        $bounds = $this->contract()['bounds'];

        foreach ($bounds['strings'] as $path => $bound) {
            $rule = $this->ruleAt($path);

            // A prop this API holds to a list of values is already stricter than any length.
            if ($rule['type'] === 'enum') {
                continue;
            }

            $this->assertSame('string', $rule['type'], $path . ' is text in the admin');
            $this->assertSame(
                ($bound['min'] ?? 0) >= 1,
                ($rule['nonEmpty'] ?? false) === true,
                $path . ' may be empty on one side only'
            );
            $this->assertSame($bound['max'], $rule['maxLength'] ?? null, $path . ' is capped on one side only');
        }

        foreach ($bounds['lists'] as $path => $bound) {
            $rule = $this->ruleAt($path);

            $this->assertSame($bound['min'] ?? 0, $rule['min'], $path . ' needs more entries on one side');
            $this->assertSame($bound['max'], $rule['max'], $path . ' takes more entries on one side');
        }

        // And the other way: a size this API enforces that the admin does not strips a card it draws.
        foreach (ArtifactComponentEnum::cases() as $component) {
            $here = $this->boundedPaths($component->props(), $component->value);

            foreach ($here['strings'] as $path) {
                $this->assertArrayHasKey($path, $bounds['strings'], $path . ' is bounded here and not in the admin');
            }

            foreach ($here['lists'] as $path) {
                $this->assertArrayHasKey($path, $bounds['lists'], $path . ' is a list here and not in the admin');
            }
        }
    }

    /**
     * The admin takes these props beside `props` too. The filter over a reply reads a hand-written
     * block the same way, so it has to know the same names.
     */
    public function testAHandWrittenBlockIsReadWithTheAdminsLeniency(): void
    {
        $this->assertEquals($this->contract()['lenient'], ArtifactBlockService::LIVE_PROP_KEYS);
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
        $types = $this->contract()['records']['types'];

        foreach (ArtifactEntityTypeEnum::values() as $type) {
            $this->assertStringContainsString($type, $entity);
        }

        foreach ($types as $type => $spec) {
            foreach (array_keys($spec['filters']) as $key) {
                $this->assertStringContainsString($key, $records, $type . '.' . $key . ' is not described');
            }
        }

        $unsearchable = array_values(array_filter(
            ArtifactEntityTypeEnum::listable(),
            static fn (string $type): bool => ! $types[$type]['searchable']
        ));

        $this->assertStringContainsString('not for ' . implode(', ', $unsearchable), $records);

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

    /**
     * The rule behind a path of the export: "actions.items[].label" is the label of an item of the
     * actions component's items.
     *
     * @return array<string, mixed>
     */
    private function ruleAt(string $path): array
    {
        $segments = explode('.', $path);
        $rules = ArtifactComponentEnum::from(array_shift($segments))->props();
        $rule = [];

        foreach ($segments as $segment) {
            $rule = $rules[str_replace('[]', '', $segment)];
            $rules = $rule['item'] ?? [];
        }

        return $rule;
    }

    /**
     * The paths of every bounded string and every list under a set of rules, in the export's own
     * spelling: it lists every array, and a string only when it has a size.
     *
     * @param array<string, array<string, mixed>> $rules
     * @return array{strings: list<string>, lists: list<string>}
     */
    private function boundedPaths(array $rules, string $prefix): array
    {
        $paths = ['strings' => [], 'lists' => []];

        foreach ($rules as $key => $rule) {
            $path = $prefix . '.' . $key;

            if ($rule['type'] === 'string' && (($rule['nonEmpty'] ?? false) === true || isset($rule['maxLength']))) {
                $paths['strings'][] = $path;
            }

            if (in_array($rule['type'], ['rows', 'list', 'record_id_list'], true)) {
                $nested = $this->boundedPaths($rule['item'] ?? [], $path . '[]');

                $paths['lists'] = [...$paths['lists'], $path, ...$nested['lists']];
                $paths['strings'] = [...$paths['strings'], ...$nested['strings']];
            }
        }

        return $paths;
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
            'id' => 'integer_id',
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
