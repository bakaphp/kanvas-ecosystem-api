<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Setup\Actions\SetUpAccountingAgentAction;
use Kanvas\Scribe\Setup\DataTransferObject\ReadinessItem;
use Kanvas\Scribe\Setup\Services\AccountingReadinessService;
use Kanvas\Scribe\Setup\Services\AccountingSetupStateService;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Changing the approver is a re-run with the new one: SeedScribeApprovalPoliciesAction moves a fallback the
 * setup wrote to the newly named approver, so a separate "set approver" tool would only duplicate this.
 */
#[AgentTool(name: 'Run Accounting Setup', category: 'accounting')]
class RunAccountingSetupTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ReportsToolOutcome;

    protected string $name = 'run_accounting_setup';

    protected ?string $description = 'Creates whatever the accounts payable / receivable agents still need: chart of accounts, '
        . 'fiscal periods, approval policies with the default approver, the accounting inbox, the agents\' mailboxes, invoice '
        . 'and quote pages and the reminder cadence. Safe to run again: it only adds what is missing, so run it again after '
        . 'something was connected, or to change who approves bills and invoices when no vendor or customer approver is named '
        . '(pass that person as the approver). Only a company admin can run it. It does not connect the e-CF provider, the '
        . 'bank or the accounting software, and never takes passwords, API keys or certificates; those are connected by the '
        . 'person in the integration connect step. Answers with each step and the readiness checklist afterwards.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'approver_email',
                type: PropertyType::STRING,
                description: 'Email of the company user who approves what no vendor or customer approver covers. Leave '
                    . 'out to keep the current approver (the company owner when none was named).',
                required: false,
            ),
            new ToolProperty(
                name: 'approver_user_id',
                type: PropertyType::INTEGER,
                description: 'The same approver by user id, when you have the id instead of the email.',
                required: false,
            ),
            new ToolProperty(
                name: 'country_code',
                type: PropertyType::STRING,
                description: 'Two-letter country of the company\'s books (DO for the Dominican Republic). Leave out to use '
                    . 'the company\'s own country.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $approver_email = null,
        ?int $approver_user_id = null,
        ?string $country_code = null,
    ): array {
        $denied = $this->requireAdminOrError();
        if ($denied !== null) {
            return $this->denied($denied['message'], guidance: 'Say the accounting setup was NOT run and that a company admin has to ask for it.');
        }

        $country = Str::trimToNull($country_code);
        if ($country !== null && preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
            return $this->invalidArgs("'{$country}' is not a two-letter country code.", guidance: 'Ask which country the books are kept in.');
        }

        $approver = $this->approver($approver_email, $approver_user_id);
        if (is_array($approver)) {
            return $approver;
        }

        try {
            $state = new SetUpAccountingAgentAction(
                app: $this->app,
                company: $this->company,
                approver: $approver,
                countryCode: $country !== null ? strtoupper($country) : null,
            )->execute();
            $readiness = new AccountingReadinessService()->check($this->app, $this->company);
        } catch (Throwable $e) {
            report($e);

            return $this->failed('The accounting setup could not run: ' . $e->getMessage(), guidance: 'Say it did not run; do not retry in this turn.');
        }

        $steps = [];
        foreach ($state['steps'] as $step => $detail) {
            $steps[] = ['step' => $step, 'status' => $detail['status'], 'error' => $detail['error'] ?? null];
        }

        return $this->ok([
            'country' => $state['country'],
            'approver_users_id' => $state['approver_users_id'],
            'steps' => $steps,
            'readiness' => array_values(array_map(fn (ReadinessItem $item): array => $item->toArray(), $readiness)),
        ], 'Tell the person what is set up now and walk them through what the readiness list still shows as not ready.');
    }

    /**
     * @return Users|array<string, mixed>|null
     */
    private function approver(?string $email, ?int $userId): Users|array|null
    {
        $email = Str::trimToNull($email);
        if ($email === null && $userId === null) {
            return null;
        }

        if ($userId === null) {
            $userId = (int) Users::query()->where('email', $email)->where('is_deleted', 0)->value('id');
        }

        try {
            return new AccountingSetupStateService()->memberApprover($this->company, (int) $userId);
        } catch (ValidationException $e) {
            return $this->invalidArgs(
                $e->getMessage(),
                guidance: 'Nothing was changed. Ask for the email of someone who is a user of this company.',
            );
        }
    }
}
