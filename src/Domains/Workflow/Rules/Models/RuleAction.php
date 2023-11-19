<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Workflow\Models\BaseModel;

class RuleAction extends BaseModel
{
    protected $table = 'rules_actions';

    protected $guarded = [];

    public function rules(): BelongsTo
    {
        return $this->belongsTo(Rule::class, 'rules_id', 'id');
    }

    public function workflowAction(): BelongsTo
    {
        return $this->belongsTo(RuleWorkflowAction::class, 'rules_workflow_actions_id', 'id');
    }
}
