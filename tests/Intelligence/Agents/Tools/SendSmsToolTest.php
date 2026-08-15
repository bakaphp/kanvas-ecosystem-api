<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendSmsTool;
use Tests\TestCase;

class SendSmsToolTest extends TestCase
{
    public function testSendsSmsToDeliverablePhoneStoredOnLead(): void
    {
        $lead = $this->makeLead();
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
            'value' => '+15551234567',
            'is_opt_out' => 0,
            'weight' => 10,
        ]);

        $delivery = [];
        $tool = $this->withTenant(new SendSmsTool(
            function (Lead $sentLead, string $message, string $to) use (&$delivery): array {
                $delivery = compact('sentLead', 'message', 'to');

                return ['sid' => 'SM_TEST'];
            },
        ));

        $result = $tool->__invoke(
            lead_id: $lead->getId(),
            message: 'Hello from Sally',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame($lead->getId(), $delivery['sentLead']->getId());
        $this->assertSame('sms', $result['channel']);
        $this->assertSame('Hello from Sally', $delivery['message']);
        $this->assertSame('15551234567', $delivery['to']);
    }

    public function testRefusesOptedOutPhone(): void
    {
        $lead = $this->makeLead();
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
            'value' => '+15551234567',
            'is_opt_out' => 1,
            'weight' => 10,
        ]);

        $called = false;
        $tool = $this->withTenant(new SendSmsTool(
            function () use (&$called): array {
                $called = true;

                return [];
            },
        ));

        $result = $tool->__invoke($lead->getId(), 'Do not send');

        $this->assertSame('error', $result['status']);
        $this->assertFalse($called);
    }

    public function testRefusesDoNotContactLead(): void
    {
        $lead = $this->makeLead();
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
            'value' => '+15551234567',
            'is_opt_out' => 0,
            'weight' => 10,
        ]);
        $lead->set('do_not_contact', 1);

        $result = $this->withTenant(new SendSmsTool(fn (): array => []))
            ->__invoke($lead->getId(), 'Do not send');

        $this->assertSame('error', $result['status']);
    }

    public function testRejectsEmptyMessages(): void
    {
        $empty = $this->withTenant(new SendSmsTool(fn (): array => []))
            ->__invoke(1, '   ');

        $this->assertSame('error', $empty['status']);
    }

    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead->people->contacts()
            ->whereIn('contacts_types_id', Contact::PHONE_TYPES)
            ->delete();

        return $lead;
    }

    /**
     * Lead tools resolve their lead against the tenant on their context, so a bare instance
     * (no withContext) intentionally resolves nothing — mirror what the agent wiring does.
     *
     * @template T of object
     *
     * @param T $tool
     *
     * @return T
     */
    private function withTenant(object $tool): object
    {
        $user = auth()->user();

        return $tool->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }
}
