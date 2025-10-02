<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Override;

class LeadIntentTool implements ContextToolInterface
{
    protected Agent $agent;

    public function __construct(
        protected Model $entity
    ) {
    }

    #[Override]
    public function execute(array $params = []): array
    {
        $sources = $this->entity->company->get('adf_sources') ?? [];
        if (empty($sources)) {
            throw new Exception('No ADF sources found for this company');
        }
        $sources = collect($sources);
        $leadType = $this->entity->type->name;
        $subSource = $this->entity->get('sub_source');
        $source = $sources->where('Source', $leadType)
            ->where('Sub_Source', $subSource)
            ->firstOrFail();

        return [
            'lead_intent' => $source['Backend'],
            'intent_completion_status' => $source['Default_Completion_Status'],
        ];
    }
}
