<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\Models\Rule;

class EntityIntegrationHistory extends BaseModel
{
    protected $table = 'entity_integration_history';

    protected $fillable = [
        'entity_namespace',
        'apps_id',
        'entity_id',
        'integrations_company_id',
        'integrations_id',
        'status_id',
        'response',
        'exception',
        'workflow_id',
        'rules_id'
    ];

    protected $casts = [
        'response' => Json::class,
        'exception' => Json::class,
        'is_deleted' => 'boolean',
    ];

    public function integrationCompany(): BelongsTo
    {
        return $this->belongsTo(IntegrationsCompany::class, 'integrations_company_id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integrations::class, 'integrations_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo($this->entity_namespace, 'entity_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(StoredWorkflow::class, 'workflow_id');
    }

    public function rules(): BelongsTo
    {
        return $this->belongsTo(Rule::class, 'rules_id', 'id');
    }

    public function setStatus(Status $status): void
    {
        $this->status_id = $status->getId();
        $this->saveOrFail();
    }

    public function getTrigger(): ?string
    {
        return $this->rules?->type->name;
    }
}
