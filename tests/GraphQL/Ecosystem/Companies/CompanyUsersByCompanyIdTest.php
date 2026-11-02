<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Companies;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;
use Tests\Traits\ActsAsAppOwner;

/**
 * The Company › Users admin screen asks for one company while X-Kanvas-Location still points at the
 * company the admin visited last. The list must follow the company named in the query, not the header.
 */
final class CompanyUsersByCompanyIdTest extends TestCase
{
    use ActsAsAppOwner;

    private const string QUERY = /** @lang GraphQL */ '
        query ($companiesId: ID, $where: QueryCompanyUsersWhereWhereConditions) {
            companyUsers(companies_id: $companiesId, where: $where) {
                data { id }
            }
        }';

    public function testAnAdminListsTheCompanyTheyNameWhileTheHeaderPointsElsewhere(): void
    {
        $admin = $this->actingAsAppOwner();
        $member = $this->createUser();
        $companyId = $member->currentCompanyId();

        $this->assertNotSame($admin->currentCompanyId(), $companyId);

        $response = $this->graphQL(
            self::QUERY,
            ['companiesId' => $companyId, 'where' => $this->whereCompany($companyId)],
            [],
            $this->headerFor($admin)
        )->assertSuccessful();

        $this->assertContains((string) $member->getId(), $response->json('data.companyUsers.data.*.id'));
    }

    public function testWithoutTheArgumentTheListStaysOnTheHeaderCompany(): void
    {
        $admin = $this->actingAsAppOwner();
        $member = $this->createUser();

        $this->graphQL(
            self::QUERY,
            ['where' => $this->whereCompany($member->currentCompanyId())],
            [],
            $this->headerFor($admin)
        )->assertJsonPath('data.companyUsers.data', []);
    }

    public function testANonMemberWithoutAdminCannotListAnotherCompany(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        Bouncer::scope()->onceTo(
            RolesEnums::getScope(app(Apps::class)),
            fn () => Bouncer::retract(RolesEnums::ADMIN->value)->from($user)
        );
        Bouncer::refreshFor($user);
        $this->actingAs($user, 'api');

        $this->assertFalse($user->isAdmin());

        $this->graphQL(
            self::QUERY,
            ['companiesId' => $other->currentCompanyId()],
            [],
            $this->headerFor($user)
        )->assertGraphQLErrorMessage('User doesn\'t belong to this company ' . $other->currentCompanyId() . ' , talk to the Admin');
    }

    private function whereCompany(int $companyId): array
    {
        return ['column' => 'COMPANIES_ID', 'operator' => 'EQ', 'value' => $companyId];
    }

    private function headerFor(Users $user): array
    {
        return [
            AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => $user->getCurrentCompany()->defaultBranch()->firstOrFail()->uuid,
        ];
    }
}
