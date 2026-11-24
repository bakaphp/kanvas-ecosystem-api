<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Regional\DataTransferObject\TaxpayerLookup;
use Kanvas\Scribe\Regional\Services\TaxpayerLookupService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/** Looks a Dominican RNC / cédula up at DGII through the company's e-CF provider. */
#[AgentTool(name: 'Check RNC', category: 'accounting')]
class CheckRncTool extends Tool
{
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'check_rnc';

    protected ?string $description = 'Checks a Dominican tax id (RNC of 9 digits or cédula of 11) against DGII and returns '
        . 'the legal name registered for it. Use it before issuing an invoice with credit fiscal (E31) and when '
        . 'creating or correcting a customer or vendor, so the e-CF carries a tax id DGII will accept. Read-only. '
        . 'Only works for a company that issues electronic invoices (e-CF).';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'rnc',
                type: PropertyType::STRING,
                description: 'The RNC or cédula, with or without dashes, e.g. "131-79432-1" or "00112345678".',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $rnc): array
    {
        $result = new TaxpayerLookupService()->lookup($this->app, $this->company, $rnc);

        return match ($result->status) {
            TaxpayerLookup::INVALID_FORMAT => [
                'found' => false,
                'reason' => 'invalid_format',
                'message' => "'{$rnc}' is not a Dominican tax id: an RNC has 9 digits and a cédula 11. Ask the user for the right number; do not retry with the same value.",
            ],
            TaxpayerLookup::NO_PROVIDER => [
                'found' => false,
                'reason' => 'no_ecf_provider',
                'message' => 'This company has no e-CF provider configured, so DGII cannot be queried from here.',
            ],
            TaxpayerLookup::FAILED => [
                'found' => false,
                'reason' => 'lookup_failed',
                'message' => 'The DGII lookup failed, so the RNC could not be verified right now. Say so; do not treat it as invalid.',
            ],
            TaxpayerLookup::NOT_REGISTERED => [
                'found' => false,
                'rnc' => $result->taxId,
                'reason' => 'not_registered',
                'message' => "DGII has no taxpayer registered under {$result->taxId}. Do not issue an E31 to it; confirm the number with the user.",
            ],
            default => [
                'found' => true,
                'rnc' => $result->taxId,
                'legal_name' => $result->legalName,
                'type' => $result->type(),
            ],
        };
    }
}
