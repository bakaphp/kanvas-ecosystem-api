<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\BatchRecipientResolverService;
use Tests\TestCase;

class BatchRecipientResolverServiceTest extends TestCase
{
    private function freshLead(Companies $company, ?int $peopleId = null): Lead
    {
        $factory = Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId());

        if ($peopleId !== null) {
            $factory = $factory->withPeopleId($peopleId);
        }

        $lead = $factory->create();
        // Factory seeds a person with a faker email; drop phones so each test adds exactly what it needs.
        $lead->people->contacts()->whereIn('contacts_types_id', Contact::PHONE_TYPES)->delete();

        return $lead;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>  lead_id => compliance_status
     */
    private function statusByLead(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['lead_id']] = (string) $row['compliance_status'];
        }

        return $map;
    }

    public function testResolvesEligibilityAndExclusionReasonsForSms(): void
    {
        $company = Companies::factory()->create();

        $eligible = $this->freshLead($company);
        $eligible->people->addPhone('+13055550001');

        $optedOut = $this->freshLead($company);
        $optedOut->people->addPhone('+13055550002', isOptOut: 1);

        $noContact = $this->freshLead($company);

        $doNotContact = $this->freshLead($company);
        $doNotContact->people->addPhone('+13055550003');
        $doNotContact->set('do_not_contact', 1);

        $result = new BatchRecipientResolverService()->resolve(
            new Collection([$eligible, $optedOut, $noContact, $doNotContact]),
            'sms',
        );

        $this->assertSame(4, $result['total_candidates']);
        $this->assertSame(1, $result['eligible_count']);
        $this->assertSame($eligible->getId(), $result['eligible'][0]['lead_id']);

        $excluded = $this->statusByLead($result['excluded']);
        $this->assertSame('opted_out_or_undeliverable', $excluded[$optedOut->getId()]);
        $this->assertSame('no_contact_info', $excluded[$noContact->getId()]);
        $this->assertSame('do_not_contact', $excluded[$doNotContact->getId()]);
    }

    public function testDedupsLeadsThatShareThePerson(): void
    {
        $company = Companies::factory()->create();

        $first = $this->freshLead($company);
        $first->people->addPhone('+13055550010');
        // Reuse the same person WITHOUT stripping contacts (freshLead would delete the shared phone).
        $second = Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId((int) $first->people_id)
            ->create();

        $result = new BatchRecipientResolverService()->resolve(
            new Collection([$first, $second]),
            'sms',
        );

        $this->assertSame(1, $result['eligible_count']);
        $this->assertSame($first->getId(), $result['eligible'][0]['lead_id']);
        $this->assertSame('duplicate', $this->statusByLead($result['excluded'])[$second->getId()]);
    }

    public function testEmailChannelUsesEmailContacts(): void
    {
        $company = Companies::factory()->create();

        $lead = Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create();
        $lead->people->addEmail('reachable@example.com');

        $result = new BatchRecipientResolverService()->resolve(new Collection([$lead]), 'email');

        $this->assertSame(1, $result['eligible_count']);
    }
}
