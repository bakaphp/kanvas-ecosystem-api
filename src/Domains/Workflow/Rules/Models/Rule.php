<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Rules\Factories\RuleFactory;
use Override;

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

    #[Override]
    protected static function newFactory()
    {
        return RuleFactory::new();
    }

    /**
     * Get the expression conditional to run the rule.
     *
     * [expression] => id > 0 and order.items.count() > 2
     * [value] => Array (empty since values are resolved dynamically)
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
                $condition = sprintf('%s %s [%s]', $attribute, $operator, implode(', ', array_map(fn ($v) => "'$v'", $value)));
            } else {
                $formattedValue = $this->formatValue($value);
                $condition = sprintf('%s %s %s', $attribute, $operator, $formattedValue);
            }

            // Use word boundary regex to replace only complete tokens
            $placeholder = (string) ($key + 1);
            $pattern = preg_replace('/\b' . preg_quote($placeholder, '/') . '\b/', $condition, $pattern);
            // This becomes: preg_replace('/\b1\b/', "message_types_id == '572'", "1 or 2")
        }

        $pattern = str_ireplace(['AND', 'OR'], ['and', 'or'], $pattern);

        return [
            'expression' => $pattern,
            'values' => $values,
        ];
    }

    /**
     * Format value for expression based on its type
     */
    private function formatValue(string|int|float|bool|null $value): string
    {
        // If it's numeric, don't quote it
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // If it's a boolean, convert to string
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        // If it's null
        if ($value === null) {
            return 'null';
        }

        // Everything else gets quoted (strings)
        return "'" . addslashes($value) . "'";
    }
}
