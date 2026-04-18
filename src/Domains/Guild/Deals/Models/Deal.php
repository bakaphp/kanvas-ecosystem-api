<?php

declare(strict_types=1);

namespace Kanvas\Guild\Deals\Models;

use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $apps_id
 * @property int $users_id
 * @property int $companies_id
 * @property int $companies_branches_id
 * @property int|null $leads_id
 * @property int $owner_id
 * @property int $status_id
 * @property int $pipeline_id
 * @property int $pipeline_stage_id
 * @property int|null $people_id
 * @property int|null $organization_id
 * @property int|null $status
 * @property string|null $title
 * @property string|null $description
 * @property int $is_deleted
 */
class Deal extends BaseModel
{
    use UuidTrait;
    use HasTagsTrait;
    use CanUseWorkflow;
    use DatabaseSearchableTrait {
        search as public traitSearch;
    }

    protected $table = 'deals';
    protected $guarded = [];

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class, 'people_id', 'id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'leads_id', 'id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'owner_id', 'id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CompaniesBranches::class, 'companies_branches_id', 'id');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id', 'id');
    }

    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id', 'id');
    }

    public function leadStatus(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'status_id', 'id');
    }

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_deal_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'deal_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
            'companies_branches_id' => $this->companies_branches_id,
            'leads_id' => $this->leads_id,
            'people_id' => $this->people_id,
            'organization_id' => $this->organization_id,
            'title' => $this->title,
            'description' => $this->description,
            'status_id' => $this->status_id,
            'pipeline_id' => $this->pipeline_id,
            'pipeline_stage_id' => $this->pipeline_stage_id,
        ];
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();
        if ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        return $query;
    }
}
