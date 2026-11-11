<?php

declare(strict_types=1);

namespace Kanvas\Guild\Deals\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Override;

class DealFactory extends Factory
{
    protected $model = Deal::class;

    #[Override]
    public function definition(): array
    {
        return [
            'title' => 'Deal ' . fake()->company(),
            'description' => fake()->sentence(),
            'apps_id' => fn () => app(Apps::class)->getId(),
            'companies_id' => fn () => Companies::factory()->create()->getKey(),
            'users_id' => fn () => auth()->user()?->getId() ?? 1,
            'owner_id' => fn (array $attributes) => $attributes['users_id'],
            'pipeline_id' => 0,
            'pipeline_stage_id' => 0,
            'is_deleted' => 0,
        ];
    }

    public function withAppId(int $appId): self
    {
        return $this->state(['apps_id' => $appId]);
    }

    public function withCompanyId(int $companyId): self
    {
        return $this->state(['companies_id' => $companyId]);
    }

    public function withUserId(int $userId): self
    {
        return $this->state(['users_id' => $userId]);
    }

    public function withOwnerId(int $ownerId): self
    {
        return $this->state(['owner_id' => $ownerId]);
    }

    public function withLead(Lead $lead): self
    {
        return $this->state([
            'leads_id' => $lead->getId(),
            'people_id' => $lead->people_id,
        ]);
    }

    public function withStage(PipelineStage $stage): self
    {
        return $this->state([
            'pipeline_id' => $stage->pipelines_id,
            'pipeline_stage_id' => $stage->getId(),
        ]);
    }
}
