<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Models;

use Baka\Traits\DynamicSearchableTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\AdminLinks\Traits\HasAdminLink;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
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
    use HasAdminLink;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }

    protected $table = 'rules';

    protected $guarded = [];

    protected $casts = [
        'params' => 'array',
        'is_async' => 'boolean',
    ];

    #[Override]
    public function adminLinkSection(): AdminLinkSectionEnum
    {
        return AdminLinkSectionEnum::RULE;
    }

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);

        return config('scout.prefix') . ($app->get('app_custom_rule_index') ?? 'rule_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'objectID' => (string) $this->id,
            'id' => (string) $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
        ];
    }

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
                    'name' => 'name',
                    'type' => 'string',
                ],
                [
                    'name' => 'description',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'apps_id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'companies_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
            ],
        ];
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();

        if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
            $query->where('companies_id', app(CompaniesBranches::class)->company->getId());
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        if ($query->model->isTypesense()) {
            $query->options(['query_by' => 'name,description']);
        }

        return $query;
    }

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

            // `in` / `not in` require an array on the right side. The stored value has no cast,
            // so an array saved as `[1, 2]` comes back as the string "[1,2]" — normalize both
            // shapes into an expression array literal so Symfony's in_array() gets an array.
            if (is_array($value) || in_array(strtolower($operator), ['in', 'not in'], true)) {
                $condition = sprintf('%s %s [%s]', $attribute, $operator, $this->formatArrayValue($value));
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
     * Build the comma-separated inner body of an expression array literal (`[...]`) from a value
     * that may already be a PHP array or a stored string such as "[1, 2]", "1,2" or "'a','b'".
     * Each element is run through formatValue so numerics stay unquoted (strict in_array matching)
     * and strings are quoted.
     */
    private function formatArrayValue(mixed $value): string
    {
        if (is_array($value)) {
            $elements = $value;
        } else {
            $normalized = trim((string) $value);
            $normalized = trim($normalized, '[]');

            $elements = $normalized === ''
                ? []
                : array_map(
                    fn (string $item) => trim(trim($item), "'\""),
                    explode(',', $normalized)
                );
        }

        return implode(', ', array_map(fn ($item) => $this->formatValue($item), $elements));
    }

    /**
     * Format value for expression based on its type
     */
    private function formatValue(string|int|float|bool|null $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        $lower = strtolower(trim($value));
        if ($lower === 'true' || $lower === 'false' || $lower === 'null') {
            return $lower;
        }

        if (is_numeric($value)) {
            return $value;
        }

        return "'" . addslashes($value) . "'";
    }
}
