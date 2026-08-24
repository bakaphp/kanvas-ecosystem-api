<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Common;

use Illuminate\Support\Str;
use Kanvas\Connectors\Apollo\Services\CsvExportService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DecodesJsonObjectParam;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

use function is_array;
use function is_bool;
use function is_string;

/**
 * Writes a caller-authored table to CSV. Its sibling export_records fixes the columns per record type
 * so a model can't launder a guess as a database lookup; this one takes arbitrary columns precisely
 * because the rows came from the user rather than the DB. Keeping them apart is what preserves that
 * guarantee — don't bolt free-form columns onto a record exporter.
 */
#[AgentTool(name: 'Export Table', category: 'common')]
class ExportTableTool extends Tool
{
    use DecodesJsonObjectParam;
    use HasKanvasContext;

    public const int MAX_ROWS = 5000;
    public const int MAX_COLUMNS = 60;

    public function __construct()
    {
        parent::__construct(
            name: 'export_table',
            description: 'Write a table you already have into a downloadable CSV and return its file URL + row '
                . 'count. Use it when the rows come from the user (a pasted list, a table built during this '
                . 'conversation) and they want it as a file with their own columns. It performs NO lookup — it '
                . 'only writes down what you pass it, so never use it to answer whether a record exists. For a '
                . 'list that has to be read out of Kanvas (people, orders, products, employees, …) use '
                . 'export_records instead.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'columns',
                type: PropertyType::STRING,
                description: 'The column headers in order, comma-separated (or as a JSON array of strings). '
                    . 'Written to the file exactly as given, so use the user\'s own wording and language.',
                required: true,
            ),
            new ToolProperty(
                name: 'rows',
                type: PropertyType::STRING,
                description: 'The table body as a JSON array. Each element is either an array of cell values in '
                    . 'the same order as columns, or an object keyed by column name. Pass "" for a cell the user '
                    . 'left blank — never invent a value to fill it.',
                required: true,
            ),
            new ToolProperty(
                name: 'filename',
                type: PropertyType::STRING,
                description: 'Optional name for the file, e.g. "lideres-rrhh-rd". Defaults to "table"; the date '
                    . 'and .csv extension are added automatically.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(array|string $columns, array|string $rows, ?string $filename = null): array
    {
        $headers = $this->parseColumns($columns);
        $body = $this->decodeJsonObjectParam($rows);

        if ($headers === [] || count($headers) > self::MAX_COLUMNS) {
            return ['error' => sprintf(
                'columns must be 1 to %d header names, comma-separated or as a JSON array.',
                self::MAX_COLUMNS,
            )];
        }

        if ($body === [] || count($body) > self::MAX_ROWS) {
            return ['error' => sprintf(
                'rows must be a JSON array of 1 to %d rows. Use export_records for a longer list.',
                self::MAX_ROWS,
            )];
        }

        $cells = [];

        foreach (array_values($body) as $index => $row) {
            // A row wider than the headers means the model lost count, and padding or truncating there
            // would shift every cell one column over — a short row is only omitted trailing blanks.
            if (! is_array($row) || count($row) > count($headers)) {
                return ['error' => sprintf(
                    'Row %d must hold at most %d cells, one per column in the same order, "" for blanks.',
                    $index + 1,
                    count($headers),
                )];
            }

            $cells[] = $this->normalizeRow($row, $headers);
        }

        return app(CsvExportService::class)->export(
            $this->app,
            $this->company,
            $this->user,
            $this->filenamePrefix($filename),
            $headers,
            $cells,
        );
    }

    /**
     * @return list<string>
     */
    private function parseColumns(array|string $columns): array
    {
        $parts = is_string($columns) && ! str_starts_with(trim($columns), '[')
            ? explode(',', $columns)
            : $this->decodeJsonObjectParam($columns);

        $headers = array_map(
            fn (mixed $column): string => is_array($column) ? '' : trim((string) $column),
            $parts,
        );

        return array_values(array_filter($headers, fn (string $header): bool => $header !== ''));
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<string> $headers
     *
     * @return list<string>
     */
    private function normalizeRow(array $row, array $headers): array
    {
        if (! array_is_list($row)) {
            return array_map(fn (string $header): string => $this->cell($row[$header] ?? ''), $headers);
        }

        return array_map(fn (int $index): string => $this->cell($row[$index] ?? ''), array_keys($headers));
    }

    private function cell(mixed $value): string
    {
        return match (true) {
            is_array($value) => implode(', ', array_map($this->cell(...), $value)),
            is_bool($value) => $value ? 'true' : 'false',
            default => trim((string) $value),
        };
    }

    private function filenamePrefix(?string $filename): string
    {
        $slug = Str::slug((string) $filename);

        return $slug === '' ? 'table' : Str::limit($slug, 60, '');
    }
}
