<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OneDrive\Actions;

use Kanvas\Connectors\Microsoft\OneDrive\Actions\Concerns\EditsOneDriveDocument;
use Kanvas\Connectors\Microsoft\OneDrive\Documents\ExcelDocument;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveDocumentTypeEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use Kanvas\Connectors\Microsoft\OneDrive\OneDriveClient;

class EditExcelDocumentAction
{
    use EditsOneDriveDocument;

    public function __construct(
        private readonly OneDriveClient $client,
        private readonly string $pathOrId,
        private readonly ?string $sheet,
        private readonly array $cells,
        private readonly array $appendRows
    ) {
    }

    public function execute(): array
    {
        if ($this->cells === [] && $this->appendRows === []) {
            throw new OneDriveException(OneDriveErrorReasonEnum::REQUEST_FAILED, 'Nothing to change');
        }

        $downloaded = $this->downloadForEdit($this->client, $this->pathOrId, OneDriveDocumentTypeEnum::EXCEL);
        $bytes = ExcelDocument::write(
            $downloaded['content'],
            $this->sheet,
            $this->cells,
            $this->appendRows
        );

        return [
            'item' => $this->replaceEdited($this->client, $downloaded['item'], $bytes),
        ];
    }
}
