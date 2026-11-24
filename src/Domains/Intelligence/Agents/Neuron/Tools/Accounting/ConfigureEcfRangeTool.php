<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ConfigureEcfRangeAction;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalNumberRange;
use Kanvas\Scribe\Setup\Actions\SyncAccountingSetupPlanAction;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Loading a range closes the onboarding "e-NCF ranges" task right away when it makes the requirement
 * ready, instead of waiting for the next setup run.
 */
#[AgentTool(name: 'Configure e-NCF Range', category: 'accounting')]
class ConfigureEcfRangeTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use TrackByInputs;

    protected string $name = 'configure_ecf_range';

    protected ?string $description = 'Loads an e-NCF range DGII authorized for one e-CF type, so invoices of that type can be '
        . 'numbered. The person reads the numbers from the DGII authorization (the OFV "solicitud de autorización de '
        . 'secuencias"): the type, the first and last e-NCF and the expiry date. One call per type (31 credito fiscal, '
        . '32 consumo, 33 debit note, 34 credit note, 41 compras, 43 gastos menores, 44, 45, 46, 47). A new range for a '
        . 'type replaces the active one and the old one is kept as history; also use it to load a renewal. Only a '
        . 'company admin can load one. Never invent or guess the numbers: ask the person to read them to you.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'document_type',
                type: PropertyType::STRING,
                description: 'e-CF type code, with or without the E: 31 or E31.',
                required: true,
            ),
            new ToolProperty(
                name: 'first_number',
                type: PropertyType::STRING,
                description: 'First e-NCF of the range: the full number (E310000000001) or just the sequence (1).',
                required: true,
            ),
            new ToolProperty(
                name: 'last_number',
                type: PropertyType::STRING,
                description: 'Last e-NCF of the range, in the same form.',
                required: true,
            ),
            new ToolProperty(
                name: 'expires_at',
                type: PropertyType::STRING,
                description: 'Expiry date of the range, YYYY-MM-DD. DGII leaves it out for types 32 and 34.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $document_type,
        string $first_number,
        string $last_number,
        ?string $expires_at = null,
    ): array {
        $denied = $this->requireAdminOrError();
        if ($denied !== null) {
            return $this->denied($denied['message'], guidance: 'Say the range was NOT loaded and that a company admin has to give it.');
        }

        $action = new ConfigureEcfRangeAction(
            app: $this->app,
            company: $this->company,
            type: $document_type,
            start: $first_number,
            end: $last_number,
            expiresAt: $expires_at,
        );

        try {
            $sequence = $action->execute();
        } catch (ValidationException $e) {
            return $this->invalidArgs($e->getMessage(), guidance: 'Nothing was loaded. Ask the person to check the numbers on the DGII authorization.');
        } catch (Throwable $e) {
            report($e);

            return $this->failed('The range could not be loaded right now.', guidance: 'Say it was NOT loaded; do not retry in this turn.');
        }

        /** @var FiscalNumberRange|null $range */
        $range = FiscalNumberRange::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->whereKey($sequence->fiscal_number_range_id)
            ->first();

        return $this->ok([
            'range' => $range?->describe(),
            'notice' => $action->notice,
            'setup_tasks_closed' => $this->syncSetupPlan(),
        ]);
    }

    private function syncSetupPlan(): int
    {
        try {
            $row = CompanyOnboarding::forCompany($this->app, $this->company);

            return $row !== null ? new SyncAccountingSetupPlanAction($row)->execute() : 0;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}
