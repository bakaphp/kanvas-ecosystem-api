<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Support\Str;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\LeadFilterEnum;
use Kanvas\Guild\Leads\Factories\LeadFactory;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Follows\Traits\FollowersTrait;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Override;

/**
 * Class Leads.
 *
 * @property int $id
 * @property string $uuid
 * @property int $users_id
 * @property int $companies_id
 * @property int|null $apps_id
 * @property int $companies_branches_id
 * @property int $leads_receivers_id
 * @property int $leads_owner_id
 * @property int $leads_status_id
 * @property int $leads_sources_id
 * @property int|null $pipeline_id
 * @property int|null $pipeline_stage_id
 * @property int $people_id
 * @property int $organization_id
 * @property int $leads_types_id
 * @property int $status
 * @property string|null $reason_lost
 * @property string $title
 * @property string $firstname
 * @property string $lastname
 * @property string $email
 * @property string $phone
 * @property string|null $description
 * @property string $is_duplicate
 * @property string $third_party_sync_status @deprecated version 0.3
 */
class Lead extends BaseModel
{
    use UuidTrait;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use HasTagsTrait;
    use FollowersTrait;
    use CanUseWorkflow;
    use HasLightHouseCache;

    protected $table = 'leads';
    protected $guarded = [];

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class, 'people_id', 'id');
    }

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'Lead';
    }

    public function participants(): HasMany
    {
        return $this->hasMany(LeadParticipant::class, 'leads_id', 'id');
    }

    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'apps_id', 'apps_id')
                    ->where('model_name', self::class);
    }

    public function scopeFilterSettings(Builder $query, mixed $user = null): Builder
    {
        //super admin can see all leads
        if (app()->bound(AppKey::class)) {
            return $query;
        }

        $app = app(Apps::class);
        $user = $user instanceof UserInterface ? $user : auth()->user();

        if ($app->get(LeadFilterEnum::FITTER_BY_USER->value)) {
            return $query->where('users_id', $user->getId());
        }

        if ($app->get(LeadFilterEnum::FILTER_BY_BRANCH->value)) {
            return $query->where('companies_branches_id', $user->getCurrentBranch()->getId());
        }

        if ($app->get(LeadFilterEnum::FILTER_BY_AGENTS->value)) {
            $company = $user->getCurrentCompany();
            $agent = Agent::fromCompany($company)->where('users_id', $user->getId())->first();

            if (! $agent) {
                return $query->where('users_id', $user->getId());
            }

            return $query->where(function ($query) use ($user, $agent) {
                $query->where('users_id', $user->getId())
                      ->orWhereIn('users_id', function ($query) use ($agent) {
                          $query->select('users_id')
                                ->from('agents')
                                ->where('companies_id', $agent->companies_id)
                                ->where('owner_linked_source_id', $agent->users_linked_source_id)
                                ->where('status_id', 1)
                                ->where('is_deleted', 0);
                      });
            })->where('is_deleted', 0);
        }

        if ($app->get(LeadFilterEnum::FILTER_BY_SPONSOR->value)) {
            $company = $user->getCurrentCompany();
            $agent = Agent::fromCompany($company)->where('users_id', $user->getId())->first();

            if (! $agent) {
                return $query->where('users_id', $user->getId());
            }

            return $query->where(function ($query) use ($user, $agent) {
                $query->where('users_id', $user->getId())
                      ->orWhereIn('users_id', function ($query) use ($agent) {
                          $query->select('users_id')
                                ->from('agents')
                                ->where('companies_id', $agent->companies_id)
                                ->where('owner_id', $agent->member_id)
                                ->where('status_id', 1)
                                ->where('is_deleted', 0);
                      });
            })->where('is_deleted', 0);
        }

        return $query;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'leads_owner_id', 'id');
    }

    public function socialChannels(): HasMany
    {
        return $this->hasMany(Channel::class, 'entity_id', 'id')
            ->whereIn('entity_namespace', [self::class, SystemModules::getLegacyNamespace(self::class)]);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(LeadReceiver::class, 'leads_receivers_id', 'id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'leads_status_id', 'id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'leads_sources_id', 'id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeadType::class, 'leads_types_id', 'id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id', 'id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id', 'id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(LeadAttempt::class, 'id', 'leads_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(LeadAttempt::class, 'leads_id', 'id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CompaniesBranches::class, 'companies_branches_id');
    }

    public function isOpen(): bool
    {
        return $this->status < 2;
    }

    public function isActive(): bool
    {
        $statusName = strtolower($this->status()->firstOrFail()->name);

        return $statusName !== 'inactive' && (Str::contains($statusName, 'active') || Str::contains($statusName, 'created'));
    }

    public function close(): void
    {
        $this->leads_status_id = 6; //change by dynamic
        $this->saveOrFail();
    }

    public function setDuplicate(): self
    {
        $duplicate = LeadStatus::where('name', 'Duplicate')->first();

        if ($duplicate) {
            $this->leads_status_id = $duplicate->getId();
        }

        return $this;
    }

    public function duplicate(): void
    {
        $this->setDuplicate()->saveOrFail();
    }

    #[Override]
    protected static function newFactory()
    {
        return new LeadFactory();
    }

    public function addCoBuyerParticipant(People $people): LeadParticipant
    {
        $type = LeadParticipantType::where('name', 'Co-Buyer')
            ->where('is_deleted', 0)
            ->where('companies_id', $this->companies_id)
            ->first();

        // Find existing participant or create new one
        $participant = LeadParticipant::where('leads_id', $this->id)
            ->where('peoples_id', $people->id)
            ->first();

        if (! $participant) {
            $participant = new LeadParticipant();
        }

        // Set participant data
        $participant->leads_id = $this->id;
        $participant->peoples_id = $people->id;
        $participant->participants_types_id = $type ? $type->id : 0;
        $participant->is_deleted = 0;

        $participant->save();

        return $participant;
    }

    public function addParticipant(People $people): LeadParticipant
    {
        // Find existing participant or create new one
        $participant = LeadParticipant::where('leads_id', $this->id)
            ->where('peoples_id', $people->id)
            ->where('is_deleted', 0)
            ->first();

        if (! $participant) {
            $participant = new LeadParticipant();
        }

        $participant->leads_id = $this->id;
        $participant->peoples_id = $people->id;
        $participant->is_deleted = 0;

        $participant->save();

        return $participant;
    }

    public function removeParticipant(People $people): bool
    {
        return LeadParticipant::where('leads_id', $this->id)
            ->where('peoples_id', $people->id)
            ->firstOrFail()->delete();
    }

    public function setOrganization(Organization $organization): void
    {
        $this->organization_id = $organization->id;
        $this->save();
    }

    /**
     * The Typesense schema to be created for the Lead model.
     */
    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                [
                    'name' => 'objectID',
                    'type' => 'string',
                ],
                [
                    'name' => 'id',
                    'type' => 'string',
                ],
                [
                    'name' => 'uuid',
                    'type' => 'string',
                ],
                [
                    'name' => 'users_id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'companies_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'apps_id',
                    'type' => 'int64',
                    'optional' => true,
                ],
                [
                    'name' => 'companies_branches_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'leads_receivers_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'leads_owner_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'leads_status_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'leads_sources_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'pipeline_id',
                    'type' => 'int64',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'pipeline_stage_id',
                    'type' => 'int64',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'people_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'organization_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'leads_types_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'status',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'title',
                    'type' => 'string',
                    'sort' => true,
                ],
                [
                    'name' => 'firstname',
                    'type' => 'string',
                    'sort' => true,
                ],
                [
                    'name' => 'lastname',
                    'type' => 'string',
                    'sort' => true,
                ],
                [
                    'name' => 'email',
                    'type' => 'string',
                ],
                [
                    'name' => 'phone',
                    'type' => 'string',
                ],
                [
                    'name' => 'description',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'reason_lost',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'is_duplicate',
                    'type' => 'bool',
                    'facet' => true,
                ],
                [
                    'name' => 'owner',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'people',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'organization',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'source',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'status_object',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'type',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'pipeline',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'stage',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'tags',
                    'type' => 'string[]',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'created_at',
                    'type' => 'int64',
                    'sort' => true,
                ],
                [
                    'name' => 'updated_at',
                    'type' => 'int64',
                    'optional' => true,
                ],
            ],
            'default_sorting_field' => 'created_at',
            'enable_nested_fields' => true,
        ];
    }

    /**
     * Define the searchable index name.
     */
    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_lead_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'leads');
    }

    public function startShowRoom(): void
    {
        $this->set('is_chrono_running', 1);
        $this->set('chrono_start_date', date('c'));
    }

    public static function search($query = '', $callback = null)
    {
        $app = app(Apps::class);

        $app->fireWorkflow(
            event: WorkflowEnum::SEARCH->value,
            params: [
                'search_type' => 'lead',
                'search' => trim($query),
            ]
        );

        $query = self::traitSearch($query, $callback)->where('apps_id', $app->getId());
        $user = auth()->user();

        if ($user instanceof UserInterface && ! auth()->user()->isAppOwner()) {
            $query->where('companies_id', auth()->user()->getCurrentCompany()->getId());
        }

        if ($query->model->isTypesense()) {
            $query->options([
                'query_by' => 'name, description,translations', // Use just 'message' instead of 'message.name'
            ]);
        }

        return $query;
    }
}
