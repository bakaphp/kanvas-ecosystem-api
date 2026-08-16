<?php

declare(strict_types=1);

namespace Kanvas\Guild\Deals\Models;

use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Event\Events\Traits\EventResourceTrait;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Observers\DealObserver;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Follows\Traits\FollowersTrait;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\SystemModules\Models\SystemModules;
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
#[ObservedBy([DealObserver::class])]
class Deal extends BaseModel
{
    use UuidTrait;
    use HasTagsTrait;
    use CanUseWorkflow;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use FollowersTrait;
    use HasLightHouseCache;
    use EventResourceTrait;

    protected $observables = [
        'softDeleting',
        'softDeleted',
    ];

    protected $table = 'deals';
    protected $guarded = [];

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'Deal';
    }

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

    public function getStringIdAttribute(): string
    {
        return (string) $this->id;
    }

    public function socialChannels(): HasMany
    {
        return $this->hasMany(Channel::class, 'entity_id', 'string_id')
            ->whereIn(
                'entity_namespace',
                [
                    self::class,
                    SystemModules::getLegacyNamespace(self::class),
                ]
            )
            ->where('is_deleted', 0);
    }

    public function notes(): HasOne
    {
        return $this->hasOne(Channel::class, 'entity_id', 'string_id')
            ->where('entity_namespace', self::class)
            ->where('name', ChannelNameEnum::NOTES->value);
    }

    public function defaultChannel(): HasOne
    {
        return $this->hasOne(Channel::class, 'entity_id', 'string_id')
            ->where('entity_namespace', self::class)
            ->where('slug', $this->uuid);
    }

    public function aiSession(): HasMany
    {
        return $this->hasMany(Session::class, 'entity_id', 'string_id')
            ->where('entity_namespace', self::class);
    }

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_deal_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'deal_index');
    }

    public function searchQueryBy(): string
    {
        return 'title,description,people_name,people_firstname,people_lastname';
    }

    /** Scout only eager-loads what this adds, and toSearchableArray reads the contact per row. */
    protected function makeAllSearchableUsing(EloquentBuilder $query): EloquentBuilder
    {
        return $query->with('people');
    }

    public function toSearchableArray(): array
    {
        $people = $this->people;

        return [
            'objectID' => self::class . '::' . $this->id,
            'id' => (string) $this->id, // Typesense rejects a non-string document id
            'uuid' => (string) $this->uuid,
            'apps_id' => (int) $this->apps_id,
            'companies_id' => (int) $this->companies_id,
            'companies_branches_id' => (int) $this->companies_branches_id,
            'leads_id' => (int) $this->leads_id,
            'people_id' => (int) $this->people_id,
            'organization_id' => (int) $this->organization_id,
            'title' => (string) $this->title,
            'description' => (string) $this->description,
            // Flattened rather than a nested people object: the contact's name is a query_by target,
            // and nested fields can't be named in query_by without enable_nested_fields gymnastics.
            'people_name' => (string) $people?->getName(),
            'people_firstname' => (string) $people?->firstname,
            'people_lastname' => (string) $people?->lastname,
            'status_id' => (int) $this->status_id,
            'pipeline_id' => (int) $this->pipeline_id,
            'pipeline_stage_id' => (int) $this->pipeline_stage_id,
            'created_at' => $this->created_at?->timestamp,
            'updated_at' => $this->updated_at?->timestamp,
        ];
    }

    /**
     * Typesense collections are immutable once created — changing this has no effect on a live
     * collection until it is dropped and reindexed.
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
                    'name' => 'apps_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'companies_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'companies_branches_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'leads_id',
                    'type' => 'int64',
                    'optional' => true,
                ],
                [
                    'name' => 'people_id',
                    'type' => 'int64',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'organization_id',
                    'type' => 'int64',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'title',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'description',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'people_name',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'people_firstname',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'people_lastname',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'status_id',
                    'type' => 'int64',
                    'optional' => true,
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
        ];
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch(
            $query,
            $callback
        )->where(
            'apps_id',
            app(Apps::class)->getId()
        );

        $user = auth()->user();
        if ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        if ($query->model->isTypesense()) {
            $query->options([
                'query_by' => $query->model->searchQueryBy(),
            ]);
        }

        return $query;
    }
}
