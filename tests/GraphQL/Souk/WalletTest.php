<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Tests\TestCase;

class WalletTest extends TestCase
{
    private function getAppKeyHeader(): array
    {
        $app = app(Apps::class);

        return [AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id];
    }

    public function testFlushUserWallet(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $wallet = $user->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit(50000, ['description' => 'Test deposit for flush']);

        $this->assertGreaterThan(0, $wallet->refresh()->balanceInt);

        $response = $this->graphQL('
            mutation($userId: ID!) {
                flushUserWallet(user_id: $userId, tag: "default") {
                    balance
                    message
                }
            }
        ', ['userId' => $user->getId()], [], $this->getAppKeyHeader());

        $response->assertSuccessful()
            ->assertJson([
                'data' => [
                    'flushUserWallet' => [
                        'message' => 'User wallet flushed successfully',
                    ],
                ],
            ]);

        $this->assertEquals(0, (int) $wallet->refresh()->balanceInt);
    }

    public function testFlushCompanyWallet(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $wallet = $company->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit(75000, ['description' => 'Test deposit for flush']);

        $this->assertGreaterThan(0, $wallet->refresh()->balanceInt);

        $response = $this->graphQL('
            mutation($companyId: ID!) {
                flushCompanyWallet(company_id: $companyId, tag: "default") {
                    balance
                    message
                }
            }
        ', ['companyId' => $company->getId()], [], $this->getAppKeyHeader());

        $response->assertSuccessful()
            ->assertJson([
                'data' => [
                    'flushCompanyWallet' => [
                        'message' => 'Company wallet flushed successfully',
                    ],
                ],
            ]);

        $this->assertEquals(0, (int) $wallet->refresh()->balanceInt);
    }

    public function testFlushEmptyWallet(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $wallet = $company->createAppWallet($app, ['name' => 'flush-empty-test']);

        $this->assertEquals(0, (int) $wallet->balanceInt);

        $response = $this->graphQL('
            mutation($companyId: ID!) {
                flushCompanyWallet(company_id: $companyId, tag: "flush-empty-test") {
                    balance
                    message
                }
            }
        ', ['companyId' => $company->getId()], [], $this->getAppKeyHeader());

        $response->assertSuccessful()
            ->assertJson([
                'data' => [
                    'flushCompanyWallet' => [
                        'message' => 'Company wallet flushed successfully',
                    ],
                ],
            ]);
    }
}
