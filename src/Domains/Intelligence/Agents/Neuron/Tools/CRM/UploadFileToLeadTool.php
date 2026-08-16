<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasFileUploadToolProperties;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Agents\Traits\AttachesFileToEntity;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Stores a document on the lead's Files. This is the only agent-facing path that produces a real
 * file — add_lead_note and update_lead_description write text into the activity thread and the
 * Description box, which is where long documents used to be dumped for lack of anywhere better.
 */
#[AgentTool(name: 'Upload File To Lead', category: 'crm')]
class UploadFileToLeadTool extends Tool implements HasRunKey
{
    use AttachesFileToEntity;
    use HasFileUploadToolProperties;
    use HasKanvasContext;
    use ResolvesLeadForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'upload_file_to_lead',
            description: 'Save a document as a real file on a lead, so the team finds it under the lead\'s Files '
                . 'instead of buried in a note. Use this for technical documentation, a PRD, a spec, a quote '
                . 'write-up, meeting minutes, or any supporting document. Normally you pass `content` — the full '
                . 'text of the document you wrote — plus a `file_name` ending in .md, .txt, .csv or .json. Pass '
                . '`file_url` instead only when the file already exists at a public URL. Use search_leads or '
                . 'get_lead_ref to get the lead_id first. For a short remark that belongs in the conversation '
                . 'thread, use add_lead_note instead.',
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
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead to attach the file to (from search_leads or get_lead_ref).',
                required: true,
            ),
            ...$this->fileUploadProperties('integration-prd.md'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $lead_id,
        ?string $file_name = null,
        ?string $content = null,
        ?string $file_url = null,
    ): array {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        return [
            'lead_id' => (int) $lead->getId(),
            ...$this->attachFileToEntity(
                entity: $lead,
                entityLabel: 'lead',
                fileUrl: $file_url,
                content: $content,
                fileName: $file_name,
            ),
        ];
    }
}
