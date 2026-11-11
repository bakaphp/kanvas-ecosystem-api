<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\AttachesFileToDocumentForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesScribeQuoteForTool;
use Kanvas\Scribe\Quotes\Sow\Services\QuoteSowPdfService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/** Attaches a file to a quote in Kanvas Filesystem: a signed or customer-supplied SOW, or any other attachment. */
#[AgentTool(name: 'Attach Quote File', category: 'accounting')]
class AttachQuoteFileTool extends Tool
{
    use AttachesFileToDocumentForTool;
    use HasKanvasContext;
    use ResolvesScribeQuoteForTool;
    use TrackByInputs;

    private const string KIND_SOW = 'sow';
    private const string DEFAULT_ATTACHMENT_SLOT = 'attachment';

    protected string $name = 'attach_quote_file';

    protected ?string $description = 'Attaches a file to a quote in Kanvas Filesystem. Identify the file by filesystem_id '
        . 'when someone handed it to you this turn (an attachment marker, download_attachment), or by '
        . 'file_url when all you have is a link. kind "sow" files it as the quote\'s Statement of Work '
        . '(replacing the SOW PDF on file); kind "attachment" keeps it beside the quote\'s other documents.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'quote_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas quote id (returned as quote_id by create_quote or find_quote).',
                required: true,
            ),
            new ToolProperty(
                name: 'kind',
                type: PropertyType::STRING,
                description: 'One of: "sow" (the Statement of Work for this quote) or "attachment" (anything else). '
                    . 'Defaults to "attachment".',
                required: false,
            ),
            new ToolProperty(
                name: 'filesystem_id',
                type: PropertyType::INTEGER,
                description: 'The filesystem_id of a file already in Kanvas — from an `[Attached file...]` '
                    . 'marker on this turn or from download_attachment. Prefer this over file_url whenever you '
                    . 'have it; pass one of the two.',
                required: false,
            ),
            new ToolProperty(
                name: 'file_url',
                type: PropertyType::STRING,
                description: 'A URL the file can be downloaded from. Use only when you have no filesystem_id.',
                required: false,
            ),
            new ToolProperty(
                name: 'file_name',
                type: PropertyType::STRING,
                description: 'File name to store it under, including extension. Defaults to the file\'s own name.',
                required: false,
            ),
            new ToolProperty(
                name: 'field_name',
                type: PropertyType::STRING,
                description: 'Only for kind "attachment": a short slot name like "po" or "nda". Two attachments '
                    . 'with the same slot replace each other, so give each different document its own name.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $quote_id,
        ?string $kind = null,
        ?int $filesystem_id = null,
        ?string $file_url = null,
        ?string $file_name = null,
        ?string $field_name = null,
    ): array {
        $quote = $this->resolveQuote($quote_id);

        if (is_array($quote)) {
            return ['file_attached' => false, ...$quote];
        }

        $isSow = strtolower((string) Str::trimToNull($kind)) === self::KIND_SOW;
        $slot = $isSow
            ? QuoteSowPdfService::FIELD_NAME
            : (Str::trimToNull($field_name) ?? self::DEFAULT_ATTACHMENT_SLOT);

        $file = $this->attachFileToDocument(
            $quote,
            $filesystem_id,
            $file_url,
            $file_name,
            $slot,
        );

        if (! isset($file['url'])) {
            return ['file_attached' => false, ...$file];
        }

        return [
            'file_attached' => true,
            'quote_id' => $quote->getId(),
            'kind' => $isSow ? self::KIND_SOW : 'attachment',
            'field_name' => $file['field_name'],
            'file_name' => $file['name'],
            'message' => $isSow
                ? 'SOW file attached. To give it structure the agent can check and bill from, read it and call '
                    . 'generate_quote_sow with its contents.'
                : 'File attached to the quote.',
        ];
    }
}
