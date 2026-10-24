<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class LeadDealsRelationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    public function testALeadListsItsLiveDealsThroughTheModelAndTheApi(): void
    {
        $lead = $this->makeLead();
        $live = $this->makeDeal($lead);
        $deleted = $this->makeDeal($lead);
        $deleted->softDelete();
        $this->makeDeal($this->makeLead());

        $this->assertSame([$live->getId()], $lead->deals->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame($lead->getId(), (int) $live->lead->getId());

        $this->graphQL('
            query($id: Mixed) {
                leads(where: { column: ID, operator: EQ, value: $id }) {
                    data {
                        id
                        deals { id title lead { id } }
                    }
                }
            }
        ', ['id' => $lead->getId()])->assertJson([
            'data' => [
                'leads' => [
                    'data' => [[
                        'id' => (string) $lead->getId(),
                        'deals' => [[
                            'id' => (string) $live->getId(),
                            'title' => $live->title,
                            'lead' => ['id' => (string) $lead->getId()],
                        ]],
                    ]],
                ],
            ],
        ]);
    }

    private function makeLead(): Lead
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();
    }

    private function makeDeal(Lead $lead): Deal
    {
        $deal = new Deal();
        $deal->apps_id = $lead->apps_id;
        $deal->companies_id = $lead->companies_id;
        $deal->users_id = $lead->users_id;
        $deal->owner_id = $lead->users_id;
        $deal->leads_id = $lead->getId();
        $deal->people_id = $lead->people_id;
        $deal->title = 'Deal of lead ' . $lead->getId() . ' ' . uniqid();
        $deal->is_deleted = 0;
        $deal->saveOrFail();

        return $deal;
    }
}
