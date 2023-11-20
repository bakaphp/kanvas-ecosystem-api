<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Rules\Factories\RuleActionFactory;

class RuleAction extends BaseModel
{
    protected $table = 'rules_actions';

    protected $guarded = [];

    public function rules(): BelongsTo
    {
        return $this->belongsTo(Rule::class, 'rules_id', 'id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(RuleWorkflowAction::class, 'rules_workflow_actions_id', 'id');
    }

    protected static function newFactory()
    {
        return RuleActionFactory::new();
    }
}
