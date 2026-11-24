<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesScribeQuoteForTool;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteSowException;
use Kanvas\Scribe\Quotes\Sow\Actions\RecordQuoteSowAction;
use Kanvas\Scribe\Quotes\Sow\DataTransferObject\StatementOfWork;
use Kanvas\Scribe\Quotes\Sow\Services\QuoteSowPdfService;
use Kanvas\Social\Messages\Exceptions\MessageValidationException;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Writes the structured Statement of Work for a quote, checks it against the quote, and renders it as a PDF. */
#[AgentTool(name: 'Generate Quote SOW', category: 'accounting')]
class GenerateQuoteSowTool extends Tool
{
    use HasKanvasContext;
    use ResolvesScribeQuoteForTool;
    use TrackByInputs;

    protected string $name = 'generate_quote_sow';

    protected ?string $description = 'Writes the Statement of Work (SOW) for a draft or sent quote: summary, scope, out of '
        . 'scope, assumptions, deliverables, timeline, payment schedule and signatories. Draft it yourself from the '
        . 'quote lines, the conversation with the customer and past SOWs in your knowledge, then pass it here. The '
        . 'SOW is checked against the quote and refused with the exact reasons when the payment schedule does not '
        . 'add up to the quote total, a quote line is not covered by a deliverable or scope item, or a payment date '
        . 'falls outside the timeline: fix those and call again. On success it saves a new SOW revision and attaches '
        . 'the SOW PDF to the quote. Signing the SOW is what starts billing on its payment schedule.';

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        $text = static fn (string $name, string $description): ToolProperty => new ToolProperty(
            name: $name,
            type: PropertyType::STRING,
            description: $description,
            required: true,
        );

        $list = static fn (string $name, string $description, bool $required): ArrayProperty => new ArrayProperty(
            name: $name,
            description: $description,
            required: $required,
            items: new ToolProperty(
                name: 'item',
                type: PropertyType::STRING,
                description: 'One bullet.',
                required: true
            ),
        );

