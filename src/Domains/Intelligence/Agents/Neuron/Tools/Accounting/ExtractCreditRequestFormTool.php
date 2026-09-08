<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesFilesystemForTool;
use Kanvas\Scribe\Invoices\Services\CreditRequestFormParserFactory;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Reads a client's credit-request document already saved in Kanvas and parses it into the fields create_ar_credit_memo needs — CreditRequestFormParserFactory picks the right client-specific parser per app, this tool never hardcodes one. */
#[AgentTool(name: 'Extract Credit Request Form', category: 'accounting')]
class ExtractCreditRequestFormTool extends Tool
{
    use HasKanvasContext;
    use ResolvesFilesystemForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'extract_credit_request_form',
            description: 'Reads a Credit Request Form (CNR) Excel file already stored in Kanvas (e.g. the '
                . 'filesystem_id returned by download_attachment) and parses it into customer_name, '
                . 'request_reference_no, region, tenant, and one or more lines (control_account_number, '
                . 'description, amount) — ready to pass straight into create_ar_credit_memo. One email can carry '
                . 'more than one form (one per credit memo) — call this once per attached form.',
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
                name: 'filesystem_id',
                type: PropertyType::INTEGER,
                description: 'The filesystem_id returned by download_attachment (or any other Kanvas file '
                    . 'upload). Always required.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $filesystem_id): array
    {
        $file = $this->findTenantFile($filesystem_id);

        if ($file === null) {
            return [
                'success' => false,
                'reason' => 'file_not_found',
                'message' => "No file with filesystem_id {$filesystem_id} for this app.",
            ];
        }

        try {
            $parsed = $this->withDownloadedFile(
                $file,
                'cnr_',
                'xlsx',
                fn (string $path): array => CreditRequestFormParserFactory::forApp($this->app)->parse($path)
            );
        } catch (Throwable $e) {
            return [
                'success' => false,
                'reason' => 'parse_failed',
                'message' => 'Could not read the Credit Request Form: ' . $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            ...$parsed,
            'file_url' => $file->url,
            'file_name' => $file->name,
        ];
    }
}
