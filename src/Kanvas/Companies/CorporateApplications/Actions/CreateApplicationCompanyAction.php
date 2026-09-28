<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Actions;

use Baka\Support\Str;
use Closure;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company as CompanyData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

class CreateApplicationCompanyAction
{
    public function __construct(
        protected readonly Users $owner,
        protected readonly Closure $read,
        protected readonly string $fallbackName,
    ) {
    }

    public function execute(): Companies
    {
        $read = $this->read;

        return new CreateCompaniesAction(
            new CompanyData(
                user: $this->owner,
                name: Str::trimToNull((string) $read('commercial_name'))
                    ?? Str::trimToNull((string) $read('legal_name'))
                    ?? $this->fallbackName,
                email: Str::trimToNull((string) $read('contact_email')) ?? (string) $this->owner->email,
                phone: trim((string) $read('contact_phone')),
            ),
        )->execute();
    }
}
