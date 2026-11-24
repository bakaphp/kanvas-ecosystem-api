<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OneDrive\Actions;

use Kanvas\Connectors\Microsoft\OneDrive\Documents\ExcelDocument;
use Kanvas\Connectors\Microsoft\OneDrive\Documents\WordDocument;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveDocumentTypeEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use Kanvas\Connectors\Microsoft\OneDrive\OneDriveClient;

class ReadOneDriveDocumentAction
{
    public const int MAX_TEXT_CHARS = 20000;

    private const array PLAIN_TEXT_EXTENSIONS = ['txt', 'csv', 'md'];

    public function __construct(
        private readonly OneDriveClient $client,
        private readonly string $pathOrId,
        private readonly ?string $sheet = null,
        private readonly ?string $range = null
    ) {
    }

    public function execute(): array
    {
        $metadata = $this->client->getItem($this->pathOrId);
        $type = OneDriveDocumentTypeEnum::fromFileName($metadata['name']);
        $isText = in_array(strtolower(pathinfo($metadata['name'], PATHINFO_EXTENSION)), self::PLAIN_TEXT_EXTENSIONS, true);

        if ($type === null && ! $isText) {
            throw new OneDriveException(
                OneDriveErrorReasonEnum::UNSUPPORTED_TYPE,
                'Only .docx, .xlsx, .txt, .csv and .md files can be read'
            );
        }

        $downloaded = $this->client->download($metadata);
        $item = $downloaded['item'];
        $content = $downloaded['content'];

        if ($type === OneDriveDocumentTypeEnum::EXCEL) {
            return ['item' => $item, 'type' => 'excel'] + ExcelDocument::read($content, $this->sheet, $this->range);
        }

        $text = $type === OneDriveDocumentTypeEnum::WORD
            ? WordDocument::readText($content)
            : mb_scrub($content, 'UTF-8');
        $truncated = mb_strlen($text) > self::MAX_TEXT_CHARS;

        return [
            'item' => $item,
            'type' => $type === null ? 'text' : 'word',
            'text' => $truncated ? mb_substr($text, 0, self::MAX_TEXT_CHARS) : $text,
            'truncated' => $truncated,
        ];
    }
}
