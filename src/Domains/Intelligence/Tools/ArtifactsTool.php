<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Override;

class ArtifactsTool implements ContextToolInterface
{
    public function __construct(
        protected Model $entity
    ) {
    }

    #[Override]
    public function execute(array $params = []): array
    {
        $adf = $this->entity->get(LeadCustomFieldEnum::ADF_LEAD_XML->value);
        $comment = $adf ? ($adf['adf']['prospect']['customer']['comments'] ?? []) : [];

        return [
            'crm' => [
                'status' => $this->entity->status->name,
                'stage' => $this->entity->stage->name,
                'tags' => $this->entity->tags->pluck('name')->toArray(),
                'last_notes' => [
                ],
                'forms' => [
                    'prequal' => null,
                    'credit_app' => null,
                    'trade_appraisal' => null,
                    'payment_calc' => null,
                ],
                'records' => [
                    'appointment' => null,
                    'quote' => null,
                    'appraisal' => null,
                    'delivery' => null,
                ],
                ],
                'transcript_snippet' => null,
                'comments' => $comment,
        ];
    }
}
