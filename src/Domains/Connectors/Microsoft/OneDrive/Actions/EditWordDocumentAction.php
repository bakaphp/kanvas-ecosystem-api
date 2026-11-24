<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OneDrive\Actions;

use Kanvas\Connectors\Microsoft\OneDrive\Actions\Concerns\EditsOneDriveDocument;
use Kanvas\Connectors\Microsoft\OneDrive\Documents\WordDocument;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveDocumentTypeEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use Kanvas\Connectors\Microsoft\OneDrive\OneDriveClient;

class EditWordDocumentAction
{
    use EditsOneDriveDocument;

    public function __construct(
        private readonly OneDriveClient $client,
        private readonly string $pathOrId,
        private readonly array $replacements,
        private readonly array $appendParagraphs
    ) {
    }

    public function execute(): array
    {
        if ($this->replacements === [] && $this->appendParagraphs === []) {
            throw new OneDriveException(OneDriveErrorReasonEnum::REQUEST_FAILED, 'Nothing to change');
        }

        $downloaded = $this->downloadForEdit($this->client, $this->pathOrId, OneDriveDocumentTypeEnum::WORD);
        $bytes = $downloaded['content'];
        $replaced = [];

        if ($this->replacements !== []) {
            $result = WordDocument::replaceText($bytes, $this->replacements);
            $bytes = $result['bytes'];
            $replaced = $result['replaced'];
        }

        if ($this->appendParagraphs !== []) {
            $bytes = WordDocument::appendParagraphs($bytes, $this->appendParagraphs);
        }

        return [
            'item' => $this->replaceEdited($this->client, $downloaded['item'], $bytes),
            'replaced' => $replaced,
        ];
    }
}
