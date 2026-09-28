<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GeneratesScribeDocumentPdfForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesScribeQuoteForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/** Renders a quote as a printable PDF and attaches it to the quote. */
#[AgentTool(name: 'Generate Quote PDF', category: 'accounting')]
class GenerateQuotePdfTool extends Tool implements HasRunKey
{
    use GeneratesScribeDocumentPdfForTool;
    use HasKanvasContext;
    use ResolvesScribeQuoteForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'generate_quote_pdf',
            description: 'Renders a quote as a PDF — customer block, lines, totals, notes and terms — and '
                . 'attaches it to the quote in Kanvas. Use it whenever someone asks for the quote as a '
                . 'document to send or review. Works on a draft too, though a draft carries no quote number '
                . 'yet; send_quote first when the customer is getting the final copy.',
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
                name: 'quote_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas quote id (returned as quote_id by create_quote or find_quote).',
                required: true,
            ),
            new ToolProperty(
                name: 'template_name',
                type: PropertyType::STRING,
                description: 'Name of a stored template to render instead of the standard layout. Only pass '
                    . 'one the user actually asked for — the default layout is the right answer otherwise.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $quote_id, ?string $template_name = null): array
    {
        $quote = $this->resolveQuote($quote_id);

        if (is_array($quote)) {
            return ['generated' => false, ...$quote];
        }

        return [
            ...$this->generateDocumentPdf($quote, $template_name),
            'quote_number' => $quote->quote_number,
        ];
    }
}
