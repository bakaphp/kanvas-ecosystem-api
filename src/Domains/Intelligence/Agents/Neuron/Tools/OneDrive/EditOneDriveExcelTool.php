<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive;

use Kanvas\Connectors\Microsoft\OneDrive\Actions\EditExcelDocumentAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\Concerns\CallsOneDrive;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Edit OneDrive Excel Workbook', category: 'productivity')]
class EditOneDriveExcelTool extends Tool
{
    use CallsOneDrive;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'onedrive_edit_excel';

    protected ?string $description = 'Edits an .xlsx workbook in the connected OneDrive by setting cell values and/or '
        . 'appending rows after the last used row. Without a sheet name it edits the first sheet. Workbooks with '
        . 'features that editing would lose (pivot tables, threaded comments, data connections, macros, ...) are '
        . 'refused (`unsupported_type`). If the result is `file_changed`, the file was modified meanwhile: read the '
        . 'document again before retrying.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'path_or_id',
                type: PropertyType::STRING,
                description: 'Path starting with "/" (e.g. "/Finanzas/ventas.xlsx") or an item id.',
                required: true,
            ),
            new ToolProperty(
                name: 'cells',
                type: PropertyType::STRING,
                description: 'JSON object mapping A1 cell to its new value, e.g. \'{"B2": 100, "C2": "Pagado"}\'.',
                required: false,
            ),
            new ToolProperty(
                name: 'append_rows',
                type: PropertyType::STRING,
                description: 'JSON list of rows (each a list of values) to append, e.g. \'[["Ana", 500], ["Luis", 300]]\'.',
                required: false,
            ),
            new ToolProperty(
                name: 'sheet',
                type: PropertyType::STRING,
                description: 'Sheet name to edit. Defaults to the first sheet.',
                required: false,
            ),
            $this->driveProperty(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $path_or_id,
        ?string $cells = null,
        ?string $append_rows = null,
        ?string $sheet = null,
        ?string $drive = null
    ): array {
        return $this->attempt(function () use ($path_or_id, $cells, $append_rows, $sheet, $drive) {
            $cellMap = $this->decodeCells($cells, 'cells');
            $rows = $this->decodeRows($append_rows, 'append_rows');

            return new EditExcelDocumentAction(
                $this->oneDrive($drive),
                $path_or_id,
                $sheet,
                $cellMap,
                $rows,
            )->execute();
        });
    }
}
