<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplicant;
use Kanvas\Connectors\Credit700\Services\CreditScoreService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\KanvasActivity;

class CreateCreditScoreActivity extends KanvasActivity
{
    /**
     * Generate a credit score for the lead.
     */
    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $people = $lead->people;
        $results = [];

        $creditScoreService = new CreditScoreService($app);
        $creditApplicant = $creditScoreService->getCreditScore(
            CreditApplicant::from($lead->people, $params['ssn'])
        );

        return [
        ];
    }
}
