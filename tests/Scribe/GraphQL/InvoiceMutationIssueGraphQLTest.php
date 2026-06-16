<?php

declare(strict_types=1);

namespace Tests\Scribe\GraphQL;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

/**
 * Happy-path GraphQL test for `issueScribeInvoice`. Same gap as BillMutationReceiveGraphQLTest —
 * the issue resolver referenced removed polymorphic columns through Phase 4 and no test exercised
 * the happy path through GraphQL.
 */
class InvoiceMutationIssueGraphQLTest extends ScribeTestCase
{
    public function test_issue_scribe_invoice_with_customer_organization_succeeds(): void
    {
        $customer = $this->seedTestOrganization('Big Customer Corp');

        $draft = new CreateInvoiceAction(
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $customer,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: 'Consulting hours',
                        quantity: 5,
                        unit_price_native: 100.0,
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                issued_date: Carbon::parse('2026-06-15'),
                due_date: Carbon::parse('2026-07-15'),
            ),
            user: static::$cachedUser,
        )->execute();

        // CreateInvoiceAction sets customer_organization_id from billable; sanity check
        $this->assertSame(
            (int) $customer->id,
            (int) $draft->customer_organization_id,
            'CreateInvoiceAction should populate customer_organization_id from BillableInterface.',
        );

        $response = $this->graphQL('
            mutation($id: ID!) {
                issueScribeInvoice(id: $id) {
                    id
                    document_status
                    invoice_number
                }
            }
        ', ['id' => $draft->id])->assertSuccessful();

        $payload = $response->json('data.issueScribeInvoice');
        $this->assertSame('ISSUED', $payload['document_status']);
        $this->assertNotNull($payload['invoice_number']);

        /** @var Invoice $issued */
        $issued = Invoice::query()->where('id', $draft->id)->firstOrFail();
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $issued->document_status);
        $this->assertSame((int) $customer->id, (int) $issued->customer_organization_id);
        $this->assertSame('Big Customer Corp', $issued->billable_display_name, 'snapshot frozen from Org.');
    }
}
