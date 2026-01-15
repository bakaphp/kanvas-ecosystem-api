<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DriveCentric;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\AddActivityToDealAction;
use Kanvas\Connectors\DriveCentric\Actions\AddCommentToDealAction;
use Kanvas\Connectors\DriveCentric\Actions\PushLeadAction;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasDriveCentricConfiguration;
use Tests\TestCase;

final class PushLeadTest extends TestCase
{
    use HasDriveCentricConfiguration;

    public function testPushLeadAction(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Setup DriveCentric client
        $this->setupDriveCentricClient($app, $company);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $user->set(CustomFieldEnums::DRIVE_CENTRIC_USER_ID->value, 'd8256337-9fe4-4671-8b18-abc36e452b86');
        //$user->set(CustomFieldEnums::DRIVE_CENTRIC_USER_ID->value, 'd67d5406-e126-4ef9-841f-a42aa93039eb');
        $lead->leads_owner_id = $user->getId();
        $lead->save();

        $pushLeadAction = new PushLeadAction($lead);
        $response = $pushLeadAction->execute();

        $this->assertNotEmpty($response);
        $this->assertNotNull($lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value));
        $this->assertNotNull($lead->people->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value));
    }

    public function testUpdateLeadAction(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Setup DriveCentric client
        $this->setupDriveCentricClient($app, $company);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // First push
        $pushLeadAction = new PushLeadAction($lead);
        $response = $pushLeadAction->execute();

        $this->assertNotEmpty($response);
        $dealId = $lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);
        $this->assertNotNull($dealId);

        // Update lead title
        $lead->title = 'Updated Lead Title';
        $lead->save();

        // Push again (update)
        $pushLeadAction = new PushLeadAction($lead);
        $updatedResponse = $pushLeadAction->execute();

        $this->assertNotEmpty($updatedResponse);
        // Deal ID should remain the same
        $this->assertEquals($dealId, $lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value));
    }

    public function testAddCommentToLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Setup DriveCentric client
        $this->setupDriveCentricClient($app, $company);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $user->set(CustomFieldEnums::DRIVE_CENTRIC_USER_ID->value, 'd8256337-9fe4-4671-8b18-abc36e452b86');
        $lead->leads_owner_id = $user->getId();
        $lead->save();

        // Push lead first
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        // Add comment
        $addCommentAction = new AddCommentToDealAction($lead);
        $response = $addCommentAction->execute('Customer is interested in financing options');

        $this->assertNotEmpty($response);
    }

    public function testAddActivityToLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Setup DriveCentric client
        $this->setupDriveCentricClient($app, $company);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $user->set(CustomFieldEnums::DRIVE_CENTRIC_USER_ID->value, 'd8256337-9fe4-4671-8b18-abc36e452b86');
        $lead->leads_owner_id = $user->getId();
        $lead->save();

        // Push lead first
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        // Add activity
        $addActivityAction = new AddActivityToDealAction($lead);
        $response = $addActivityAction->execute(
            title: 'Follow-up call',
            content: 'Discussed financing options and vehicle availability'
        );

        $this->assertNotEmpty($response);
        $this->assertArrayHasKey('activities', $response);
    }
}
