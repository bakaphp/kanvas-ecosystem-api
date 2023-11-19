<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Rules\Factories\RuleWorkflowActionFactory;

class RuleWorkflowAction extends BaseModel
{
    protected $table = 'rules_workflow_actions';

    protected $guarded = [];

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'actions_id', 'id');
    }

    public function actionName(): string
    {
        return $this->action->name;
    }

    public function actionClass(): string
    {
        return $this->action->model_name;
    }

    public function systemModule(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(SystemModules::class, 'systems_modules_id', 'id');
    }

    protected static function newFactory()
    {
        return RuleWorkflowActionFactory::new();
    }
}
