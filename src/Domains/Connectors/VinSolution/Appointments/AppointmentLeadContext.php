<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Appointments;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Services\LeadUserService;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * The VinSolutions ids an appointment call needs, resolved off a lead. Each is nullable: a missing one
 * is an error for the push and a skip for the pull, so the caller decides.
 */
final readonly class AppointmentLeadContext
{
    public function __construct(
        public ?string $dealerId,
        public ?string $leadId,
        public ?string $customerId,
        public ?string $assignedUserId,
        public ?UserInterface $assignedUser,
    ) {
    }

    public static function fromLead(Lead $lead): self
    {
        $company = $lead->company;
        $assignedUser = LeadUserService::resolve($lead);
        $assignedUserId = $assignedUser?->get(ConfigurationEnum::getUserKey($company, $assignedUser));

        return new self(
            dealerId: Str::trimToNull((string) $company->get(ConfigurationEnum::COMPANY->value)),
            leadId: Str::trimToNull((string) $lead->get(CustomFieldEnum::LEADS->value)),
            customerId: Str::trimToNull((string) $lead->people?->get(CustomFieldEnum::CONTACT->value)),
            assignedUserId: Str::trimToNull((string) $assignedUserId),
            assignedUser: $assignedUser,
        );
    }
}
