<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Actions\ImportPeopleRowsAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use Override;
use Throwable;

/**
 * Ingests a list of people (parsed by the agent from a CSV, a pasted list of names, etc.) into the
 * CRM as People — no Lead is created. Dedup is automatic: a row matching an existing email/phone
 * updates that record instead of duplicating it. Use create_lead_campaign afterwards to message the
 * resulting people_ids. Admin-only — a bulk write over the whole tenant.
 */
#[AgentTool(name: 'Import People List', category: 'crm')]
class ImportPeopleListTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;

    protected string $name = 'import_people_list';

    protected ?string $description = 'Bulk-import a list of people into the CRM as standalone contacts (no lead created). '
        . 'Pass rows you have already parsed from the source (a CSV, a pasted list of names, etc.) — each row needs '
        . 'at least a firstname. A row matching an existing email/phone updates that person instead of duplicating '
        . 'them. Returns the resulting people_ids for use with create_lead_campaign. Max 500 rows per call. Admin-only.';

    private const int MAX_ROWS = 500;

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ArrayProperty(
                name: 'rows',
                description: 'One entry per person. At least one row is required, max ' . self::MAX_ROWS . '.',
                required: true,
                items: new ObjectProperty(
                    name: 'row',
                    description: 'A single person.',
                    properties: [
                        new ToolProperty(name: 'firstname', type: PropertyType::STRING, description: 'First name, or the full name if you have not split it (required).', required: true),
                        new ToolProperty(name: 'lastname', type: PropertyType::STRING, description: 'Last name.', required: false),
                        new ToolProperty(name: 'email', type: PropertyType::STRING, description: 'Email address.', required: false),
                        new ToolProperty(name: 'phone', type: PropertyType::STRING, description: 'Phone number.', required: false),
                        new ToolProperty(name: 'organization', type: PropertyType::STRING, description: 'Employer / organization name.', required: false),
                    ],
                ),
            ),
        ];
    }

    /**
     * @param  array<int, array{firstname?: string, lastname?: string, email?: string, phone?: string, organization?: string}>  $rows
     *
     * @return array<string, mixed>
     */
    public function __invoke(array $rows): array
    {
        if ($denied = $this->requireAdminOrError()) {
            return ['status' => 'error', 'message' => $denied['message']];
        }

        if ($rows === []) {
            return ['status' => 'error', 'message' => 'Provide at least one row to import.'];
        }

        if (count($rows) > self::MAX_ROWS) {
            return ['status' => 'error', 'message' => 'Too many rows in one call (max ' . self::MAX_ROWS . '). Split the list.'];
        }

        $normalizedRows = [];
        foreach ($rows as $row) {
            $firstname = trim((string) ($row['firstname'] ?? ''));
            if ($firstname === '') {
                continue;
            }

            $contacts = [];
            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '') {
                $contacts[] = ['value' => $email];
            }
            $phone = trim((string) ($row['phone'] ?? ''));
            if ($phone !== '') {
                $contacts[] = ['value' => $phone];
            }

            $lastname = trim((string) ($row['lastname'] ?? ''));
            $organization = trim((string) ($row['organization'] ?? ''));

            $normalizedRows[] = [
                'firstname' => $firstname,
                'lastname' => $lastname !== '' ? $lastname : null,
                'contacts' => $contacts,
                'organization' => $organization !== '' ? $organization : null,
            ];
        }

        if ($normalizedRows === []) {
            return ['status' => 'error', 'message' => 'None of the rows had a firstname — nothing to import.'];
        }

        try {
            $result = new ImportPeopleRowsAction($this->app, $this->resolveBranch(), $this->user)->execute($normalizedRows);
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'message' => 'Import failed: ' . $e->getMessage()];
        }

        return [
            'status' => 'success',
            'created' => $result['created'],
            'matched' => $result['updated'],
            'people_ids' => $result['people_ids'],
            'skipped_rows' => count($rows) - count($normalizedRows),
            'errors' => $result['errors'],
            'note' => 'People imported. Use create_lead_campaign with these people_ids to message them.',
        ];
    }

    private function resolveBranch(): CompaniesBranches
    {
        /** @var CompaniesBranches $branch */
        $branch = $this->company->defaultBranch()->first() ?? $this->company->branches()->firstOrFail();

        return $branch;
    }
}
