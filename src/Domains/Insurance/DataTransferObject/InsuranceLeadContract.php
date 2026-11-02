<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;

class InsuranceLeadContract
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly Lead $lead,
        public readonly InsuranceCustomer $suppliedCustomer,
    ) {
    }

    public static function fromMultiple(
        AppInterface $app,
        UserInterface $user,
        CompanyInterface $company,
        array $data
    ): self {
        $lead = Lead::getByIdFromCompanyApp((int) $data['lead_id'], $company, $app);

        if ((int) $lead->users_id !== $user->getId()) {
            throw new ValidationException('Quote not found');
        }

        return new self(
            app: $app,
            company: $company,
            user: $user,
            lead: $lead,
            suppliedCustomer: InsuranceCustomer::fromArray((array) ($data['customer'] ?? [])),
        );
    }
}
