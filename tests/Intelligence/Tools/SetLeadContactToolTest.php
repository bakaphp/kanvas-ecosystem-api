<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetLeadContactTool;
use Tests\TestCase;

class SetLeadContactToolTest extends TestCase
{
    public function testSwitchesContactByPeopleId(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $original = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $target = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'firstname' => 'Newton',
            'lastname' => 'Contact',
        ]);
        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'people_id' => $original->getId(),
        ]);

        $result = new SetLeadContactTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), people_id: $target->getId());

        $this->assertArrayNotHasKey('error', $result);
        $lead->refresh();
        $this->assertSame($target->getId(), (int) $lead->people_id);
        $this->assertSame('Newton', $lead->firstname);
    }

    public function testSwitchesContactByEmail(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $original = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $target = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $email = 'target' . uniqid() . '@example.com';
        $target->addEmail($email);

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'people_id' => $original->getId(),
        ]);

        $result = new SetLeadContactTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), contact: $email);

        $this->assertArrayNotHasKey('error', $result);
        $lead->refresh();
        $this->assertSame($target->getId(), (int) $lead->people_id);
    }

    public function testUnknownContactReturnsErrorAndLeavesLeadUntouched(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $original = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'people_id' => $original->getId(),
        ]);

        $result = new SetLeadContactTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), contact: 'nobody' . uniqid() . '@example.com');

        $this->assertArrayHasKey('error', $result);
        $lead->refresh();
        $this->assertSame($original->getId(), (int) $lead->people_id);
    }
}
