<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Kanvas\AdminLinks\Enums\AdminLinkIdentifierEnum;
use Kanvas\Intelligence\Agents\Enums\ArtifactComponentEnum;
use Kanvas\Intelligence\Agents\Enums\ArtifactEntityTypeEnum;

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
     * One path segment and nothing else: the admin puts a slug straight into the record's URL.
     */
    private const string SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/iD';

    /**
     * The integer id as a list filter takes it. Zero is a value the admin accepts; a uuid never is.
     */
    public const string INTEGER_ID_PATTERN = '/^\d{1,15}$/D';

    /**
     * The props of the live components by the names a model also writes BESIDE `props`. The admin
     * lifts them in before it validates, so the filter over a reply has to as well.
     */
    public const array LIVE_PROP_KEYS = [
        'records' => ['type', 'filter', 'filters', 'search', 'limit'],
        'metric' => ['metric', 'window', 'as', 'chartKind', 'limit'],
    ];

    /**
     * @param array<string, mixed> $props
     * @return list<string>
     */
    public function errors(ArtifactComponentEnum $component, array $props): array
    {
        $errors = $this->checkObject($props, $component->props(), 'props');

        if ($errors !== []) {
            return $errors;
        }

        // Rules that span two props, so they only make sense once each prop is valid on its own.
        return match ($component) {
            ArtifactComponentEnum::CHART => $this->checkChartKeys($props),
            ArtifactComponentEnum::ENTITY => $this->checkEntityId($props),
            ArtifactComponentEnum::RECORDS => $this->checkRecords($props),
            default => [],
        };
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
     *
     * A reply that was nothing but the block comes back as written: an empty reply reads as the agent
     * going silent, and the admin draws its own fallback for a block it refuses.
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

                // A hand-written block can carry anything here, a list included.
                $name = $decoded['component'] ?? null;
                $component = is_string($name) ? ArtifactComponentEnum::tryFrom($name) : null;
                $props = $component !== null ? $this->propsAsTheAdminReadsThem($component, $decoded) : null;

                if ($component === null || $props === null) {
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
        // (approvals with no filter) is only valid with `{}`. The same goes for an empty row of a table
        // or a chart, which the admin takes as `{}` and refuses as `[]`.
        foreach (['rows', 'data'] as $rows) {
            if (is_array($props[$rows] ?? null)) {
                $props[$rows] = array_map(
                    static fn (mixed $row): mixed => $row === [] ? (object) [] : $row,
                    $props[$rows]
                );
            }
        }

        $block['props'] = (object) $props;

        return '```' . self::FENCE . "\n"
            . json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . "\n```";
    }

    /**
     * Whether the admin card can find a record of this type by this id.
     */
    public function cardReads(ArtifactEntityTypeEnum $type, string|int $id): bool
    {
        $id = is_int($id) ? (string) $id : trim($id);
        $lookups = $type->lookups();
        $shape = AdminLinkIdentifierEnum::shapeOf($id);

        // For a type that opens by slug the type decides: "2024" is a slug there too. A uuid is not —
        // the admin reads none.
        if ($lookups === [AdminLinkIdentifierEnum::SLUG]) {
            return preg_match(self::SLUG_PATTERN, $id) === 1 && $shape !== AdminLinkIdentifierEnum::UUID;
        }

        return $this->isRecordId($id) && in_array($shape, $lookups, true);
    }

    /**
     * `{}` and `[]` both decode to an empty PHP array, and both mean "no filter": written back it
     * would be `[]`, which the admin reads as a list.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    public function withoutEmptyFilter(array $props): array
    {
        if (($props['filter'] ?? null) === []) {
            unset($props['filter']);
        }

        return $props;
    }

    /**
     * The props of a hand-written block the way the admin reads them before it validates, or null when
     * they are not an object at all.
     *
     * The admin is lenient with the two components whose every prop narrows what is shown: it takes
     * props written beside `props`, the plural `filters`, an empty `[]` where "no filter" was meant and
     * a count sent as text. Holding the raw block to the tool's rules would strip a card the admin
     * draws. The block itself is left as written — the admin does this reading again.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private function propsAsTheAdminReadsThem(ArtifactComponentEnum $component, array $block): ?array
    {
        $liveKeys = self::LIVE_PROP_KEYS[$component->value] ?? null;
        $props = $block['props'] ?? null;

        if ($liveKeys === null) {
            return is_array($props) ? $props : null;
        }

        // No props at all: everything may be beside them. A list with entries is not an object.
        $props = array_key_exists('props', $block) ? $props : [];

        if (! is_array($props) || ($props !== [] && array_is_list($props))) {
            return null;
        }

        foreach ($liveKeys as $key) {
            if (array_key_exists($key, $block) && ! array_key_exists($key, $props)) {
                $props[$key] = $block[$key];
            }
        }

        // Short digit strings only: a longer one is no row count, and a cast that overflows is a
        // warning the framework turns into an exception, which would take the whole reply down.
        $limit = $props['limit'] ?? null;

        if (is_string($limit) && preg_match('/^\d{1,9}$/D', trim($limit)) === 1) {
            $props['limit'] = (int) trim($limit);
        }

        return $component === ArtifactComponentEnum::RECORDS ? $this->withOneFilter($props) : $props;
    }

    /**
     * A list's filter under its one name, and absent when it says nothing.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private function withOneFilter(array $props): array
    {
        // Both spellings at once are two answers to one question: `filters` stays, and is refused.
        if (array_key_exists('filters', $props) && ! array_key_exists('filter', $props)) {
            $props['filter'] = $props['filters'];
            unset($props['filters']);
        }

        return $this->withoutEmptyFilter($props);
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
            'integer_id' => $this->isIntegerId($value) ? [] : [$path . ' must be the numeric id, not the uuid'],
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
                fn (mixed $row, string $rowPath): array => $this->checkFlatRow(
                    $row,
                    $rowPath,
                    ($rule['booleans'] ?? true) === true
                )
            ),
            'list' => $this->checkList(
                $value,
                $rule,
                $path,
                fn (mixed $item, string $itemPath): array => is_array($item) && ! array_is_list($item)
                    ? $this->checkObject($item, $rule['item'], $itemPath)
                    : [$itemPath . ' must be an object']
            ),
            // Only that it is an object: its keys belong to the record type, a sibling prop.
            'filter' => is_array($value) && ($value === [] || ! array_is_list($value))
                ? []
                : [$path . ' must be an object of filters, e.g. {"statusId": 3}'],
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
    private function checkFlatRow(mixed $row, string $path, bool $booleans): array
    {
        if (! is_array($row) || ($row !== [] && array_is_list($row))) {
            return [$path . ' must be a flat object'];
        }

        foreach ($row as $key => $cell) {
            if (is_bool($cell) && ! $booleans) {
                return [sprintf('%s.%s must be a string, number or null — a chart plots no booleans', $path, $key)];
            }

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
     * The id has to be one the card can find the record by, or it draws a card that reads nothing. A
     * type the tool can look up arrives here already rewritten to what its page reads
     * (RenderArtifactTool); this is what holds everything else, hand-written blocks included.
     *
     * @param array<string, mixed> $props
     * @return list<string>
     */
    private function checkEntityId(array $props): array
    {
        $type = ArtifactEntityTypeEnum::from($props['type']);

        if ($this->cardReads($type, $props['id'])) {
            return [];
        }

        return match ($type->lookups()) {
            [AdminLinkIdentifierEnum::SLUG] => [
                'props.id must be the slug of the ' . $type->value . ', as a tool returned it — not its uuid or its name',
            ],
            [AdminLinkIdentifierEnum::ID] => [
                'props.id must be the numeric id of the ' . $type->value . ' — its card reads nothing else',
            ],
            default => [
                'props.id must be the numeric id or the uuid of the ' . $type->value . ', as a tool returned it',
            ],
        };
    }

    /**
     * A live list selects records, so a filter the type does not take is refused, never dropped:
     * without it the list would show every record of the type under a title about a few of them.
     *
     * @param array<string, mixed> $props
     * @return list<string>
     */
    private function checkRecords(array $props): array
    {
        $type = ArtifactEntityTypeEnum::from($props['type']);
        $filter = $props['filter'] ?? [];
        $allowed = $type->filters() ?? [];

        // The admin's search ignores every other clause, so the two together would show the search
        // alone while the title promised the filter too.
        if ($filter !== [] && array_key_exists('search', $props)) {
            return ['props.search cannot be combined with props.filter — send one or the other'];
        }

        // The API answers a search on these lists with an error, so the admin never runs one.
        if (array_key_exists('search', $props) && ! $type->searchable()) {
            return [sprintf(
                'props.search is not available for %s lists — narrow the list with a filter instead: %s',
                $type->value,
                implode(', ', array_keys($allowed))
            )];
        }

        $errors = [];

        foreach ($filter as $key => $value) {
            $rule = $allowed[$key] ?? null;

            if ($rule === null) {
                $errors[] = $allowed === []
                    ? sprintf('props.filter.%s is not a valid filter — %s lists take no filters', $key, $type->value)
                    : sprintf(
                        'props.filter.%s is not a filter of %s lists — allowed: %s',
                        $key,
                        $type->value,
                        implode(', ', array_keys($allowed))
                    );

                continue;
            }

            array_push($errors, ...$this->checkFilterValue($value, $rule, 'props.filter.' . $key));
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $rule
     * @return list<string>
     */
    private function checkFilterValue(mixed $value, array $rule, string $path): array
    {
        if (! is_array($value)) {
            return $this->checkValue($value, $rule, $path);
        }

        if (($rule['many'] ?? false) !== true) {
            return [$path . ' takes one value, not a list'];
        }

        return $this->checkList(
            $value,
            ['min' => 1, 'max' => ArtifactComponentEnum::MAX_FILTER_VALUES],
            $path,
            fn (mixed $item, string $itemPath): array => $this->checkValue($item, $rule, $itemPath)
        );
    }

    /**
     * @return list<string>
     */
    private function checkRecordId(mixed $value, string $path): array
    {
        return $this->isRecordId($value) ? [] : [$path . ' must be a numeric id or a uuid'];
    }

    /**
     * How Kanvas names a record: the integer id or the uuid. The admin sends each to its own column —
     * a uuid compared against an integer id is a cast ("5f1c…" reads as 5), not a miss — so anything
     * else is refused rather than drawn as a card that shows the wrong record.
     */
    private function isRecordId(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        if (! is_string($value)) {
            return false;
        }

        $id = trim($value);

        return match (AdminLinkIdentifierEnum::shapeOf($id)) {
            AdminLinkIdentifierEnum::ID => ltrim($id, '0') !== '',
            AdminLinkIdentifierEnum::UUID => true,
            default => false,
        };
    }

    /**
     * A list is narrowed by integer ids only: the column behind every list filter is an integer one,
     * and the admin refuses to run the list rather than cast a uuid into it.
     */
    private function isIntegerId(mixed $value): bool
    {
        $text = is_int($value) ? (string) $value : $value;

        return is_string($text) && preg_match(self::INTEGER_ID_PATTERN, trim($text)) === 1;
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
