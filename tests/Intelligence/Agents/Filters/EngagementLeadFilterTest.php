<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Filters;

use Illuminate\Support\Collection;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Filters\EngagementLeadFilter;
use Tests\TestCase;

class EngagementLeadFilterTest extends TestCase
{
    public function testUsesLatestStagePerEngagementEntity(): void
    {
        $records = collect([
            $this->engagement(
                1,
                10,
                'entity-a',
                'credit-app',
                'sent',
                '2026-01-01 10:00:00'
            ),
            $this->engagement(
                2,
                10,
                'entity-a',
                'credit-app',
                'submitted',
                '2026-01-01 11:00:00'
            ),
            $this->engagement(
                3,
                20,
                'entity-b',
                'credit-app-2',
                'opened',
                '2026-01-02 10:00:00'
            ),
        ]);
        $filter = $this->filter($records);

        $incomplete = $filter->resolve(
            app(Apps::class),
            auth()->user()->getCurrentCompany(),
            'credit-app',
            'incomplete'
        );
        $submitted = $filter->resolve(
            app(Apps::class),
            auth()->user()->getCurrentCompany(),
            'credit-app',
            'submitted'
        );

        $this->assertSame([20], $incomplete['lead_ids']);
        $this->assertSame([10], $submitted['lead_ids']);
        $this->assertCount(8, $incomplete['slugs']);
    }

    public function testMissingReturnsAnExclusionSelection(): void
    {
        $filter = $this->filter(collect([
            $this->engagement(
                1,
                30,
                'trade-a',
                'add-trade',
                'sent',
                '2026-01-01 10:00:00'
            ),
        ]));

        $selection = $filter->resolve(
            app(Apps::class),
            auth()->user()->getCurrentCompany(),
            'trade-in',
            'missing'
        );

        $this->assertTrue($selection['exclude']);
        $this->assertSame([30], $selection['lead_ids']);
        $this->assertSame(['add-trade'], $selection['slugs']);
    }

    private function filter(Collection $records): EngagementLeadFilter
    {
        return new class ($records) extends EngagementLeadFilter {
            public function __construct(private readonly Collection $records)
            {
            }

            protected function engagements(Apps $app, Companies $company, array $slugs): Collection
            {
                return $this->records->whereIn('slug', $slugs)->values();
            }
        };
    }

    private function engagement(
        int $id,
        int $leadId,
        string $entityUuid,
        string $slug,
        string $status,
        string $createdAt,
    ): Engagement {
        $engagement = new Engagement([
            'leads_id' => $leadId,
            'entity_uuid' => $entityUuid,
            'slug' => $slug,
            'created_at' => $createdAt,
        ]);
        $engagement->id = $id;
        $engagement->setRelation('stage', new PipelineStage(['slug' => $status]));

        return $engagement;
    }
}
