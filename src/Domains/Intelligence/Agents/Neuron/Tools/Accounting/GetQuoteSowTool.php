<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesScribeQuoteForTool;
use Kanvas\Scribe\Quotes\Models\QuoteLine;
use Kanvas\Scribe\Quotes\Sow\DataTransferObject\StatementOfWork;
use Kanvas\Scribe\Quotes\Sow\Services\QuoteSowPdfService;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/** Reads a quote's current Statement of Work as structured data. */
#[AgentTool(name: 'Get Quote SOW', category: 'accounting')]
class GetQuoteSowTool extends Tool
{
    use HasKanvasContext;
    use ResolvesScribeQuoteForTool;
    use TrackByInputs;

    protected string $name = 'get_quote_sow';

    protected ?string $description = 'Reads the current Statement of Work of a quote: scope, out of scope, assumptions, '
        . 'deliverables, timeline, payment schedule, signatories. Use it to answer "is X in scope?", "when is the '
        . 'next payment?" or "what did we promise?" from what was agreed, instead of guessing or re-reading a PDF. '
        . 'Pass term to get the scope, out-of-scope, deliverable and assumption entries that mention it. It also '
        . 'returns the quote lines with their quote_line_id, which generate_quote_sow deliverables refer to.';

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
                description: 'The Kanvas quote id (from find_quote).',
                required: true,
            ),
            new ToolProperty(
                name: 'term',
                type: PropertyType::STRING,
                description: 'Optional word or phrase to look up, e.g. "mobile app".',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $quote_id, ?string $term = null): array
    {
        $quote = $this->resolveQuote($quote_id);

        if (is_array($quote)) {
            return ['found' => false, ...$quote];
        }

        $lines = $quote->lines->map(fn (QuoteLine $line): array => [
            'quote_line_id' => (int) $line->id,
            'description' => $line->description,
            'total' => $line->line_total_native,
        ])->all();

        /** @var Message|null $message */
        $message = $quote->sowMessage;

        if ($message === null) {
            return [
                'found' => false,
                'reason' => 'no_sow',
                'quote_id' => $quote->getId(),
                'has_sow_file' => $quote->getFileByName(QuoteSowPdfService::FIELD_NAME) !== null,
                'quote_lines' => $lines,
                'message' => 'This quote has no structured SOW. Nothing is in or out of scope on record; say so '
                    . 'rather than guessing.',
            ];
        }

        $sow = StatementOfWork::fromMessage($message);
        $result = [
            'found' => true,
            'quote_id' => $quote->getId(),
            'quote_status' => $quote->status->value,
            'sow_message_id' => $message->getId(),
            'previous_sow_message_id' => $message->parent_id,
            'committed' => ! $quote->status->canBeRevised(),
            'quote_lines' => $lines,
            ...$sow->toPayload($quote->getId()),
        ];

        $needle = Str::trimToNull($term);

        if ($needle === null) {
            return $result;
        }

        $matches = fn (array $items): array => array_values(array_filter(
            $items,
            static fn (string $item): bool => mb_stripos($item, $needle) !== false,
        ));

        return [
            ...$result,
            'term' => $needle,
            'matches_in_scope' => $matches($sow->scope),
            'matches_out_of_scope' => $matches($sow->outOfScope),
            'matches_in_deliverables' => $matches(array_column($sow->deliverables, 'name')),
            'matches_in_assumptions' => $matches($sow->assumptions),
            'note' => 'An entry in out_of_scope means it is NOT included. No match anywhere means the SOW does not '
                . 'mention it: say it is not covered and that a change order would be needed.',
        ];
    }
}
