<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Rules\Factories\RuleFactory;

/**
 * @param int $id
 * @param int $systems_modules_id
 * @param int $companies_id
 * @param int $apps_id
 * @param int $rules_types_id
 * @param string $name
 * @param string $description
 * @param string $pattern
 * @param array $params
 * @param bool $is_async
 */
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

    public function runAsync(): bool
    {
        return $this->is_async;
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
        $values = [];

        foreach ($conditions as $key => $conditionModel) {
            $attribute = trim($conditionModel->attribute_name);
            $operator = trim($conditionModel->operator);
            $value = $conditionModel->value;

            // Detect if the attribute is an array key
            if (strpos($attribute, '[') !== false && strpos($attribute, ']') !== false) {
                $attribute = preg_replace_callback('/\[(.*?)\]/', function ($matches) {
                    return "['" . trim($matches[1], "'\"") . "']";
                }, $attribute);
            }

            if (is_array($value)) {
                // Handle array operators
                $condition = sprintf('%s %s [%s]', $attribute, $operator, implode(', ', array_map(fn ($v) => "'$v'", $value)));
            } else {
                // Replace placeholders directly
                $condition = sprintf("%s %s '%s'", $attribute, $operator, $value);
            }

            // Replace the pattern placeholder
            $pattern = str_replace((string) ($key + 1), $condition, $pattern);
        }

        // Normalize AND/OR keywords
        $pattern = str_ireplace(['AND', 'OR'], ['and', 'or'], $pattern);

        return [
            'expression' => $pattern,
            'values' => $values, // Values are no longer used in the expression
        ];
    }
}