        return [
            new ToolProperty(
                name: 'quote_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas quote id (from create_quote or find_quote).',
                required: true,
            ),
            $text('summary', 'Two to four sentences on what the project is and the outcome the customer gets.'),
            $list('scope', 'What is included, one item per bullet.', true),
            $list('out_of_scope', 'What is explicitly NOT included. Pass an empty list when nothing needs saying.', false),
            $list('assumptions', 'What the plan relies on (access, customer inputs, third parties).', false),
            new ArrayProperty(
                name: 'deliverables',
                description: 'What is delivered. Every quote line must be covered: list its id in quote_line_ids '
                    . '(get_quote_sow returns the quote line ids) or name it in scope.',
                required: true,
                items: new ObjectProperty(
                    name: 'deliverable',
                    description: 'One deliverable.',
                    properties: [
                        new ToolProperty(
                            name: 'name',
                            type: PropertyType::STRING,
                            description: 'Deliverable name.',
                            required: true
                        ),
                        new ToolProperty(
                            name: 'acceptance_criteria',
                            type: PropertyType::STRING,
                            description: 'How the customer will judge it done.',
                            required: true,
                        ),
                        new ArrayProperty(
                            name: 'quote_line_ids',
                            description: 'Ids of the quote lines this deliverable covers.',
                            required: false,
                            items: new ToolProperty(
                                name: 'quote_line_id',
                                type: PropertyType::INTEGER,
                                description: 'A quote line id.',
                                required: true
                            ),
                        ),
                    ],
                ),
            ),
            $text('timeline_start', 'Project start date, YYYY-MM-DD.'),
            $text('timeline_end', 'Project end date, YYYY-MM-DD.'),
            new ArrayProperty(
                name: 'payment_schedule',
                description: 'How payment follows signing. Percent lines must add up to 100, or amounts to the quote '
                    . 'total. Each line gives percent OR amount, never both.',
                required: true,
                items: new ObjectProperty(
                    name: 'payment',
                    description: 'One payment.',
                    properties: [
                        new ToolProperty(
                            name: 'label',
                            type: PropertyType::STRING,
                            description: 'e.g. "Deposit", "Balance".',
                            required: true
                        ),
                        new ToolProperty(
                            name: 'when',
                            type: PropertyType::STRING,
                            description: 'One of: "on_signature" (invoiced right after signing) or "date" (invoiced on date).',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'date',
                            type: PropertyType::STRING,
                            description: 'YYYY-MM-DD, required when when is "date"; must fall inside the timeline.',
                            required: false,
                        ),
                        new ToolProperty(
                            name: 'percent',
                            type: PropertyType::NUMBER,
                            description: 'Share of the quote total, 0-100.',
                            required: false
                        ),
                        new ToolProperty(
                            name: 'amount',
                            type: PropertyType::NUMBER,
                            description: 'Fixed amount in the quote currency.',
                            required: false
                        ),
                    ],
                ),
            ),
            new ArrayProperty(
                name: 'signatories',
                description: 'Who signs, on both sides.',
                required: true,
                items: new ObjectProperty(
                    name: 'signatory',
                    description: 'One signatory.',
                    properties: [
                        new ToolProperty(
                            name: 'name',
                            type: PropertyType::STRING,
                            description: 'Full name.',
                            required: true
                        ),
                        new ToolProperty(
                            name: 'role',
                            type: PropertyType::STRING,
                            description: 'Title and company.',
                            required: true
                        ),
                    ],
                ),
            ),
            new ToolProperty(
                name: 'change_process',
                type: PropertyType::STRING,
                description: 'How scope changes are handled (e.g. "written change order, quoted separately").',
                required: false,
            ),
            new ToolProperty(
                name: 'payment_terms_id',
                type: PropertyType::INTEGER,
                description: 'Kanvas payment term id for the invoices, when the customer agreed specific terms.',
                required: false,
            ),
            new ToolProperty(
                name: 'render_pdf',
                type: PropertyType::BOOLEAN,
                description: 'Defaults to true. Pass false when the customer\'s own SOW file is already attached '
                    . '(attach_quote_file kind "sow") and you are only recording its structure.',
                required: false,
            ),
            new ToolProperty(
                name: 'template',
                type: PropertyType::STRING,
                description: 'Optional company SOW layout version (from get_sow_template). Omit to use the company '
                    . 'default, or the stock layout when there is none.',
                required: false,
            ),
        ];
    }

    /**
     * @param array<int, mixed> $scope
     * @param array<int, mixed>|null $out_of_scope
     * @param array<int, mixed>|null $assumptions
     * @param array<int, mixed> $deliverables
     * @param array<int, mixed> $payment_schedule
     * @param array<int, mixed> $signatories
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        int $quote_id,
        string $summary,
        array $scope,
        array $deliverables,
        string $timeline_start,
        string $timeline_end,
        array $payment_schedule,
        array $signatories,
        ?array $out_of_scope = null,
        ?array $assumptions = null,
        ?string $change_process = null,
        ?int $payment_terms_id = null,
        ?bool $render_pdf = null,
        ?string $template = null,
    ): array {
        $quote = $this->resolveQuote($quote_id);

        if (is_array($quote)) {
            return ['saved' => false, ...$quote];
        }

        $user = $this->contextUser();

        if ($user === null) {
            return [
                'saved' => false,
                'reason' => 'no_user_context',
                'message' => 'No acting user in scope, so the SOW cannot be recorded for this company.',
            ];
        }

        $sow = StatementOfWork::fromInput([
            'summary' => $summary,
            'scope' => $scope,
            'out_of_scope' => $out_of_scope ?? [],
            'assumptions' => $assumptions ?? [],
            'deliverables' => $deliverables,
            'timeline' => ['start' => $timeline_start, 'end' => $timeline_end],
            'payment_schedule' => $payment_schedule,
            'change_process' => $change_process,
            'payment_terms_id' => $payment_terms_id,
            'signatories' => $signatories,
            'template' => $template,
        ]);

        try {
            $message = new RecordQuoteSowAction($quote, $sow, $user)->execute();
        } catch (InvalidQuoteSowException $e) {
            return [
                'saved' => false,
                'reason' => 'sow_inconsistent',
                'problems' => $e->problems,
                'message' => 'The SOW was NOT saved. Fix every problem listed and call generate_quote_sow again; '
                    . 'ask the user when the fix is a business decision (a different price split, a dropped line).',
            ];
        } catch (MessageValidationException $e) {
            return [
                'saved' => false,
                'reason' => 'sow_incomplete',
                'message' => 'The SOW was NOT saved, a required part is missing or malformed: ' . $e->getMessage(),
            ];
        } catch (ValidationException $e) {
            return ['saved' => false, 'reason' => 'quote_not_editable', 'message' => $e->getMessage()];
        }

        $result = [
            'saved' => true,
            'quote_id' => $quote->getId(),
            'sow_message_id' => $message->getId(),
            'previous_sow_message_id' => $message->parent_id,
            'template' => StatementOfWork::fromMessage($message)->template,
        ];

        if ($render_pdf === false) {
            return [...$result, 'message' => 'SOW saved. No PDF rendered, as asked.'];
        }

        try {
            $file = new QuoteSowPdfService($quote->refresh(), $message)->generate($user);
        } catch (Throwable $e) {
            report($e);

            return [
                ...$result,
                'pdf_generated' => false,
                'message' => 'SOW saved, but the PDF could not be rendered: ' . $e->getMessage(),
            ];
        }

        return [
            ...$result,
            'pdf_generated' => true,
            'filesystem_id' => $file->getId(),
            'file_name' => $file->name,
            'message' => 'SOW saved and its PDF attached to the quote. Hand the person a link with get_file_link, '
                . 'never the raw id.',
        ];
    }
}
