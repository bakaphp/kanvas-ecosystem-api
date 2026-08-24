<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Actions;

use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplication;
use Kanvas\Connectors\Credit700\Enums\CustomFieldEnum;
use Kanvas\Connectors\Credit700\Services\CreditApplicationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Actions\UpdatePeopleDriverLicenseAction;
use Kanvas\Guild\Customers\DataTransferObject\DriverLicense;
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
     * @return array{success: bool, transaction_id: string|null, token: string|null, response: array<string, mixed>}
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

        // Keep the People row in sync so the next integration need not re-read this form.
        if ($application->driversLicenseNumber !== null) {
            new UpdatePeopleDriverLicenseAction(
                $targetPeople,
                new DriverLicense(
                    number: $application->driversLicenseNumber,
                    state: $application->driversLicenseState,
                ),
            )->execute();
        }

        $service = new CreditApplicationService($lead->app, $lead->company);
        $result = $service->submitToRouteOne($application);

        $this->storeSubmissionHistory(
            $lead,
            $targetPeople,
            $result
        );

        return $result;
    }

    /**
     * @param array{success: bool, transaction_id: string|null, token: string|null, response: array<string, mixed>} $result
     */
    protected function storeSubmissionHistory(
        Lead $lead,
        People $people,
        array $result
    ): void {
        $history = $lead->get(CustomFieldEnum::LEAD_CREDIT_APP_SUBMISSION->value) ?? [];

        $history[] = [
            'date' => date('Y-m-d H:i:s'),
            'people_id' => $people->getId(),
            'success' => $result['success'],
            'transaction_id' => $result['transaction_id'],
            'token' => $result['token'],
            'response' => $result['response'],
        ];

        $lead->set(CustomFieldEnum::LEAD_CREDIT_APP_SUBMISSION->value, $history);
    }
}
