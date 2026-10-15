<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Support\Collection;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmCustomer;

/**
 * The people who share a customer's name and at least one phone or email.
 */
final class NameAndContactPeopleFinder
{
    /**
     * Both names, compared as text with case and spacing ignored, and any one contact in common. Nothing
     * is searched without both names or without a contact: either alone would match far too much.
     *
     * @return Collection<int, People> oldest first
     */
    public function find(AppInterface $app, CompanyInterface $company, CrmCustomer $customer): Collection
    {
        if (! $customer->hasBothNames()) {
            return new Collection();
        }

        $emails = $customer->normalizedEmails();
        $phones = $this->phoneVariants($customer->phoneNumbers());

        if ($emails === [] && $phones === []) {
            return new Collection();
        }

        return PeoplesRepository::getByAnyContact($app, $company, $emails, $phones)
            ->whereRaw("LOWER(TRIM(REGEXP_REPLACE(firstname, '[[:space:]]+', ' '))) = ?", [$customer->normalizedFirstname()])
            ->whereRaw("LOWER(TRIM(REGEXP_REPLACE(lastname, '[[:space:]]+', ' '))) = ?", [$customer->normalizedLastname()])
            ->get();
    }

    /**
     * Stored phones carry a leading +1 or 1 as often as not, and Focus sends ten digits, so each
     * number is looked up with and without the country code.
     *
     * @param list<string> $digits
     *
     * @return list<string>
     */
    private function phoneVariants(array $digits): array
    {
        $variants = [];

        foreach ($digits as $phone) {
            $national = Str::normalizePhoneNumber($phone);
            $variants[] = $national;
            $variants[] = '1' . $national;
        }

        return array_values(array_unique($variants));
    }
}
