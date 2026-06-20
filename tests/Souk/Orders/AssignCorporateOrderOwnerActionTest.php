<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Actions\AssignCorporateOrderOwnerAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class AssignCorporateOrderOwnerActionTest extends TestCase
{
    public function testReassignsToCompanyOwnerWhenNoCreatorInMetadata(): void
    {
        $systemUser = auth()->user();
        $app = app(Apps::class);
        [$company, $owner] = $this->makeCorporateCompany($app);

        $order = $this->makeCorporateOrder($systemUser, $app, ['user_company_id' => $company->getId()]);

        $reassigned = new AssignCorporateOrderOwnerAction($order)->execute();

        $this->assertNotNull($reassigned);
        $this->assertSame($owner->getId(), (int) $reassigned->users_id);
        $this->assertSame(
            $systemUser->getId(),
            (int) $reassigned->metadata['data'][AssignCorporateOrderOwnerAction::ACTOR_METADATA_KEY]
        );
    }

    public function testReassignsToRealCreatorWhenMetadataHasAValidCompanyMember(): void
    {
        $systemUser = auth()->user();
        $app = app(Apps::class);
        [$company, $owner] = $this->makeCorporateCompany($app);

        $creator = Users::factory()->create();
        $company->associateUserApp($creator, $app, 1);

        $order = $this->makeCorporateOrder($systemUser, $app, [
            'user_company_id' => $company->getId(),
            AssignCorporateOrderOwnerAction::CREATOR_METADATA_KEY => $creator->getId(),
        ]);

        $reassigned = new AssignCorporateOrderOwnerAction($order)->execute();

        $this->assertNotNull($reassigned);
        $this->assertSame($creator->getId(), (int) $reassigned->users_id);
        $this->assertNotSame($owner->getId(), (int) $reassigned->users_id);
    }

    public function testFallsBackToOwnerWhenCreatorIsNotACompanyMember(): void
    {
        $systemUser = auth()->user();
        $app = app(Apps::class);
        [$company, $owner] = $this->makeCorporateCompany($app);

        // a user that is NOT associated with the corporate company — must not be trusted
        $outsider = Users::factory()->create();

        $order = $this->makeCorporateOrder($systemUser, $app, [
            'user_company_id' => $company->getId(),
            AssignCorporateOrderOwnerAction::CREATOR_METADATA_KEY => $outsider->getId(),
        ]);

        $reassigned = new AssignCorporateOrderOwnerAction($order)->execute();

        $this->assertNotNull($reassigned);
        $this->assertSame($owner->getId(), (int) $reassigned->users_id);
        $this->assertNotSame($outsider->getId(), (int) $reassigned->users_id);
    }

    public function testIsIdempotentAndDoesNotOverwriteTheCapturedActorOnReRun(): void
    {
        $systemUser = auth()->user();
        $app = app(Apps::class);
        [$company, $owner] = $this->makeCorporateCompany($app);

        $order = $this->makeCorporateOrder($systemUser, $app, ['user_company_id' => $company->getId()]);

        new AssignCorporateOrderOwnerAction($order)->execute();
        $secondRun = new AssignCorporateOrderOwnerAction($order->refresh())->execute();

        $this->assertNull($secondRun, 'Already-owned order must be a no-op.');
        $this->assertSame($owner->getId(), (int) $order->refresh()->users_id);
        $this->assertSame(
            $systemUser->getId(),
            (int) $order->metadata['data'][AssignCorporateOrderOwnerAction::ACTOR_METADATA_KEY]
        );
    }

    public function testIgnoresNonCorporateOrders(): void
    {
        $systemUser = auth()->user();
        $app = app(Apps::class);

        $order = $this->makeCorporateOrder($systemUser, $app, [], 'no-corporate-metadata');

        $this->assertNull(new AssignCorporateOrderOwnerAction($order)->execute());
        $this->assertSame($systemUser->getId(), (int) $order->refresh()->users_id);
    }

    /**
     * @return array{0: Companies, 1: Users}
     */
    private function makeCorporateCompany(Apps $app): array
    {
        $owner = Users::factory()->create();
        $company = Companies::factory()->create(['users_id' => $owner->getId()]);
        $company->associateApp($app);
        $company->associateUserApp($owner, $app, 1);

        return [$company, $owner];
    }

    private function makeCorporateOrder(Users $systemUser, Apps $app, array $data, string $reference = 'corporate-recharge'): Order
    {
        return Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($systemUser->getCurrentCompany()->getId())
            ->withUserId($systemUser->getId())
            ->withPeopleId($this->makePeople($systemUser, $app)->getId())
            ->create([
                'metadata' => $data ? ['data' => $data] : [],
                'reference' => $reference,
            ]);
    }

    private function makePeople(Users $systemUser, Apps $app): People
    {
        return People::factory()
            ->withUserId($systemUser->getId())
            ->withAppId($app->getId())
            ->withCompanyId($systemUser->getCurrentCompany()->getId())
            ->create();
    }
}
