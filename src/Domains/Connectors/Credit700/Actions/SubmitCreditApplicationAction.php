<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Actions;

use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplication;
use Kanvas\Connectors\Credit700\Enums\CustomFieldEnum;
use Kanvas\Connectors\Credit700\Services\CreditApplicationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;

class SubmitCreditApplicationAction
{
    public function __construct(
        protected Message $message,
        protected ?People $people = null,
    ) {
    }

    /**
     * @return array{success: bool, response: array<string, mixed>}
     */
    public function execute(): array
    {
        $lead = $this->message->getEngagement()->lead;

        $formData = $this->message->getMessage()['data']['form'] ?? null;

        if (! $formData) {
            throw new ValidationException('Credit application form data not found on the message.');
        }

        if (empty($formData['personal']['ssn'])) {
            throw new ValidationException('Credit application is missing the SSN.');
        }

        $targetPeople = $this->people ?? $lead->people;

        $application = CreditApplication::from($formData, $targetPeople);

        $service = new CreditApplicationService($lead->app, $lead->company);
        $result = $service->submitToRouteOne($application);

        $this->storeSubmissionHistory($targetPeople, $lead, $result);

        return $result;
    }

    /**
     * @param array{success: bool, response: array<string, mixed>} $result
     */
    protected function storeSubmissionHistory(People $people, Lead $lead, array $result): void
    {
        $history = $people->get(CustomFieldEnum::LEAD_CREDIT_APP_SUBMISSION->value) ?? [];

        $history[] = [
            'date' => date('Y-m-d H:i:s'),
            'lead_id' => $lead->getId(),
            'success' => $result['success'],
            'response' => $result['response'],
        ];

        $people->set(CustomFieldEnum::LEAD_CREDIT_APP_SUBMISSION->value, $history);
    }
}
