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

class AddLeadTagsTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Add one or more tags to an existing lead. Tags are created automatically if they do not exist. Use this to classify leads with labels such as event type, industry, source, or any categorical marker.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $leadId = $request->integer('lead_id');
        $tags = $request->all()['tags'] ?? [];

        if (! is_array($tags) || empty($tags)) {
            return 'No tags provided. Pass a "tags" array with one or more tag name strings.';
        }

        try {
            /** @var Lead $lead */
            $lead = Lead::getByIdFromCompanyApp($leadId, $this->company, $this->app);
        } catch (Throwable $e) {
            return "Lead {$leadId} not found: {$e->getMessage()}";
        }

        $user = auth()->user();
        $lead->addTags(array_values($tags), $this->app, $user, $this->company);

        return json_encode([
            'success' => true,
            'lead_id' => $leadId,
            'tags_added' => count($tags),
            'tags' => array_values($tags),
        ], JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'lead_id' => $schema
                ->integer()
                ->description('The ID of the lead to tag.')
                ->required(),
            'tags' => $schema
                ->array()
                ->items($schema->string())
                ->description('List of tag names to add to the lead. Tags are created if they do not exist.')
                ->required(),
        ];
    }
}
