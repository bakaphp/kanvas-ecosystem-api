<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Companies;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Support\LocationContext;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;
use Tests\Traits\ActsAsAppOwner;

/**
 * The X-Kanvas-Location header lets anyone shop at a store, but it never turns a non-member into
 * someone working inside that store's company, and no admin reaches another company's branches.
 */
final class CompanyLocationAccessTest extends TestCase
{
    use ActsAsAppOwner;

    public function testTheHeaderDoesNotMoveANonMemberIntoTheStoreCompany(): void
    {
        $store = $this->createUser();
        $shopper = $this->createUser();
        $ownCompanyId = $shopper->currentCompanyId();

        $this->atLocation($this->branchOf($store));

        $this->assertSame($ownCompanyId, $shopper->currentCompanyId());
        $this->assertNotSame($this->branchOf($store)->getId(), $shopper->currentBranchId());
    }

    public function testAMemberWorksInTheHeaderCompany(): void
    {
        $store = $this->createUser();
        $branch = $this->branchOf($store);

        $this->atLocation($branch);

        $this->assertSame($branch->companies_id, $store->currentCompanyId());
        $this->assertSame($branch->getId(), $store->currentBranchId());
    }

    public function testAnAppOwnerWorksInTheHeaderCompany(): void
    {
        $branch = $this->branchOf($this->createUser());
        $owner = $this->actingAsAppOwner();

        $this->atLocation($branch);

        $this->assertSame($branch->companies_id, $owner->currentCompanyId());
    }

    public function testAShopperReadsTheCatalogAtTheStoreButNotItsAdminData(): void
    {
        $store = $this->createUser();
        $shopper = $this->createUser();
        $this->actingAs($shopper, 'api');

        $this->atLocation($this->branchOf($store));

        $this->assertSame($store->currentCompanyId(), LocationContext::companyToScope(new Products())->getId());
        $this->assertSame($shopper->currentCompanyId(), LocationContext::companyToScope(new People())->getId());
        $this->assertNotNull(LocationContext::readableCompany(Products::class, $shopper));
        $this->assertNull(LocationContext::readableCompany(People::class, $shopper));
    }

    public function testViewAllOrdersDoesNotReachIntoAnotherStore(): void
    {
        $store = $this->createUser();
        $shopper = $this->createUser();
        Bouncer::scope()->onceTo(
            RolesEnums::getScope(app(Apps::class)),
            fn () => Bouncer::allow($shopper)->to('view-all-orders')
        );
        Bouncer::refreshFor($shopper);

        $this->assertTrue(Order::canViewAllOrders($shopper));

        $this->atLocation($this->branchOf($store));

        $this->assertFalse(Order::canViewAllOrders($shopper));
    }

    public function testTheHeaderDoesNotExposeAnotherCompanysPeople(): void
    {
        $store = $this->createUser();
        $person = People::factory()
            ->withCompanyId($store->currentCompanyId())
            ->withUserId($store->getId())
            ->create();
        $query = /** @lang GraphQL */ '
            query peoples($id: Mixed!) {
                peoples(where: { column: ID, operator: EQ, value: $id }) { data { id } }
            }';
        $header = [AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => $this->branchOf($store)->uuid];

        $this->actingAs($this->createUser(), 'api');
        $this->graphQL(
            $query,
            ['id' => $person->getId()],
            [],
            $header
        )->assertJsonPath('data.peoples.data', []);

        $this->actingAs($store, 'api');
        $this->graphQL(
            $query,
            ['id' => $person->getId()],
            [],
            $header
        )->assertJsonPath('data.peoples.data.0.id', (string) $person->getId());
    }

    public function testAShopperReadsOnlyTheMessagesOnTheirOwnOrdersAtTheStore(): void
    {
        $store = $this->createUser();
        $shopper = $this->createUser();
        $own = $this->orderMessage($store, $shopper);
        $someoneElses = $this->orderMessage($store, $this->createUser());
        $this->actingAs($shopper, 'api');

        $ids = $this->graphQL(
            /** @lang GraphQL */
            '{ messages(first: 100) { data { id } } }',
            [],
            [],
            [AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => $this->branchOf($store)->uuid]
        )->json('data.messages.data.*.id');

        $this->assertContains((string) $own->getId(), $ids);
        $this->assertNotContains((string) $someoneElses->getId(), $ids);
    }

    public function testABranchOfACompanyOutsideTheAppIsRefused(): void
    {
        $branch = $this->branchOf($this->createUser());
        UserCompanyApps::where('companies_id', $branch->companies_id)
            ->where('apps_id', app(Apps::class)->getId())
            ->update(['is_deleted' => StateEnums::YES->getValue()]);

        $response = $this->graphQL(
            /** @lang GraphQL */
            '{ me { id } }',
            [],
            [],
            [AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => $branch->uuid]
        );

        $response->assertStatus(500);
        $this->assertSame(
            ['message' => 'No Company Branch configured with this key: ' . $branch->uuid],
            $response->json()
        );
    }

