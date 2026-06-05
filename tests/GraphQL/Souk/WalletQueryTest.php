<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class WalletQueryTest extends TestCase
{
    public function testGetUserWalletReturnsBalance(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $wallet = $user->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit(25000, ['description' => 'Test deposit']);

        $response = $this->graphQL('
            query($tag: String!) {
                getUserWallet(tag: $tag) {
                    balance
                    message
                }
            }
        ', ['tag' => 'default']);

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'getUserWallet' => ['balance', 'message'],
                ],
            ]);

        $balance = $response->json('data.getUserWallet.balance');
        $this->assertGreaterThan(0, $balance);
    }

    public function testGetCompanyWalletReturnsBalanceForAdmin(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $wallet = $company->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit(50000, ['description' => 'Corporate test deposit']);

        $response = $this->graphQL('
            query($tag: String!, $companyId: ID!) {
                getCompanyWallet(tag: $tag, company_id: $companyId) {
                    balance
                    message
                }
            }
        ', ['tag' => 'default', 'companyId' => $company->getId()]);

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'getCompanyWallet' => ['balance', 'message'],
                ],
            ]);

        $balance = $response->json('data.getCompanyWallet.balance');
        $this->assertGreaterThanOrEqual(0, $balance);
    }

    public function testGetCompanyWalletRejectsNonMember(): void
    {
        $app = app(Apps::class);
        /** @var Users $owner */
        $owner = auth()->user();

        // Company owned by the default test user but a fresh non-admin user has no association
        $otherCompany = Companies::factory()->create([
            'users_id' => $owner->getId(),
        ]);
        $otherCompany->associateApp($app);

        // Use a raw factory user (no RegisterUsersAction → no Admin role on any company),
        // otherwise CompaniesRepository::userAssociatedToCompany short-circuits via isAdmin().
        $freshUser = Users::factory()->create();
        $this->actingAs($freshUser, 'api');

        $response = $this->graphQL('
            query($tag: String!, $companyId: ID!) {
                getCompanyWallet(tag: $tag, company_id: $companyId) {
                    balance
                }
            }
        ', ['tag' => 'default', 'companyId' => $otherCompany->getId()]);

        $response->assertSuccessful();
        $errorMessage = $response->json('errors.0.message');
        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString("doesn't belong to this company", $errorMessage);
    }
}
