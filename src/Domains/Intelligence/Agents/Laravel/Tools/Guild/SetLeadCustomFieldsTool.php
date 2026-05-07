<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

class SetLeadCustomFieldsTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Set one or more custom fields on an existing lead. Use this after create_lead to store domain-specific data such as event type, severity, scores, or any structured classification result.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $leadId = $request->integer('lead_id');

        $rawFields = $request->all()['fields'] ?? null;
        $fields = is_array($rawFields)
            ? $rawFields
            : (is_string($rawFields) ? (json_decode($rawFields, true) ?? []) : []);

        if (empty($fields)) {
            return 'No fields provided. Pass a "fields" JSON string with key/value pairs.';
        }

        try {
            /** @var Lead $lead */
            $lead = Lead::getByIdFromCompanyApp($leadId, $this->company, $this->app);
        } catch (Throwable $e) {
            return "Lead {$leadId} not found: {$e->getMessage()}";
        }

        foreach ($fields as $key => $value) {
            $lead->set((string) $key, $value);
        }

        return json_encode([
            'success' => true,
            'lead_id' => $leadId,
            'fields_set' => count($fields),
        ], JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'lead_id' => $schema
                ->integer()
                ->description('The ID returned by create_lead.')
                ->required(),
            'fields' => $schema
                ->string()
                ->description('JSON-encoded object with key/value pairs to store as custom fields on the lead. Example: {"event_type":"Chapter11Bankruptcy","severity":"4"}')
                ->required(),
        ];
    }
}
