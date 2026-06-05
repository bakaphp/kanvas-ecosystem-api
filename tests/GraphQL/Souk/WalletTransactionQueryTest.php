<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class WalletTransactionQueryTest extends TestCase
{
    public function testHasMetaFilterBySourceOnUserTransactions(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $wallet = $user->createAppWallet($app, ['name' => 'default']);

        $wallet->deposit(10000, ['source' => 'recharge_manual', 'description' => 'manual top-up']);
        $wallet->deposit(5000, ['source' => 'recharge_auto', 'description' => 'auto top-up']);

        $response = $this->graphQL('
            query($tag: String!, $hasMeta: WalletMetaFilter) {
                getUserWalletTransactions(tag: $tag, hasMeta: $hasMeta) {
                    data {
                        id
                        type
                        amount
                        meta
                    }
                }
            }
        ', [
            'tag' => 'default',
            'hasMeta' => ['path' => 'source', 'value' => 'recharge_manual', 'operator' => 'EQ'],
        ]);

        $response->assertSuccessful();
        $transactions = $response->json('data.getUserWalletTransactions.data');
        $this->assertNotEmpty($transactions);

        foreach ($transactions as $tx) {
            $this->assertEquals('recharge_manual', $tx['meta']['source'] ?? null);
        }
    }

    public function testHasMetaFilterByOrderIdOnUserTransactions(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $wallet = $user->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit(8000, ['order_id' => 999, 'description' => 'order-backed deposit']);
        $wallet->deposit(3000, ['order_id' => 888, 'description' => 'other order']);

        $response = $this->graphQL('
            query($tag: String!, $hasMeta: WalletMetaFilter) {
                getUserWalletTransactions(tag: $tag, hasMeta: $hasMeta) {
                    data {
                        id
                        meta
                    }
                }
            }
        ', [
            'tag' => 'default',
            'hasMeta' => ['path' => 'order_id', 'value' => '999', 'operator' => 'EQ'],
        ]);

        $response->assertSuccessful();
        $transactions = $response->json('data.getUserWalletTransactions.data');
        $this->assertNotEmpty($transactions);
        $this->assertEquals(999, $transactions[0]['meta']['order_id'] ?? null);
    }

    public function testHasMetaFilterOnCompanyWalletTransactions(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $wallet = $company->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit(20000, ['source' => 'recharge_manual', 'description' => 'corporate recharge']);
        $wallet->deposit(5000, ['source' => 'payment', 'description' => 'payment deduction sim']);

        $response = $this->graphQL('
            query($tag: String!, $companyId: ID!, $hasMeta: WalletMetaFilter) {
                getCompanyWalletTransactions(tag: $tag, company_id: $companyId, hasMeta: $hasMeta) {
                    data {
                        id
                        type
                        meta
                    }
                }
            }
        ', [
            'tag' => 'default',
            'companyId' => $company->getId(),
            'hasMeta' => ['path' => 'source', 'value' => 'recharge_manual', 'operator' => 'EQ'],
        ]);

        $response->assertSuccessful();
        $transactions = $response->json('data.getCompanyWalletTransactions.data');
        $this->assertNotEmpty($transactions);

        foreach ($transactions as $tx) {
            $this->assertEquals('recharge_manual', $tx['meta']['source'] ?? null);
        }
    }

    public function testFilterCompanyWalletByBulkRechargeTagNumber(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $wallet = $company->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit(100000, ['source' => 'recharge_manual', 'description' => 'corporate top-up']);

        $wallet->withdraw(50000, [
            'source' => 'payment',
            'service' => 'paso_rapido',
            'tag_number' => '941001',
            'order_id' => 12345,
            'reason' => 'Corporate bulk recharge TAG 941001',
        ]);
        $wallet->withdraw(30000, [
            'source' => 'payment',
            'service' => 'paso_rapido',
            'tag_number' => '941002',
            'order_id' => 12345,
            'reason' => 'Corporate bulk recharge TAG 941002',
        ]);

        $response = $this->graphQL('
            query($tag: String!, $companyId: ID!, $hasMeta: WalletMetaFilter) {
                getCompanyWalletTransactions(tag: $tag, company_id: $companyId, hasMeta: $hasMeta) {
                    data {
                        id
                        type
                        amount
                        meta
                    }
                }
            }
        ', [
            'tag' => 'default',
            'companyId' => $company->getId(),
            'hasMeta' => ['path' => 'tag_number', 'value' => '941001', 'operator' => 'EQ'],
        ]);

        $response->assertSuccessful();
        $transactions = $response->json('data.getCompanyWalletTransactions.data');
        $this->assertNotEmpty($transactions);

        foreach ($transactions as $tx) {
            $this->assertEquals('941001', $tx['meta']['tag_number'] ?? null);
            $this->assertEquals('paso_rapido', $tx['meta']['service'] ?? null);
        }
    }

    public function testPaginationLimitsResults(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $wallet = $user->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit(1000, ['description' => 'first']);
        $wallet->deposit(2000, ['description' => 'second']);
        $wallet->deposit(3000, ['description' => 'third']);

        $response = $this->graphQL('
            query($tag: String!) {
                getUserWalletTransactions(tag: $tag, first: 2) {
                    data {
                        id
                        amount
                        created_at
                    }
                    paginatorInfo {
                        total
                        currentPage
                        hasMorePages
                    }
                }
            }
        ', ['tag' => 'default']);

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'getUserWalletTransactions' => [
                        'data' => [['id', 'amount', 'created_at']],
                        'paginatorInfo' => ['total', 'currentPage', 'hasMorePages'],
                    ],
                ],
            ]);

        $transactions = $response->json('data.getUserWalletTransactions.data');
        $this->assertCount(2, $transactions);
        $this->assertTrue($response->json('data.getUserWalletTransactions.paginatorInfo.hasMorePages'));
    }
}
