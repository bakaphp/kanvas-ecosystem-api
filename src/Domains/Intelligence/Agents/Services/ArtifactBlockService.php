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
    /**
     * Drop any `kanvas-artifact` block in a reply that the client would refuse to draw.
     *
     * Validating inside `render_artifact` only covers blocks the tool produced. Nothing stops a
     * model writing the fence by hand, and when it does every check here is bypassed — a reply
     * shipped an `actions` block whose third item was the assistant's own closing question
     * ("¿Deseas profundizar en alguna de estas empresas…") sitting where a button label goes. The
     * client rejected the artifact, so the person lost the whole card rather than the bad item.
     *
     * A malformed block is removed and its prose kept, which is the failure the reader can still
     * use. Repairing it is deliberately not attempted: truncating that question to 40 characters
     * produces a button that reads like a glitch, and silently dropping list entries would edit
     * the data a chart or table is asserting.
     */
    public function stripInvalidBlocks(string $reply): string
    {
        $pattern = '/```' . preg_quote(self::FENCE, '/') . '\s*\n(.*?)\n?```/s';

        return (string) preg_replace_callback(
            $pattern,
            function (array $match): string {
                $decoded = json_decode(trim($match[1]), true);

                if (! is_array($decoded)) {
                    return '';
                }

                $component = ArtifactComponentEnum::tryFrom((string) ($decoded['component'] ?? ''));
                $props = $decoded['props'] ?? null;

                if ($component === null || ! is_array($props)) {
                    return '';
                }

                return $this->errors($component, $props) === [] ? $match[0] : '';
            },
            $reply
        ) ?: $reply;
    }

    public function render(ArtifactComponentEnum $component, ?string $title, array $props): string
    {
        $block = ['version' => 1, 'component' => $component->value];

        if ($title !== null && trim($title) !== '') {
            $block['title'] = trim($title);
        }

        $block['props'] = $props;

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
            'bool' => is_bool($value) ? [] : [$path . ' must be true or false'],
            'id' => is_int($value) || (is_string($value) && trim($value) !== '' && $this->hasNoFence($value))
                ? []
                : [$path . ' must be the record id'],
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
     * A `maxLength` here is a rendering contract, not a preference.
     *
     * The client validates the same block with its own schema and refuses to draw it if a field
     * is over — an action label at 42 characters came back as
     * `items.0.label: Too big: expected string to have <=40 characters`, and the whole artifact
     * was dropped after the agent had already spent the turn building it. Checking it here turns
     * a dead render into an error the model can act on while it still has the turn, and the
     * limit is repeated in the component's `usage()` so it usually never gets this far.
     *
     * @param array<string, mixed> $rule
     * @return list<string>
     */
    private function checkString(mixed $value, array $rule, string $path): array
    {
        if (! is_string($value) || ! $this->hasNoFence($value)) {
            return [$path . ' must be a string without ```'];
        }

        $max = $rule['maxLength'] ?? null;

        if ($max !== null && mb_strlen($value) > $max) {
            return [sprintf(
                '%s is %d characters; the renderer caps it at %d — shorten it, do not drop the entry',
                $path,
                mb_strlen($value),
                $max
            )];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $rule
     * @param callable(mixed, string): list<string> $checkItem
     * @return list<string>
     */
    private function checkList(
        mixed $value,
        array $rule,
        string $path,
        callable $checkItem
    ): array {
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

    private function isTextOrNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && $this->hasNoFence($value));
    }

    private function hasNoFence(string $value): bool
    {
        return ! str_contains($value, '```');
    }
}
