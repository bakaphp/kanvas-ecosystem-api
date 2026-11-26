<?php

declare(strict_types=1);

namespace Tests\Scribe\GraphQL;

use Illuminate\Support\Carbon;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\CreatesTestSalesOrder;

class SalesOrderCreditGraphQLTest extends ScribeTestCase
{
    use CreatesTestSalesOrder;

    private const string CREDIT = '
        mutation ($customer: ID!, $amount: Float, $currency: String) {
            setScribeCustomerCreditLimit(customer_id: $customer, amount: $amount, currency: $currency) {
                has_limit limit currency available on_hold overdue_invoices missing_rates
            }
        }';

    public function test_a_credit_limit_is_set_read_and_removed_through_the_api(): void
    {
        $customer = $this->seedTestOrganization('Credit Graph Customer');
        $this->issueTestInvoice($customer, 400.0, dueDate: Carbon::today()->addDays(30)->toDateString());

        $this->graphQL(self::CREDIT, ['customer' => $customer->getId(), 'amount' => 1000, 'currency' => 'usd'])
            ->assertJsonPath('data.setScribeCustomerCreditLimit.has_limit', true)
            ->assertJsonPath('data.setScribeCustomerCreditLimit.currency', 'USD')
            ->assertJsonPath('data.setScribeCustomerCreditLimit.available', 600);

        $this->graphQL('
            query ($customer: ID!) {
                scribeCustomerCredit(customer_id: $customer) { limit available on_hold exposure_complete }
            }', ['customer' => $customer->getId()])
            ->assertJsonPath('data.scribeCustomerCredit.limit', 1000)
            ->assertJsonPath('data.scribeCustomerCredit.exposure_complete', true);

        $this->graphQL(self::CREDIT, ['customer' => $customer->getId()])
            ->assertJsonPath('data.setScribeCustomerCreditLimit.has_limit', false)
            ->assertJsonPath('data.setScribeCustomerCreditLimit.available', null);
    }

    public function test_a_limit_without_a_currency_is_refused(): void
    {
        $customer = $this->seedTestOrganization('Bad Limit Customer');

        $this->graphQL(self::CREDIT, ['customer' => $customer->getId(), 'amount' => 500])
            ->assertGraphQLErrorMessage('A credit limit needs an amount of zero or more and a three-letter currency.');
    }

    public function test_the_documents_are_released_or_held_back_through_the_api(): void
    {
        $customer = $this->seedTestOrganization('Release Graph Customer');
        $order = $this->createShippedSalesOrder($customer);
        $this->issueTestInvoice($customer, 100.0, dueDate: Carbon::today()->subDays(5)->toDateString());

        $this->graphQL('
            mutation ($id: ID!) {
                releaseScribeDocuments(id: $id) { documents_released_at }
            }', ['id' => (string) $order->getId()])
            ->assertGraphQLErrorMessage('The customer has 1 overdue invoice(s), the oldest 5 days overdue: the documents are not released.');

        $good = $this->createShippedSalesOrder($this->seedTestOrganization('Release Good Customer'));

        $this->graphQL('
            mutation ($id: ID!) {
                releaseScribeDocuments(id: $id) { documents_released_at }
            }', ['id' => (string) $good->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.releaseScribeDocuments.documents_released_at', fn (?string $at): bool => $at !== null);
    }

    public function test_the_order_indicators_are_listed_for_a_period(): void
    {
        $this->graphQL('
            query {
                scribeOrderKpis(from: "2026-05-01", to: "2027-02-28") { key unit target target_kind }
            }')
            ->assertSuccessful()
            ->assertJsonCount(8, 'data.scribeOrderKpis')
            ->assertJsonPath('data.scribeOrderKpis.0.key', 'acceptance_within_72h')
            ->assertJsonPath('data.scribeOrderKpis.7.unit', 'days');
    }
}
