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
    private const string RECORD_ID_PATTERN = '/^(0*[1-9]\d*|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i';

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
     * A list over its cap is cut to the cap instead of refused. The cap is a rendering contract (see checkString), but
     * the model kept sending 7 or 8 entity fields against a cap of 6 and each refusal cost a model round before the
     * same card came back trimmed by hand; cutting it here and saying so costs nothing. Only top-level lists: a nested
     * list's cap is the renderer's, and a bad item is still an error the model fixes.
     *
     * @param array<string, mixed> $props
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function truncateLists(ArtifactComponentEnum $component, array $props): array
    {
        $notes = [];

        foreach ($component->props() as $key => $rule) {
            $max = $rule['max'] ?? null;
            $value = $props[$key] ?? null;

            if ($max === null
                || ! in_array($rule['type'] ?? null, ['list', 'rows', 'record_id_list'], true)
                || ! is_array($value)
                || ! array_is_list($value)
                || count($value) <= $max
            ) {
                continue;
            }

            $notes[] = sprintf(
                'props.%s had %d entries; the first %d were kept, say the rest in prose',
                $key,
                count($value),
                $max
            );
            $props[$key] = array_slice($value, 0, $max);
        }

        return [$props, $notes];
    }

    public const string UNISSUED_NOTICE = '_A card in this reply was removed: it showed a record no tool produced in this '
        . 'turn, so nothing on it was done in Kanvas._';

    /**
     * Keep only the blocks a tool returned in this turn. A card is how the admin chat shows a record, and a model
     * writing one by hand can show a record that does not exist: an "AR Invoice Created" card for an invoice id no
     * tool ever returned, in a turn that called no tool, and the person then asked for its PDF. `render_artifact`
     * returns the exact block it validated, so any other block is the model's own. When one is removed the reply
     * says so, so the prose around it is not read as done.
     *
     * Compared as decoded JSON, so a block pasted with different whitespace still counts as the tool's.
     *
     * @param list<array<string, mixed>> $toolResults the turn's serialized tool results
     */
    public function stripUnissuedBlocks(string $reply, array $toolResults): string
    {
        $issued = [];
        array_walk_recursive($toolResults, function (mixed $value) use (&$issued): void {
            if (is_string($value)) {
                $issued = [...$issued, ...$this->blocksIn($value)];
            }
        });

        $removed = false;
        $kept = (string) preg_replace_callback(
            $this->blockPattern(),
            function (array $match) use ($issued, &$removed): string {
                $block = json_decode(trim($match[1]), true);

                if (is_array($block) && in_array($block, $issued, false)) {
                    return $match[0];
                }

                $removed = true;

                return '';
            },
            $reply
        );

        return $removed ? rtrim($kept) . "\n\n" . self::UNISSUED_NOTICE : $reply;
    }

    /**
     * History replays a card as one line naming it. A model shown its own past "AR Invoice Created" cards copies
     * the shape of success without doing the work (it once wrote one for an invoice it never created); the marker
     * keeps what the card said happened, with its record id, and nothing to copy. Costs a few tokens, not a card.
     */
    public function asHistoryMarkers(string $text): string
    {
        if (! str_contains($text, self::FENCE)) {
            return $text;
        }

        return (string) preg_replace_callback(
            $this->blockPattern(),
            function (array $match): string {
                $block = json_decode(trim($match[1]), true);
                $props = is_array($block) && is_array($block['props'] ?? null) ? $block['props'] : [];
                $title = is_array($block) ? trim((string) ($block['title'] ?? $props['title'] ?? $block['component'] ?? 'card')) : 'card';
                $id = $props['id'] ?? null;

                return '[card shown: ' . $title . (is_scalar($id) && (string) $id !== '' ? " #{$id}" : '') . ']';
            },
            $text
        );
    }

    /**
     * The decoded blocks inside a tool result, which may itself be a JSON string carrying the block.
     *
     * @return list<array<string, mixed>>
     */
    private function blocksIn(string $value): array
    {
        if (! str_contains($value, self::FENCE)) {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $blocks = [];
            array_walk_recursive($decoded, function (mixed $inner) use (&$blocks): void {
                if (is_string($inner)) {
                    $blocks = [...$blocks, ...$this->blocksIn($inner)];
                }
            });

            return $blocks;
        }

        preg_match_all($this->blockPattern(), $value, $matches);

        return array_values(array_filter(array_map(
            fn (string $json): mixed => json_decode(trim($json), true),
            $matches[1]
        ), 'is_array'));
    }

    private function blockPattern(): string
    {
        return '/```' . preg_quote(self::FENCE, '/') . '\s*\n(.*?)\n?```/s';
    }

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
        return (string) preg_replace_callback(
            $this->blockPattern(),
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
            'record_id' => $this->checkRecordId($value, $path),
            'record_id_list' => $this->checkList(
                $value,
                $rule,
                $path,
                $this->checkRecordId(...)
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

        if (($rule['nonEmpty'] ?? false) === true && trim($value) === '') {
            return [$path . ' must not be empty'];
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

    /**
     * @return list<string>
     */
    private function checkRecordId(mixed $value, string $path): array
    {
        return $this->isRecordId($value) ? [] : [$path . ' must be a numeric id or a uuid'];
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
