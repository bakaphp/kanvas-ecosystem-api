<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Kanvas\Intelligence\Agents\Enums\ArtifactComponentEnum;

/**
 * Validates an artifact against the admin chat's component contract and renders the fenced block.
 *
 * Errors name the exact path and the fix ("props.stats is not a valid prop — allowed: items") because
 * the model reads them and retries; the commonest miss is naming the array after the component.
 */
class ArtifactBlockService
{
    public const string FENCE = 'kanvas-artifact';

    /**
     * How Kanvas names a record: the integer id or the uuid. The admin card sends each to its own column —
     * a uuid compared against an integer id is a cast ("5f1c…" reads as 5), not a miss — so anything else
     * is refused here rather than rendered as a card that shows the wrong record.
     */
    private const string RECORD_ID_PATTERN = '/^(\d+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i';

    /**
     * @param array<string, mixed> $props
     * @return list<string>
     */
    public function errors(ArtifactComponentEnum $component, array $props): array
    {
        $errors = $this->checkObject($props, $component->props(), 'props');

        if ($errors === [] && $component === ArtifactComponentEnum::CHART) {
            $errors = $this->checkChartKeys($props);
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $props
     */
    public function render(ArtifactComponentEnum $component, ?string $title, array $props): string
    {
        $block = ['version' => 1, 'component' => $component->value];

        if ($title !== null && trim($title) !== '') {
            $block['title'] = trim($title);
        }

        // As an object: an empty PHP array encodes as `[]`, and a component whose props are all optional
        // (approvals with no filter) is only valid with `{}`.
        $block['props'] = (object) $props;

        return '```' . self::FENCE . "\n"
            . json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . "\n```";
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, array<string, mixed>> $spec
     * @return list<string>
     */
    private function checkObject(array $value, array $spec, string $path): array
    {
        $errors = [];

        foreach (array_keys($value) as $key) {
            if (! isset($spec[$key])) {
                $errors[] = sprintf(
                    '%s.%s is not a valid prop — allowed: %s',
                    $path,
                    $key,
                    implode(', ', array_keys($spec))
                );
            }
        }

        foreach ($spec as $key => $rule) {
            if (! array_key_exists($key, $value)) {
                if (($rule['required'] ?? false) === true) {
                    $errors[] = sprintf('%s.%s is required', $path, $key);
                }

                continue;
            }

            array_push($errors, ...$this->checkValue($value[$key], $rule, $path . '.' . $key));
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $rule
     * @return list<string>
     */
    private function checkValue(mixed $value, array $rule, string $path): array
    {
        return match ($rule['type']) {
            'string' => $this->checkString($value, $rule, $path),
            'number' => is_int($value) || is_float($value) ? [] : [$path . ' must be a number'],
            'integer' => is_int($value) && $value >= $rule['min'] && $value <= $rule['max']
                ? []
                : [sprintf('%s must be a whole number from %d to %d', $path, $rule['min'], $rule['max'])],
            'bool' => is_bool($value) ? [] : [$path . ' must be true or false'],
            'id' => is_int($value) || (is_string($value) && trim($value) !== '' && $this->hasNoFence($value))
                ? []
                : [$path . ' must be the record id'],
            'record_id' => $this->isRecordId($value) ? [] : [$path . ' must be a numeric id or a uuid'],
            'record_id_list' => $this->checkList(
                $value,
                $rule,
                $path,
                fn (mixed $item, string $itemPath): array => $this->isRecordId($item)
                    ? []
                    : [$itemPath . ' must be a numeric id or a uuid']
            ),
            'text_or_number' => $this->isTextOrNumber($value) ? [] : [$path . ' must be a string or a number'],
            'scalar' => $value === null || is_bool($value) || $this->isTextOrNumber($value)
                ? []
                : [$path . ' must be a string, number, boolean or null'],
            'enum' => in_array($value, $rule['values'], true)
                ? []
                : [sprintf('%s must be one of: %s', $path, implode(', ', $rule['values']))],
            'rows' => $this->checkList(
                $value,
                $rule,
                $path,
                $this->checkFlatRow(...)
            ),
            'list' => $this->checkList(
                $value,
                $rule,
                $path,
                fn (mixed $item, string $itemPath): array => is_array($item) && ! array_is_list($item)
                    ? $this->checkObject($item, $rule['item'], $itemPath)
                    : [$itemPath . ' must be an object']
            ),
        };
    }

    /**
     * @param array<string, mixed> $rule
     * @param callable(mixed, string): list<string> $checkItem
     * @return list<string>
     */
    private function checkList(mixed $value, array $rule, string $path, callable $checkItem): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [$path . ' must be an array'];
        }

        $count = count($value);
        if ($count < $rule['min'] || $count > $rule['max']) {
            return [sprintf(
                '%s has %d entries; it needs %d to %d — truncate and say so in prose',
                $path,
                $count,
                $rule['min'],
                $rule['max']
            )];
        }

        $errors = [];
        foreach ($value as $index => $item) {
            array_push($errors, ...$checkItem($item, sprintf('%s[%d]', $path, $index)));
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function checkFlatRow(mixed $row, string $path): array
    {
        if (! is_array($row) || ($row !== [] && array_is_list($row))) {
            return [$path . ' must be a flat object'];
        }

        foreach ($row as $key => $cell) {
            if (! ($cell === null || is_bool($cell) || $this->isTextOrNumber($cell))) {
                return [sprintf('%s.%s must be a string, number, boolean or null — rows are flat', $path, $key)];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $props
     * @return list<string>
     */
    private function checkChartKeys(array $props): array
    {
        $fields = [];
        foreach ($props['data'] as $row) {
            $fields += array_flip(array_keys($row));
        }

        $keys = [$props['xKey'], ...array_column($props['series'], 'key')];
        $missing = array_values(array_filter($keys, fn (string $key): bool => ! isset($fields[$key])));

        return $missing === []
            ? []
            : [sprintf('props.data has no field named %s — xKey and series keys must be fields of data', implode(', ', $missing))];
    }

    /**
     * @param array<string, mixed> $rule
     * @return list<string>
     */
    private function checkString(mixed $value, array $rule, string $path): array
    {
        if (! is_string($value) || ! $this->hasNoFence($value)) {
            return [$path . ' must be a string without ```'];
        }

        if (($rule['non_empty'] ?? false) === true && trim($value) === '') {
            return [$path . ' must not be empty'];
        }

        if (isset($rule['max_length']) && mb_strlen(trim($value)) > $rule['max_length']) {
            return [sprintf('%s must be at most %d characters', $path, $rule['max_length'])];
        }

        return [];
    }

    private function isRecordId(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value) && preg_match(self::RECORD_ID_PATTERN, trim($value)) === 1;
    }

    private function isTextOrNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && $this->hasNoFence($value));
    }

    private function hasNoFence(string $value): bool
    {
        return ! str_contains($value, '```');
    }
}
