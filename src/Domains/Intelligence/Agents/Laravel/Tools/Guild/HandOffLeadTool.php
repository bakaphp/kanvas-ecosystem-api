<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Actions\HandOffAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Enums\HandOffTypeEnum;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

#[AgentTool(name: 'Hand Off Lead', category: 'crm')]
class HandOffLeadTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Hand off an existing lead. The agent instructions determine when a handoff is appropriate and which handoff type to use; call this tool only with a handoff type allowed by those instructions.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $leadId = $request->integer('lead_id');
        $handOffType = strtolower(trim((string) $request->string('handoff_type')));
        $type = HandOffTypeEnum::tryFrom($handOffType);

        if ($type === null) {
            return json_encode([
                'success' => false,
                'error' => 'Unsupported handoff type.',
                'handoff_type' => $handOffType,
            ], JSON_PRETTY_PRINT);
        }

        try {
            /** @var Lead $lead */
            $lead = Lead::getByIdFromCompanyApp($leadId, $this->company, $this->app);

            $params = ['handoff_type' => $type->value];
            $conversationSummary = trim((string) $request->string('conversation_summary'));

            if ($conversationSummary !== '') {
                $params['conversation_summary'] = $conversationSummary;
            }

            $result = new HandOffAction($lead, $this->app, $params)->execute();

            return json_encode([
                ...$result,
                'lead_id' => $leadId,
                'handoff_type' => $type->value,
            ], JSON_PRETTY_PRINT);
        } catch (Throwable $e) {
            return json_encode([
                'success' => false,
                'error' => "Unable to hand off lead {$leadId}: {$e->getMessage()}",
            ], JSON_PRETTY_PRINT);
        }
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'lead_id' => $schema
                ->integer()
                ->description('The ID of the lead to hand off.')
                ->required(),
            'handoff_type' => $schema
                ->string()
                ->description('The handoff type selected from the handoff options allowed by the agent instructions.')
                ->required(),
            'conversation_summary' => $schema
                ->string()
                ->description('Optional concise context to pass to the handoff recipient.'),
        ];
    }
}
