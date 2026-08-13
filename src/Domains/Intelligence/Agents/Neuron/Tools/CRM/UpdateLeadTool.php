<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
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
                . 'Two things you can update: (1) the CONTACT details (real first/last name, email, phone) when the prospect '
                . 'gives them and they were missing or wrong; (2) the QUALIFICATION answers (budget, what service they need, '
                . 'urgency, timeline, address) plus your disposition of the lead. '
                . 'Use this AFTER the prospect shares details or answers your qualifying questions. '
                . 'Only pass the fields the prospect actually gave you — omit the rest. '
                . 'Set disposition to "qualified", "unqualified", or "spam" once you can tell. Disposition is your '
                . 'qualification judgment, NOT the lead\'s status — it does not close a lead and does not change '
                . 'what the lead UI shows. To close, reopen, or otherwise change a lead\'s status, use set_lead_status. '
                . 'Set advance_stage=true to move the lead to the next pipeline stage when it is genuinely progressing.',
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
                name: 'advance_stage',
                type: PropertyType::BOOLEAN,
                description: 'Set true to move the lead to the next pipeline stage (only when it is genuinely progressing).',
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
        ?string $budget = null,
        ?string $service_needed = null,
        ?string $address = null,
        ?string $urgency = null,
        ?string $timeline = null,
        ?string $notes = null,
        ?string $disposition = null,
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

        $stageAdvanced = false;
        if ($advance_stage === true) {
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
                'message' => 'Nothing to update — no qualification fields, disposition, or stage change were provided.',
            ];
        }

        return [
            'status' => 'success',
            'lead_id' => $lead_id,
            'updated' => $updated,
            'stage_advanced' => $stageAdvanced,
            'current_pipeline_stage' => $lead->getCurrentPipelineStage()?->name,
        ];
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
