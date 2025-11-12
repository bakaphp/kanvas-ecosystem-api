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
        $leadSource = $this->entity->source->name;
        $subSource = $this->entity->get('sub_source');

        /**
         * @todo standardize source and subsource names to lowercase to avoid issues like this
         */
        if ($this->entity->get('VIN_SOLUTION_LEADS')) {
            $leadSource = $this->entity->type->name;
            $subSource = $this->entity->source->name;
        }

        $source = $sources->where('Source', $leadSource)
           ->where('Sub_Source', $subSource)
           ->first();

        if (! $source) {
            $source = [
                'Backend' => 'ADVANCED_REQUEST',
                'Default_Completion_Status' => 'Incomplete',
            ];
        }

        return [
            'lead_intent' => $source['Backend'],
            'intent_completion_status' => $source['Default_Completion_Status'],
        ];
    }
}
