<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Services\InsuranceQuoteBinding;
use Kanvas\Users\Models\Users;
use Tests\Insurance\Concerns\BuildsSearchCatalog;
use Tests\TestCase;

class SelectInsurancePlanTest extends TestCase
{
    use DatabaseTransactions;
    use BuildsSearchCatalog;

    protected $connectionsToTransact = [null, 'commerce', 'crm'];

    private const SELECT = '
        mutation($input: SelectInsurancePlanInput!) {
            selectInsurancePlan(input: $input) { id title }
        }
    ';

    private function planOfActingCompany(string $provider): InsurancePlan
    {
        $company = auth()->user()->getCurrentCompany();

        $this->bindFakeProvider($provider);

        return $this->seedPlan(
            $this->seedInsurer($company, $provider),
            'FULL-' . uniqid(),
            ['commission_rate' => 10]
        );
    }

    private function bindQuote(InsurancePlan $plan, string $quoteNumber, ?Users $user = null): void
    {
        InsuranceQuoteBinding::bind(
            app(Apps::class)->getId(),
            ($user ?? auth()->user())->getId(),
            $quoteNumber,
            $plan->getId()
        );
    }

    private function leadsFor(InsurancePlan $plan): array
    {
        $company = auth()->user()->getCurrentCompany();
        $people = PeoplesRepository::getByEmail(auth()->user()->email, $company, app(Apps::class));

        if ($people === null) {
            return [];
        }

        return Lead::getByCustomFieldBuilderTransactionSafe(
            InsuranceCustomFieldEnum::PLAN_ID->value,
            (string) $plan->getId(),
            $company
        )->where('people_id', $people->getId())->get()->all();
    }

    public function testSelectingAPlanCreatesALeadCarryingTheQuote(): void
    {
        $plan = $this->planOfActingCompany('select_' . uniqid());
        $this->bindQuote($plan, 'QN-1');

        $response = $this->graphQL(self::SELECT, ['input' => ['plan_id' => $plan->getId(), 'quote_number' => 'QN-1']])
            ->assertSuccessful();

        $this->assertNotNull($response->json('data.selectInsurancePlan.id'), json_encode($response->json()));

        $leads = $this->leadsFor($plan);
        $this->assertCount(1, $leads);
        $lead = $leads[0];
        $this->assertSame('QN-1', (string) $lead->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value));
        $this->assertSame($plan->insurer->provider, (string) $lead->get(InsuranceCustomFieldEnum::PROVIDER->value));
        $this->assertEquals(116.0, (float) $lead->get(InsuranceCustomFieldEnum::TOTAL->value));
        $this->assertEquals(9.0, (float) $lead->get(InsuranceCustomFieldEnum::COMMISSION->value));
        $this->assertSame('1', (string) $lead->get(InsuranceCustomFieldEnum::CAN_EMIT->value));
    }

    public function testSelectingTheSamePlanAgainUpdatesTheSameLeadInsteadOfCreatingAnother(): void
    {
        $plan = $this->planOfActingCompany('select_' . uniqid());
        $input = ['plan_id' => $plan->getId(), 'quote_number' => 'QN-1'];
        $this->bindQuote($plan, 'QN-1');
        $this->bindQuote($plan, 'QN-2');

        $first = $this->graphQL(self::SELECT, ['input' => $input])->assertSuccessful()->json('data.selectInsurancePlan.id');
        $input['quote_number'] = 'QN-2';
        $second = $this->graphQL(self::SELECT, ['input' => $input])->assertSuccessful()->json('data.selectInsurancePlan.id');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $leads = $this->leadsFor($plan);
        $this->assertCount(1, $leads);
        $this->assertSame('QN-2', (string) $leads[0]->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value));
    }

    public function testADifferentPlanGetsItsOwnLead(): void
    {
        $planA = $this->planOfActingCompany('select_' . uniqid());
        $planB = $this->planOfActingCompany('select_' . uniqid());
        $this->bindQuote($planA, 'QA');
        $this->bindQuote($planB, 'QB');

        $a = $this->graphQL(self::SELECT, ['input' => ['plan_id' => $planA->getId(), 'quote_number' => 'QA']])->json('data.selectInsurancePlan.id');
        $b = $this->graphQL(self::SELECT, ['input' => ['plan_id' => $planB->getId(), 'quote_number' => 'QB']])->json('data.selectInsurancePlan.id');

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotSame($a, $b);
    }

    public function testAPlanOfAnotherTenantCannotBeSelected(): void
    {
        $name = 'select_' . uniqid();
        $this->bindFakeProvider($name);
        $foreign = $this->seedPlan(
            $this->seedInsurer($this->createUser()->getCurrentCompany(), $name),
            'FOREIGN'
        );

        $this->bindQuote($foreign, 'QX');

        $response = $this->graphQL(self::SELECT, ['input' => ['plan_id' => $foreign->getId(), 'quote_number' => 'QX']]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertEmpty($this->leadsFor($foreign));
    }

    public function testAQuoteNumberTheSearchNeverReturnedIsRejected(): void
    {
        $plan = $this->planOfActingCompany('select_' . uniqid());

        $response = $this->graphQL(self::SELECT, ['input' => ['plan_id' => $plan->getId(), 'quote_number' => 'GUESSED']]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertEmpty($this->leadsFor($plan));
    }

    public function testAQuoteIssuedForAnotherPlanIsRejected(): void
    {
        $planA = $this->planOfActingCompany('select_' . uniqid());
        $planB = $this->planOfActingCompany('select_' . uniqid());
        $this->bindQuote($planA, 'QA');

        $response = $this->graphQL(self::SELECT, ['input' => ['plan_id' => $planB->getId(), 'quote_number' => 'QA']]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertEmpty($this->leadsFor($planB));
    }

    public function testAnotherUsersQuoteNumberIsRejected(): void
    {
        $plan = $this->planOfActingCompany('select_' . uniqid());
        $this->bindQuote($plan, 'QOTHER', $this->createUser());

        $response = $this->graphQL(self::SELECT, ['input' => ['plan_id' => $plan->getId(), 'quote_number' => 'QOTHER']]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertEmpty($this->leadsFor($plan));
    }

    public function testABlankQuoteNumberIsRejected(): void
    {
        $plan = $this->planOfActingCompany('select_' . uniqid());

        $response = $this->graphQL(self::SELECT, ['input' => ['plan_id' => $plan->getId(), 'quote_number' => '   ']]);

        $this->assertNotEmpty($response->json('errors'));
    }

    public function testAQuoteWhoseProductDiffersFromThePlanIsRejected(): void
    {
        $provider = 'select_' . uniqid();
        $this->bindFakeProvider($provider, productCode: 'SOMETHING-ELSE');
        $plan = $this->seedPlan(
            $this->seedInsurer(auth()->user()->getCurrentCompany(), $provider),
            'FULL-' . uniqid()
        );
        $this->bindQuote($plan, 'QP');

        $response = $this->graphQL(self::SELECT, ['input' => ['plan_id' => $plan->getId(), 'quote_number' => 'QP']]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertEmpty($this->leadsFor($plan));
    }

    public function testUnauthenticatedCallersAreRejected(): void
    {
        $this->app['auth']->forgetGuards();

        $this->graphQL(self::SELECT, ['input' => ['plan_id' => 1, 'quote_number' => 'Q']])
            ->assertGraphQLErrorMessage('Unauthenticated.');
    }
}
