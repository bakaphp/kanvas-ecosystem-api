<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Leads\DataTransferObject\CrmLeadSync;
use Kanvas\Guild\Leads\Enums\CrmTypeEnum;
use Tests\TestCase;
use Throwable;

final class CrmLeadSyncDtoTest extends TestCase
{
    use DatabaseTransactions;

    public function testTheFullRequestBecomesTypedSections(): void
    {
        $data = $this->build([
            'customer' => [
                'firstname' => 'Ana',
                'middlename' => null,
                'lastname' => 'Perez',
                'language' => 'es',
                'phones' => ['cell' => '7875550101', 'home' => null, 'work' => '7875550103'],
                'emails' => ['ana.perez@example.com'],
                'address' => ['address' => '123 Calle Sol', 'address_2' => null, 'city' => 'San Juan', 'state' => 'PR', 'zip' => '00901'],
                'consent' => [
                    'text_opt_status' => 'OptedIn',
                    'opt_out' => 'N',
                    'opt_out_use' => 'N',
                    'follow_up' => ['phone' => 'Y', 'mail' => 'N', 'email' => 'Y', 'text' => 'Y'],
                ],
            ],
            'lead' => [
                'type' => ['id' => 6, 'name' => 'Walk-In'],
                'source' => ['id' => 102623, 'name' => '*Drove By'],
                'status' => 'Open',
                'salesperson' => ['id' => 469364, 'name' => 'Gomez, Luis'],
                'description' => 'SUV',
            ],
            'participants' => [
                'add' => [['firstname' => 'Marco', 'lastname' => 'Perez', 'phone' => '7875550199', 'email' => 'm@example.com', 'relationship_id' => '5']],
                'remove' => [['people_id' => '77', 'relationship_id' => '5']],
            ],
            'followers' => ['attach' => ['rep.one@dealer.com'], 'detach' => []],
        ]);

        $this->assertSame(CrmTypeEnum::REYNOLDS, $data->identity->type);
        $this->assertSame('4369283', $data->identity->client_id);
        $this->assertSame('9912345', $data->identity->lead_id);
        $this->assertSame('3f1c2a54', $data->requestId);

        $this->assertSame('Perez', $data->customer->lastname);
        $this->assertSame('7875550103', $data->customer->phones->work);
        $this->assertSame('PR', $data->customer->address->state);
        $this->assertSame('Y', $data->customer->consent->follow_up->email);

        $this->assertSame('6', $data->lead->type->id, 'the CRM sends a number, the DTO holds a string');
        $this->assertSame('*Drove By', $data->lead->source->name);
        $this->assertSame('Gomez, Luis', $data->lead->salesperson->name);

        $this->assertSame('Marco', $data->participants->add[0]->firstname);
        $this->assertSame('77', $data->participants->remove[0]->people_id);
        $this->assertSame(['rep.one@dealer.com'], $data->followers->attach);
    }

    public function testOnlyTheIdentityIsRequired(): void
    {
        $data = $this->build();

        $this->assertNull($data->customer);
        $this->assertNull($data->lead);
        $this->assertNull($data->participants);
        $this->assertNull($data->followers);
    }

    /**
     * The extension sends a null for everything the CRM has not answered yet.
     */
    public function testExplicitNullsAreAcceptedEverywhere(): void
    {
        $data = $this->build([
            'identity' => ['type' => 'reynolds', 'client_id' => '4369283', 'lead_id' => null],
            'customer' => [
                'firstname' => null,
                'middlename' => null,
                'lastname' => null,
                'language' => null,
                'phones' => ['cell' => null, 'home' => null, 'work' => null],
                'emails' => [],
                'address' => null,
                'consent' => null,
            ],
            'lead' => ['type' => null, 'source' => null, 'status' => null, 'salesperson' => null],
        ]);

        $this->assertNull($data->identity->lead_id);
        $this->assertNull($data->customer->firstname);
        $this->assertNull($data->customer->address);
        $this->assertNull($data->lead->type);
        $this->assertSame([], $data->customer->phoneNumbers());
    }

    public function testTheBranchDefaultsToTheCallersCurrentOne(): void
    {
        $user = auth()->user();

        $this->assertSame($user->getCurrentBranch()->getId(), $this->build()->branch->getId());
    }

    public function testABranchOfAnotherCompanyIsRejected(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->build(['branch_id' => 999999999]);
    }

    public function testAnUnknownCrmTypeIsRejected(): void
    {
        $this->expectException(Throwable::class);

        $this->build(['identity' => ['type' => 'not-a-crm', 'client_id' => '1']]);
    }

    private function build(array $overrides = []): CrmLeadSync
    {
        $user = auth()->user();

        return CrmLeadSync::from(
            app(Apps::class),
            $user,
            $user->getCurrentCompany(),
            array_merge([
                'request_id' => '3f1c2a54',
                'identity' => ['type' => 'reynolds', 'client_id' => 4369283, 'lead_id' => 9912345],
            ], $overrides)
        );
    }
}
