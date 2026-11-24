<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OneDrive\Actions;

use Kanvas\Connectors\Microsoft\OneDrive\Documents\ExcelDocument;
use Kanvas\Connectors\Microsoft\OneDrive\Documents\WordDocument;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveDocumentTypeEnum;
use Kanvas\Connectors\Microsoft\OneDrive\OneDriveClient;

class CreateOneDriveDocumentAction
{
    public function __construct(
        private readonly OneDriveClient $client,
        private readonly OneDriveDocumentTypeEnum $type,
        private readonly string $name,
        private readonly ?string $folder,
        private readonly array $content
    ) {
    }

    public function execute(): array
    {
        $extension = '.' . $this->type->extension();
        $name = str_ends_with(strtolower($this->name), $extension) ? $this->name : $this->name . $extension;

        $bytes = match ($this->type) {
            OneDriveDocumentTypeEnum::WORD => WordDocument::create($this->content),
            OneDriveDocumentTypeEnum::EXCEL => ExcelDocument::create($this->content),
        };

        return ['item' => $this->client->createFile($this->folder, $name, $bytes)];
    }
}
