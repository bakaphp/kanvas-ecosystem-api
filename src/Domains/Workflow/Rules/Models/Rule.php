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
        return $this->belongsTo(SystemModules::class, 'systems_modules_id', 'id');
    }

    public function workflowActivities(): HasMany
    {
        return $this->hasMany(RuleAction::class, 'rules_id', 'id')->orderBy('weight', 'ASC');
    }

    public function getRulesConditions(): HasMany
    {
        return $this->hasMany(RuleCondition::class, 'rules_id', 'id');
    }

    protected static function newFactory()
    {
        return RuleFactory::new();
    }

    /**
     * Get the expression conditional to run the rule.
     *
     * [expression] => created_at > created_at_Variable
     * [value] => Array
     *   (
     *       [created_at_Variable] => 2020-01-01
     *   )
     */
    public function getExpressionCondition(): array
    {
        $conditions = $this->getRulesConditions()->get();
        $pattern = (string) $this->pattern;
        $variableExpression = 'Variable';
        $values = [];

        foreach ($conditions as $key => $conditionModel) {
            $attribute = trim($conditionModel->attribute_name);
            $operator = trim($conditionModel->operator);

            $condition = "$attribute $operator $attribute$variableExpression";
            $values["$attribute$variableExpression"] = $conditionModel->value;

            $pattern = str_replace((string) ($key + 1), $condition, $pattern);
        }

        $pattern = str_ireplace(['AND', 'OR'], ['and', 'or'], $pattern);

        return [
            'expression' => $pattern,
            'values' => $values,
        ];
    }
}
