<?php

declare(strict_types=1);

namespace Tests\GraphQL\Analytics;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

class LeadAnalyticsTest extends TestCase
{
    public function testLeadAnalyticsHappyPath(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        Lead::factory()->count(2)->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'created_at' => Carbon::now()->subDays(2),
        ]);

        Lead::factory()->count(3)->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'created_at' => Carbon::now()->subDays(9),
        ]);

        $response = $this->graphQL('
            query($from: Date!, $to: Date!) {
                leadAnalytics(from: $from, to: $to, bucket: DAY) {
                    total
                    periods { period count total }
                    by_status { name key count }
                    by_source { name key count }
                    by_pipeline { name key count }
                    by_user { name key count }
                }
            }
        ', [
            'from' => Carbon::now()->subDays(30)->toDateString(),
            'to' => Carbon::now()->toDateString(),
        ]);

        if ($response->json('errors')) {
            $this->fail('GraphQL errors: ' . json_encode($response->json('errors')));
        }

        $response
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'leadAnalytics' => [
                    'total',
                    'periods' => [['period', 'count', 'total']],
                    'by_status',
                    'by_source',
                    'by_pipeline',
                    'by_user',
                ],
            ],
        ]);

        $this->assertGreaterThanOrEqual(
            5,
            (int) $this->graphQL('
                query {
                    leadAnalytics(bucket: DAY) { total }
                }
            ')->json('data.leadAnalytics.total'),
        );
    }

    public function testLeadAnalyticsDoesNotLeakAcrossCompanies(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $otherCompanyId = $company->getId() + 99999;

        $baselineTotal = (int) $this->graphQL('
            query {
                leadAnalytics(bucket: DAY) { total }
            }
        ')->json('data.leadAnalytics.total');

        $now = Carbon::now();
        $leakRows = [];
        for ($i = 0; $i < 12; $i++) {
            $leakRows[] = [
                'uuid' => (string) Str::uuid(),
                'apps_id' => $app->getId(),
                'companies_id' => $otherCompanyId,
                'users_id' => $user->getId(),
                'companies_branches_id' => 0,
                'leads_owner_id' => $user->getId(),
                'leads_status_id' => 0,
                'leads_sources_id' => 0,
                'leads_types_id' => 0,
                'people_id' => 0,
                'organization_id' => 0,
                'title' => 'leak-test-' . $i,
                'firstname' => 'leak',
                'lastname' => 'test',
                'email' => "leak{$i}@example.com",
                'phone' => '000',
                'status' => 0,
                'is_deleted' => 0,
                'created_at' => $now->copy()->subDay(),
                'updated_at' => $now,
            ];
        }

        DB::connection('crm')->table('leads')->insert($leakRows);

        try {
            $afterLeakTotal = (int) $this->graphQL('
                query {
                    leadAnalytics(bucket: DAY) { total }
                }
            ')->json('data.leadAnalytics.total');

            $this->assertSame(
                $baselineTotal,
                $afterLeakTotal,
                'Analytics leaked rows from another company (expected no change after seeding other-company leads)',
            );
        } finally {
            DB::connection('crm')
                ->table('leads')
                ->where('companies_id', $otherCompanyId)
                ->delete();
        }
    }
}
