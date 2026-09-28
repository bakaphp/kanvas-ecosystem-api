<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasFileUploadToolSchema;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\AttachesFileToEntity;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

/**
 * Laravel-AI counterpart of the Neuron upload_file_to_lead tool — same body via
 * AttachesFileToEntity, so both frameworks store and attach files identically.
 */
#[AgentTool(name: 'Upload File To Lead', category: 'crm')]
class UploadFileToLeadTool implements KanvasToolInterface
{
    use AttachesFileToEntity;
    use HasFileUploadToolSchema;
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Save a document as a real file on a lead, so the team finds it under the lead\'s Files instead of '
            . 'buried in a note. Use this for technical documentation, a PRD, a spec, a quote write-up, meeting '
            . 'minutes, or any supporting document. Normally you pass content — the full text of the document you '
            . 'wrote — plus a file_name ending in .md, .txt, .csv or .json. Pass file_url instead only when the '
            . 'file already exists at a public URL.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $leadId = $request->integer('lead_id');

        try {
            /** @var Lead $lead */
            $lead = Lead::getByIdFromCompanyApp($leadId, $this->company, $this->app);
        } catch (Throwable) {
            return "Lead {$leadId} does not exist in this company. Use a lead search to get a real lead_id.";
        }

        return $this->uploadFromRequest($request, $lead, 'lead', ['lead_id' => $leadId]);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'lead_id' => $schema
                ->integer()
                ->description('The ID of the lead to attach the file to.')
                ->required(),
            ...$this->fileUploadSchema($schema, 'integration-prd.md'),
        ];
    }
}
