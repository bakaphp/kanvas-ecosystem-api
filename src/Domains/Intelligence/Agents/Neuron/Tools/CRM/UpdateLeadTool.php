<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesOrganizationForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Update Lead', category: 'crm')]
class UpdateLeadTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesLeadForTool;
    use ResolvesOrganizationForTool;
    use TrackByInputs;

    /**
     * Qualification answers persisted as lead custom fields (only when the LLM supplies them).
     */
    private const QUALIFICATION_FIELDS = [
        'budget',
        'service_needed',
        'address',
        'urgency',
        'timeline',
    ];

    private const DISPOSITIONS = ['qualified', 'unqualified', 'spam'];

    public function __construct()
    {
        parent::__construct(
            name: 'update_lead',
            description: 'Save what you learned about the prospect onto their CRM record so the business owner sees it. '
                . 'Three things you can update: (1) the CONTACT details (real first/last name, email, phone) when the prospect '
                . 'gives them and they were missing or wrong; (2) the QUALIFICATION answers (budget, what service they need, '
                . 'urgency, timeline, address) plus your disposition of the lead; (3) the RECORD fields — the lead title, '
                . 'the organization (company/account) it belongs to, its lead type and its source. '
                . 'Use this AFTER the prospect shares details or answers your qualifying questions. '
                . 'Only pass the fields the prospect actually gave you — omit the rest. '
                . 'Set disposition to "qualified", "unqualified", or "spam" once you can tell. Disposition is your '
                . 'qualification judgment, NOT the lead\'s status — it does not close a lead and does not change '
                . 'what the lead UI shows. To close, reopen, or otherwise change a lead\'s status, use set_lead_status. '
                . 'For any other custom field, use set_lead_custom_fields. '
                . 'To move the lead through its pipeline: pass pipeline_stage with the stage name when you know where '
                . 'it should land, or advance_stage=true to step it to the next one. Never both.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead in scope for this conversation.',
                required: true,
            ),
            new ToolProperty(
                name: 'firstname',
                type: PropertyType::STRING,
                description: 'The prospect\'s real first name, if they gave one and it was missing/wrong on the record.',
                required: false,
            ),
            new ToolProperty(
                name: 'lastname',
                type: PropertyType::STRING,
                description: 'The prospect\'s real last name, if they gave one.',
                required: false,
            ),
            new ToolProperty(
                name: 'email',
                type: PropertyType::STRING,
                description: 'An email address the prospect gave. Added to their contact record (existing emails are kept).',
                required: false,
            ),
            new ToolProperty(
                name: 'phone',
                type: PropertyType::STRING,
                description: 'A phone number the prospect gave. Added as a cellphone contact (existing numbers are kept).',
                required: false,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'A new title for the lead. Only pass it when the current title is wrong or meaningless — '
                    . 'this is what the owner sees in the lead list.',
                required: false,
            ),
            new ToolProperty(
                name: 'lead_type',
                type: PropertyType::STRING,
                description: 'The lead type, by name (e.g. "Sales", "Support"). Types are configured per company — '
                    . 'if the name does not match you get the available ones back, retry with one of those.',
                required: false,
            ),
            new ToolProperty(
                name: 'source',
                type: PropertyType::STRING,
                description: 'Where the lead came from, by name (e.g. "Website", "Referral"). Sources are configured '
                    . 'per company — if the name does not match you get the available ones back, retry with one of those.',
                required: false,
            ),
            new ToolProperty(
                name: 'organization_id',
                type: PropertyType::INTEGER,
                description: 'The id of the organization (company/account) this lead belongs to. Preferred when known — '
                    . 'get it from find_crm_records, list_people or create_organization.',
                required: false,
            ),
            new ToolProperty(
                name: 'organization_name',
                type: PropertyType::STRING,
                description: 'The organization name, when you do not have the id. If more than one org matches you get '
                    . 'the candidates back — ask which one, then call again with organization_id.',
                required: false,
            ),
            new ToolProperty(
                name: 'budget',
                type: PropertyType::STRING,
                description: 'The prospect\'s stated budget / price range, verbatim (e.g. "around $500", "under 10k").',
                required: false,
            ),
            new ToolProperty(
                name: 'service_needed',
                type: PropertyType::STRING,
                description: 'What the prospect is asking for (e.g. "AC repair", "kitchen remodel consultation").',
                required: false,
            ),
            new ToolProperty(
                name: 'address',
                type: PropertyType::STRING,
                description: 'Service address / location or ZIP the prospect gave, if any.',
                required: false,
            ),
            new ToolProperty(
                name: 'urgency',
                type: PropertyType::STRING,
                description: 'How urgent it is (e.g. "emergency today", "this week", "just researching").',
                required: false,
            ),
            new ToolProperty(
                name: 'timeline',
                type: PropertyType::STRING,
                description: 'When the prospect wants the work / appointment (e.g. "next month", "ASAP").',
                required: false,
            ),
            new ToolProperty(
                name: 'notes',
                type: PropertyType::STRING,
                description: 'Any other qualification context worth saving on the lead for the owner. '
                    . 'Recorded as a note on the lead\'s activity thread.',
                required: false,
            ),
            new ToolProperty(
                name: 'disposition',
                type: PropertyType::STRING,
                description: 'Your judgment of the lead: "qualified", "unqualified", or "spam". Omit if you cannot tell yet.',
                required: false,
            ),
            new ToolProperty(
                name: 'pipeline_stage',
                type: PropertyType::STRING,
                description: 'Move the lead to this pipeline stage, by name (e.g. "Negotiation"). Works in both '
                    . 'directions. If the name does not match you get the pipeline\'s stages back, retry with one of '
                    . 'those. Do not combine with advance_stage.',
                required: false,
            ),
            new ToolProperty(
                name: 'advance_stage',
                type: PropertyType::BOOLEAN,
                description: 'Set true to move the lead to the next pipeline stage (only when it is genuinely '
                    . 'progressing and you do not know the stage name). Do not combine with pipeline_stage.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        int $lead_id,
        ?string $firstname = null,
        ?string $lastname = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $title = null,
        ?string $lead_type = null,
        ?string $source = null,
        ?int $organization_id = null,
        ?string $organization_name = null,
        ?string $budget = null,
        ?string $service_needed = null,
        ?string $address = null,
        ?string $urgency = null,
        ?string $timeline = null,
        ?string $notes = null,
        ?string $disposition = null,
        ?string $pipeline_stage = null,
        ?bool $advance_stage = null,
    ): array {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $updated = [];

        $this->updatePeopleContact(
            $lead,
            $firstname,
            $lastname,
            $email,
            $phone,
            $updated
        );

        $recordError = $this->applyRecordFields(
            $lead,
            $title,
            $lead_type,
            $source,
            $organization_id,
            $organization_name,
            $updated
        );
        if ($recordError !== null) {
            return $recordError;
        }

        $incoming = [
            'budget' => $budget,
            'service_needed' => $service_needed,
            'address' => $address,
            'urgency' => $urgency,
            'timeline' => $timeline,
        ];

        foreach (self::QUALIFICATION_FIELDS as $field) {
            $value = $incoming[$field];
            if ($value !== null && trim($value) !== '') {
                $lead->set($field, trim($value));
                $updated[] = $field;
            }
        }

        if ($notes !== null && trim($notes) !== '') {
            $this->recordNote($lead, trim($notes), 'qualification-note');
            $updated[] = 'notes';
        }

        if ($disposition !== null && trim($disposition) !== '') {
            $normalized = strtolower(trim($disposition));
            if (! in_array($normalized, self::DISPOSITIONS, true)) {
                return [
                    'status' => 'error',
                    'message' => 'Invalid disposition "' . $disposition . '". Use one of: '
                        . implode(', ', self::DISPOSITIONS) . '.',
                ];
            }
            $lead->set('lead_disposition', $normalized);
            // The custom field alone is invisible in the CRM UI, so mirror it onto the activity
            // thread — otherwise the tool reports success on a change nobody can see.
            $this->recordNote($lead, 'Lead qualified as "' . $normalized . '" by the agent.', 'disposition');
            $updated[] = 'disposition';
        }

        $pipeline_stage = $this->trimmedOrNull($pipeline_stage);
        if ($pipeline_stage !== null && $advance_stage === true) {
            return [
                'status' => 'error',
                'message' => 'Pass either pipeline_stage (the stage you want) or advance_stage (step to the next one), not both.',
            ];
        }

        $stageAdvanced = false;
        if ($pipeline_stage !== null) {
            $stage = $this->resolvePipelineStage($lead, $pipeline_stage);
            if (is_array($stage)) {
                return $stage;
            }

            $stageAdvanced = (int) $lead->pipeline_stage_id !== $stage->getId();
            $lead->pipeline_stage_id = $stage->getId();
            $lead->saveOrFail();
            $updated[] = 'pipeline_stage';
        } elseif ($advance_stage === true) {
            $before = $lead->pipeline_stage_id;
            $lead->moveToNextPipelineStage();
            $stageAdvanced = $lead->pipeline_stage_id !== $before;
            if ($stageAdvanced) {
                $updated[] = 'pipeline_stage';
            }
        }

        if ($updated === []) {
            return [
                'status' => 'noop',
                'lead_id' => $lead_id,
                'message' => 'Nothing to update — no record fields, qualification fields, disposition, or stage change were provided.',
            ];
        }

        return [
            'status' => 'success',
            'lead_id' => $lead_id,
            'updated' => $updated,
            'stage_advanced' => $stageAdvanced,
            'current_pipeline_stage' => $lead->getCurrentPipelineStage()?->name,
            'title' => $lead->title,
            'organization' => $lead->organization?->name,
            'lead_type' => $lead->type?->name,
            'source' => $lead->source?->name,
        ];
    }

    /**
     * Write the columns on the lead row itself. Organization / type / source are resolved inside the lead's
     * own tenant, so an LLM-supplied id or name can never reach another company's record. A failed resolve
     * returns before the save, so a partially-resolved call leaves the lead untouched rather than half-written.
     *
     * @param list<string> $updated appended in place with the fields that changed
     *
     * @return array<string, mixed>|null the LLM-facing error/disambiguation to return verbatim, null otherwise
     */
    private function applyRecordFields(
        Lead $lead,
        ?string $title,
        ?string $leadType,
        ?string $source,
        ?int $organizationId,
        ?string $organizationName,
        array &$updated,
    ): ?array {
        $title = $this->trimmedOrNull($title);
        if ($title !== null) {
            $lead->title = $title;
            $updated[] = 'title';
        }

        $organizationName = $this->trimmedOrNull($organizationName);
        if ($organizationId !== null || $organizationName !== null) {
            if (! $this->hasTenantContext()) {
                return [
                    'status' => 'error',
                    'message' => 'Cannot link an organization without tenant context.',
                ];
            }

            $organization = $this->resolveOrganization($organizationId, $organizationName);
            if (! $organization instanceof Organization) {
                return $organization;
            }

            $lead->organization_id = $organization->getId();
            $updated[] = 'organization';
        }

        $leadType = $this->trimmedOrNull($leadType);
        if ($leadType !== null) {
            $type = $this->resolveCatalogEntry(LeadType::class, 'lead_type', $leadType);
            if (is_array($type)) {
                return $type;
            }

            $lead->leads_types_id = $type->getId();
            $updated[] = 'lead_type';
        }

        $source = $this->trimmedOrNull($source);
        if ($source !== null) {
            $leadSource = $this->resolveCatalogEntry(LeadSource::class, 'source', $source);
            if (is_array($leadSource)) {
                return $leadSource;
            }

            $lead->leads_sources_id = $leadSource->getId();
            $updated[] = 'source';
        }

        if ($lead->isDirty()) {
            $lead->saveOrFail();
            $lead->unsetRelation('organization');
            $lead->unsetRelation('type');
            $lead->unsetRelation('source');
        }

        return null;
    }

    /**
     * Resolve a lead type / source by name. Both are per-company catalogs the LLM has no other tool to
     * enumerate, so a miss returns the available names — the agent retries with a real one instead of
     * inventing the field or reporting a change it could not make.
     *
     * @param class-string<LeadSource|LeadType> $catalog
     *
     * @return LeadSource|LeadType|array<string, mixed>
     */
    private function resolveCatalogEntry(string $catalog, string $field, string $name): LeadSource|LeadType|array
    {
        $matches = $catalog::query()
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('name', 'like', '%' . $name . '%')
            ->limit(10)
            ->get();

        $exact = $matches->first(fn (LeadSource|LeadType $entry): bool => strcasecmp($entry->name, $name) === 0);
        if ($exact !== null) {
            return $exact;
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        return [
            'status' => 'error',
            'message' => $matches->isEmpty()
                ? "No {$field} named \"{$name}\" exists in this company. Retry with one of the available values, or omit {$field}."
                : "\"{$name}\" matches more than one {$field}. Retry with the exact name.",
            'available' => $catalog::query()
                ->fromCompany($this->company)
                ->notDeleted()
                ->limit(25)
                ->pluck('name')
                ->all(),
        ];
    }

    /**
     * Resolve a stage by name inside the lead's OWN pipeline. Scoping to `$lead->pipeline_id` is what keeps
     * this tenant-safe without a company clause — the pipeline id came off a lead already resolved for this
     * tenant, and a stage borrowed from another pipeline would put the lead in a state its own board cannot
     * render. A miss returns the pipeline's stages so the agent retries with a real one.
     *
     * @return PipelineStage|array<string, mixed>
     */
    private function resolvePipelineStage(Lead $lead, string $name): PipelineStage|array
    {
        $pipelineId = (int) $lead->pipeline_id;
        if ($pipelineId === 0) {
            return [
                'status' => 'error',
                'message' => 'This lead is not on a pipeline, so it has no stages to move between.',
            ];
        }

        $stages = PipelineStage::query()
            ->where('pipelines_id', $pipelineId)
            ->where('is_deleted', 0)
            ->orderBy('weight')
            ->get();

        $match = $stages->first(fn (PipelineStage $stage): bool => strcasecmp($stage->name, $name) === 0)
            ?? $stages->first(fn (PipelineStage $stage): bool => stripos($stage->name, $name) !== false);

        if ($match === null) {
            return [
                'status' => 'error',
                'message' => "No stage named \"{$name}\" on this lead's pipeline. Retry with one of the available stages.",
                'available' => $stages->pluck('name')->all(),
            ];
        }

        return $match;
    }

    private function trimmedOrNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== null && $value !== '' ? $value : null;
    }

    private function recordNote(Lead $lead, string $body, string $tag): void
    {
        new RecordLeadNoteAction($lead)->execute($body, $tag, $this->contextUser());
    }

    /**
     * Enrich the lead's People contact with anything new the prospect shared. Names overwrite
     * (a real name beats a placeholder); email/phone are idempotent upserts via the model helpers
     * (existing contacts are kept, duplicates are not created). Only provided values are touched.
     *
     * @param list<string> $updated appended in place with the fields that changed
     */
    private function updatePeopleContact(
        Lead $lead,
        ?string $firstname,
        ?string $lastname,
        ?string $email,
        ?string $phone,
        array &$updated,
    ): void {
        $people = $lead->people;
        if ($people === null) {
            return;
        }

        $nameChanged = false;
        if ($firstname !== null && trim($firstname) !== '') {
            $people->firstname = trim($firstname);
            $nameChanged = true;
        }
        if ($lastname !== null && trim($lastname) !== '') {
            $people->lastname = trim($lastname);
            $nameChanged = true;
        }
        if ($nameChanged) {
            $people->name = trim($people->firstname . ' ' . $people->lastname);
            $people->saveOrFail();
            $updated[] = 'contact_name';
        }

        if ($email !== null && trim($email) !== '') {
            $people->addEmail(trim($email));
            $updated[] = 'contact_email';
        }

        if ($phone !== null && trim($phone) !== '') {
            $people->addCellPhone(trim($phone));
            $updated[] = 'contact_phone';
        }
    }
}
