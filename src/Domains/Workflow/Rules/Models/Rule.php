<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Rules\Factories\RuleFactory;

class Rule extends BaseModel
{
    protected $table = 'rules';

    protected $guarded = [];

    protected $casts = [
        'params' => 'array',
        'is_async' => 'boolean',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(RuleType::class, 'rules_types_id', 'id');
    }

    public function systemModule(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(SystemModules::class, 'systems_modules_id', 'id');
    }

    public function workflowActivities(): HasMany
    {
        return $this->hasMany(RuleAction::class, 'rules_id', 'id')->orderBy('weight', 'ASC');
    }

    protected static function newFactory()
    {
        return RuleFactory::new();
    }
}