    public function testAnAdminCannotJoinAnotherCompanysBranch(): void
    {
        $branch = $this->branchOf($this->createUser());
        $admin = $this->actingAsAdmin();

        $this->graphQL(/** @lang GraphQL */ '
            mutation addUserToBranch($id: ID!, $userId: ID!) {
                addUserToBranch(id: $id, user_id: $userId)
            }',
            ['id' => $branch->getId(), 'userId' => $admin->getId()]
        )->assertGraphQLErrorMessage('You are not allowed to perform this action for company ' . $branch->company->name);

        $this->assertFalse($admin->isMemberOf($branch->company));
    }

    public function testAnAdminCannotEditOrDeleteAnotherCompanysBranch(): void
    {
        $branch = $this->branchOf($this->createUser());
        $this->actingAsAdmin();
        $denied = 'You are not allowed to perform this action for company ' . $branch->company->name;

        $this->graphQL(/** @lang GraphQL */ '
            mutation updateCompanyBranch($id: ID!, $input: CompanyBranchInput) {
                updateCompanyBranch(id: $id, input: $input) { id }
            }',
            ['id' => $branch->getId(), 'input' => ['name' => 'Hijacked', 'companies_id' => $branch->companies_id]]
        )->assertGraphQLErrorMessage($denied);

        $this->graphQL(/** @lang GraphQL */ '
            mutation deleteCompanyBranch($id: ID!) {
                deleteCompanyBranch(id: $id)
            }',
            ['id' => $branch->getId()]
        )->assertGraphQLErrorMessage($denied);

        $this->assertNotSame('Hijacked', $branch->refresh()->name);
        $this->assertSame(StateEnums::NO->getValue(), (int) $branch->is_deleted);
    }

    public function testAnAdminCannotEditAnotherCompany(): void
    {
        $company = $this->createUser()->getCurrentCompany();
        $this->actingAsAdmin();

        $this->graphQL(/** @lang GraphQL */ '
            mutation updateCompany($id: ID!, $input: CompanyInput) {
                updateCompany(id: $id, input: $input) { id }
            }',
            ['id' => $company->getId(), 'input' => ['name' => 'Hijacked']]
        )->assertGraphQLErrorMessage('You are not allowed to perform this action for company ' . $company->name);
    }

    public function testAUserFromAnotherAppCannotBeAddedToACompany(): void
    {
        $member = $this->createUser();
        $outsider = $this->createUser();
        UsersAssociatedApps::where('users_id', $outsider->getId())
            ->where('apps_id', app(Apps::class)->getId())
            ->delete();
        $this->actingAs($member, 'api');

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation addUserToCompany($id: ID!, $userId: ID!) {
                addUserToCompany(id: $id, user_id: $userId)
            }',
            ['id' => $member->currentCompanyId(), 'userId' => $outsider->getId()]
        );

        $this->assertNotEmpty($response->json('errors'));
        $this->assertFalse($outsider->isMemberOf($member->getCurrentCompany()));
    }

    public function testAMemberCanAddAUserToTheirOwnBranch(): void
    {
        $member = $this->createUser();
        $branch = $this->branchOf($member);
        $newcomer = $this->createUser();
        $this->actingAs($member, 'api');

        $this->graphQL(/** @lang GraphQL */ '
            mutation addUserToBranch($id: ID!, $userId: ID!) {
                addUserToBranch(id: $id, user_id: $userId)
            }',
            ['id' => $branch->getId(), 'userId' => $newcomer->getId()]
        )->assertJsonPath('data.addUserToBranch', true);

        $this->assertTrue($newcomer->isMemberOf($branch->company));
    }

    public function testEditingABranchDoesNotMakeTheEditorAMember(): void
    {
        $branch = $this->branchOf($this->createUser());
        $owner = $this->actingAsAppOwner();

        $this->graphQL(/** @lang GraphQL */ '
            mutation updateCompanyBranch($id: ID!, $input: CompanyBranchInput) {
                updateCompanyBranch(id: $id, input: $input) { id name }
            }',
            ['id' => $branch->getId(), 'input' => ['name' => 'Renamed by owner', 'companies_id' => $branch->companies_id]]
        )->assertJsonPath('data.updateCompanyBranch.name', 'Renamed by owner');

        $this->assertFalse($owner->isMemberOf($branch->company));
    }

    private function actingAsAdmin(): Users
    {
        $admin = $this->createUser();
        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isOwnerOf(app(Apps::class)));
        $this->actingAs($admin, 'api');

        return $admin;
    }

    private function orderMessage(Users $store, Users $buyer): Message
    {
        $app = app(Apps::class);
        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($store->currentCompanyId())
            ->withUserId($buyer->getId())
            ->create();
        $message = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($store->currentCompanyId())
            ->create(['users_id' => $store->getId()]);

        AppModuleMessage::firstOrCreate([
            'message_id' => $message->getId(),
            'message_types_id' => $message->message_types_id,
            'apps_id' => $app->getId(),
            'companies_id' => $store->currentCompanyId(),
            'system_modules' => Order::class,
            'entity_id' => $order->getId(),
        ]);

        return $message;
    }

    private function branchOf(Users $user): CompaniesBranches
    {
        return $user->getCurrentCompany()->defaultBranch()->firstOrFail();
    }

    private function atLocation(CompaniesBranches $branch): void
    {
        app()->scoped(CompaniesBranches::class, fn () => $branch);
    }
}
