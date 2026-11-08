<?php

declare(strict_types=1);

namespace Tests\Scribe\BillingSchedules;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListBillingSchedulesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ScheduleBillingTool;
use Kanvas\NervousSystem\Ledger\Models\Event as LedgerEvent;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use Kanvas\Scribe\BillingSchedules\Actions\CreateBillingScheduleAction;
use Kanvas\Scribe\BillingSchedules\DataTransferObject\BillingSchedule as BillingScheduleData;
use Kanvas\Scribe\BillingSchedules\Enums\BillingCadenceEnum;
use Kanvas\Scribe\BillingSchedules\Enums\BillingScheduleRunStatusEnum;
use Kanvas\Scribe\BillingSchedules\Enums\BillingScheduleStatusEnum;
use Kanvas\Scribe\BillingSchedules\Jobs\RunBillingScheduleJob;
use Kanvas\Scribe\BillingSchedules\Models\BillingSchedule;
use Kanvas\Scribe\BillingSchedules\Models\BillingScheduleLine;
use Kanvas\Scribe\BillingSchedules\Models\BillingScheduleRun;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Actions\AcceptQuoteAction;
use Kanvas\Scribe\Quotes\Actions\ConvertQuoteToInvoiceAction;
use Kanvas\Scribe\Quotes\Actions\SendQuoteAction;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteTransitionException;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Sow\Actions\RecordQuoteSowAction;
use Kanvas\Scribe\Quotes\Sow\DataTransferObject\StatementOfWork;
use RuntimeException;
use Tests\Scribe\ScribeTestCase;

class BillingScheduleTest extends ScribeTestCase
{
    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting', 'intelligence', 'crm', 'social'];

    public function test_a_due_period_becomes_exactly_one_draft_invoice_submitted_as_agent_work(): void
    {
        $customer = $this->seedTestOrganization('Retainer Co');
        $schedule = $this->monthlySchedule($customer, '2026-06-01');

        $origins = [];
        $captureOrigin = function () use (&$origins): void {
            $origins[] = ApprovalOriginService::current();
        };
        Event::listen('eloquent.creating: ' . ApprovalRequest::class, $captureOrigin);
        Event::listen('eloquent.creating: ' . ApprovalQueueItem::class, $captureOrigin);

        $this->runJob($schedule, '2026-06-01');
        $this->runJob($schedule, '2026-06-01');

        $invoices = $this->invoicesFor($customer);
        $this->assertCount(1, $invoices, 'One period, one invoice, however often the job runs.');

        $invoice = $invoices->first();
        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $invoice->document_status);
        $this->assertEqualsWithDelta(2000.0, (float) $invoice->total_native, 0.001);
        $this->assertSame('2026-06-01', $invoice->issued_date->toDateString());
        $this->assertSame($schedule->getId(), $invoice->metadata['billing_schedule_id']);

        $this->assertSame([ApprovalOriginEnum::AGENT], $origins, 'Submitted once, as agent-originated work.');
        $this->assertTrue(
            $invoice->pendingApproval() !== null
            || ApprovalQueueItem::query()->where('target_type', 'invoice')->where('target_id', $invoice->getId())->exists(),
        );

        $schedule->refresh();
        $this->assertSame('2026-07-01', $schedule->next_run_at->toDateString());
        $this->assertSame(1, BillingScheduleRun::query()->where('billing_schedule_id', $schedule->getId())->count());
        $this->assertTrue(
            LedgerEvent::query()
                ->where('event_type', 'scribe.billing_schedule.invoiced')
                ->where('source_entity_id', $schedule->getId())
                ->exists()
        );
    }

    public function test_a_failed_submission_leaves_the_run_failed_and_the_next_run_resumes_the_same_draft(): void
    {
        Carbon::setTestNow('2026-06-01 03:00:00');
        $customer = $this->seedTestOrganization('Flaky Approval Co');
        $schedule = $this->monthlySchedule($customer, '2026-06-01');

        $failures = 1;
        $failOnce = function () use (&$failures): void {
            if ($failures-- > 0) {
                throw new RuntimeException('Approval service unavailable');
            }
        };
        Event::listen('eloquent.creating: ' . ApprovalRequest::class, $failOnce);
        Event::listen('eloquent.creating: ' . ApprovalQueueItem::class, $failOnce);

        try {
            $this->runJob($schedule, '2026-06-01');
        } catch (RuntimeException) {
        }

        $run = BillingScheduleRun::query()->where('billing_schedule_id', $schedule->getId())->firstOrFail();
        $this->assertSame(BillingScheduleRunStatusEnum::FAILED, $run->status);
        $this->assertSame(1, $run->attempts);
        $this->assertStringContainsString('Approval service unavailable', (string) $run->last_error);
        $this->assertNotNull($run->next_retry_at);
        $this->assertSame('2026-07-01', $schedule->refresh()->next_run_at->toDateString());

        Carbon::setTestNow($run->next_retry_at->copy()->addMinute());
        $this->artisan('scribe:run-billing-schedules', ['--today' => '2026-06-01', '--sync' => true])->assertSuccessful();

        $run->refresh();
        $this->assertSame(BillingScheduleRunStatusEnum::SUBMITTED, $run->status);
        $this->assertSame(2, $run->attempts);
        $this->assertNull($run->next_retry_at);

        $invoices = $this->invoicesFor($customer);
        $this->assertCount(1, $invoices, 'The retry resumes the draft, it never bills the period again.');
        $this->assertSame($run->invoice_id, $invoices->first()->getId());
        $this->assertTrue(
            $invoices->first()->pendingApproval() !== null
            || ApprovalQueueItem::query()->where('target_type', 'invoice')->where('target_id', $run->invoice_id)->exists(),
        );
    }

    public function test_a_schedule_that_fell_behind_bills_each_missed_period_once(): void
    {
        $customer = $this->seedTestOrganization('Catch Up Co');
        $schedule = $this->monthlySchedule($customer, '2026-04-01');

        $this->runJob($schedule, '2026-06-01');
        $this->runJob($schedule, '2026-06-01');

        $this->assertSame(
            ['2026-04-01', '2026-05-01', '2026-06-01'],
            $this->invoicesFor($customer)->map(fn (Invoice $invoice): string => $invoice->issued_date->toDateString())->all(),
        );
        $this->assertSame('2026-07-01', $schedule->refresh()->next_run_at->toDateString());
    }

    public function test_a_period_not_yet_due_is_not_billed_and_a_paused_schedule_never_is(): void
    {
        $customer = $this->seedTestOrganization('Not Yet Co');
        $future = $this->monthlySchedule($customer, '2026-06-15');
        $paused = $this->monthlySchedule($customer, '2026-05-01', BillingScheduleStatusEnum::PAUSED);

        $this->runJob($future, '2026-06-01');
        $this->runJob($paused, '2026-06-01');

        $this->assertCount(0, $this->invoicesFor($customer));
    }

    public function test_a_once_schedule_and_an_ended_schedule_finish(): void
    {
        $customer = $this->seedTestOrganization('Finishing Co');
        $once = $this->createSchedule($customer, BillingCadenceEnum::ONCE, '2026-06-01');
        $ending = $this->createSchedule(
            $customer,
            BillingCadenceEnum::MONTHLY,
            '2026-05-01',
            endDate: '2026-06-10'
        );

        $this->runJob($once, '2026-06-01');
        $this->runJob($ending, '2026-06-01');

        $this->assertSame(BillingScheduleStatusEnum::FINISHED, $once->refresh()->status);
        $this->assertNull($once->next_run_at);
        $this->assertSame(BillingScheduleStatusEnum::FINISHED, $ending->refresh()->status);
        $this->assertCount(3, $this->invoicesFor($customer));
    }

    public function test_the_anchor_day_survives_short_months(): void
    {
        $cadence = BillingCadenceEnum::MONTHLY;

        $february = $cadence->nextPeriod(Carbon::parse('2026-01-31'), 31);
        $march = $cadence->nextPeriod($february, 31);

        $this->assertSame('2026-02-28', $february->toDateString());
        $this->assertSame('2026-03-31', $march->toDateString());
        $this->assertSame('2026-09-30', BillingCadenceEnum::QUARTERLY->nextPeriod(Carbon::parse('2026-06-30'), 30)->toDateString());
        $this->assertNull(BillingCadenceEnum::ONCE->nextPeriod(Carbon::parse('2026-06-30')));
    }

    public function test_accepting_a_quote_with_a_sow_turns_its_payment_schedule_into_billing(): void
    {
        Queue::fake();
        $quote = $this->sentQuoteWithSow('Signed Sow Co');

        $accepted = new AcceptQuoteAction($quote, static::$cachedUser)->execute();
        new AcceptQuoteAction($accepted, static::$cachedUser)->execute();

        $schedules = BillingSchedule::query()->where('source_quote_id', $quote->getId())->orderBy('id')->get();
        $this->assertCount(2, $schedules, 'Re-accepting must not schedule the payments twice.');
        $this->assertSame(BillingCadenceEnum::ONCE, $schedules[0]->cadence);
        $this->assertSame('2026-06-01', $schedules[0]->next_run_at->toDateString(), 'on_signature bills today.');
        $this->assertSame('2026-06-20', $schedules[1]->next_run_at->toDateString());

        Queue::assertPushed(RunBillingScheduleJob::class, 1);

        $this->runJob($schedules[0], '2026-06-01');

        $deposit = $this->invoicesFor($quote->customer)->first();
        $this->assertEqualsWithDelta(
            2050.0,
            (float) $deposit->total_native,
            0.001,
            'Half of the 4,100 quote.'
        );
        $this->assertEqualsWithDelta(
            50.0,
            (float) $deposit->tax_native,
            0.001,
            'Half of the quote tax.'
        );
        $this->assertSame($quote->getId(), $deposit->quote_id);

        $this->expectException(InvalidQuoteTransitionException::class);
        new ConvertQuoteToInvoiceAction($quote->refresh())->execute();
    }

    public function test_accepting_a_quote_without_a_sow_schedules_nothing(): void
    {
        Queue::fake();
        $quote = $this->sentQuote('Plain Quote Co');

        new AcceptQuoteAction($quote, static::$cachedUser)->execute();

        $this->assertFalse(BillingSchedule::query()->where('source_quote_id', $quote->getId())->exists());
        Queue::assertNotPushed(RunBillingScheduleJob::class);
    }

    public function test_schedule_billing_creates_pauses_and_resumes_and_list_reads_it(): void
    {
        $this->seedTestOrganization('Tool Retainer Co');
        $tool = new ScheduleBillingTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $created = $tool->__invoke(
            action: 'create',
            customer_name: 'Tool Retainer Co',
            lines: [['description' => 'Support retainer', 'unit_price' => 1500]],
            cadence: 'monthly',
            start_date: '2026-06-05',
            net_terms_days: 15,
        );

        $this->assertTrue($created['saved'], json_encode($created));
        $this->assertSame('2026-06-05', $created['next_run_at']);
        $this->assertEqualsWithDelta(1500.0, $created['amount_per_period'], 0.001);

        $paused = $tool->__invoke(action: 'pause', billing_schedule_id: $created['billing_schedule_id']);
        $this->assertSame('paused', $paused['status']);

        $list = new ListBillingSchedulesTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: 'Tool Retainer Co');
        $this->assertSame(1, $list['count']);
        $this->assertSame('paused', $list['billing_schedules'][0]['status']);

        $resumed = $tool->__invoke(action: 'resume', billing_schedule_id: $created['billing_schedule_id']);
        $this->assertSame('active', $resumed['status']);
    }

    public function test_schedule_billing_refuses_bad_input_without_saving(): void
    {
        $this->seedTestOrganization('Tool Refusal Co');
        $tool = new ScheduleBillingTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $this->assertSame('invalid_action', $tool->__invoke(action: 'delete')['reason']);
        $this->assertSame('invalid_cadence', $tool->__invoke(action: 'create', customer_name: 'Tool Refusal Co', cadence: 'weekly')['reason']);
        $this->assertSame('invalid_schedule', $tool->__invoke(
            action: 'create',
            customer_name: 'Tool Refusal Co',
            lines: [['description' => 'No price']],
            cadence: 'monthly',
        )['reason']);
        $this->assertSame('billing_schedule_not_found', $tool->__invoke(action: 'pause', billing_schedule_id: 999999999)['reason']);
    }

    public function test_an_invalid_line_is_refused_with_its_position_and_valid_lines_are_stored_as_typed_rows(): void
    {
        $customer = $this->seedTestOrganization('Typed Lines Co');
        $input = fn (array $lines): array => [
            'customer' => $customer,
            'currency' => 'USD',
            'lines' => $lines,
            'cadence' => 'monthly',
            'start_date' => '2026-06-01',
        ];

        foreach ([
            'quantity must be a number' => [['description' => 'Hosting', 'unit_price' => 100], ['description' => 'Support', 'unit_price' => 50, 'quantity' => 'two']],
            'unit_price is required' => [['description' => 'Hosting', 'unit_price' => 100], ['description' => 'No price']],
            'tax_amount must be a number' => [['description' => 'Hosting', 'unit_price' => 100, 'tax_amount' => 'n/a']],
        ] as $expected => $lines) {
            try {
                new CreateBillingScheduleAction(
                    data: BillingScheduleData::from($this->kanvasApp, $this->company, $input($lines)),
                    user: static::$cachedUser,
                )->execute();
                $this->fail("Accepted lines that should fail with '{$expected}'.");
            } catch (ValidationException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
        $this->assertFalse(BillingSchedule::query()->where('customer_organization_id', $customer->getId())->exists());

        $schedule = new CreateBillingScheduleAction(
            data: BillingScheduleData::from($this->kanvasApp, $this->company, $input([
                ['description' => 'Hosting', 'unit_price' => '100.125', 'quantity' => 2],
                ['description' => 'Support', 'unit_price' => 50, 'tax_rate' => 18, 'item_id' => 7],
            ])),
            user: static::$cachedUser,
        )->execute();

        $lines = BillingScheduleLine::query()->where('billing_schedule_id', $schedule->getId())->orderBy('sort_order')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('100.1250', $lines[0]->unit_price);
        $this->assertSame('2.0000', $lines[0]->quantity);
        $this->assertSame('9.0000', $lines[1]->tax_amount, '18% of 50.');
        $this->assertSame(7, $lines[1]->item_id);
        $this->assertEqualsWithDelta(259.25, $schedule->refresh()->linesTotal(), 0.0001);
    }

    public function test_the_daily_command_bills_due_schedules(): void
    {
        $customer = $this->seedTestOrganization('Command Co');
        $schedule = $this->monthlySchedule($customer, '2026-06-01');

        $this->artisan('scribe:run-billing-schedules', ['--today' => '2026-06-01', '--sync' => true])->assertSuccessful();

        $this->assertCount(1, $this->invoicesFor($customer));
        $this->assertSame('2026-07-01', $schedule->refresh()->next_run_at->toDateString());
    }

    private function monthlySchedule(
        Organization $customer,
        string $start,
        BillingScheduleStatusEnum $status = BillingScheduleStatusEnum::ACTIVE
    ): BillingSchedule {
        return $this->createSchedule(
            $customer,
            BillingCadenceEnum::MONTHLY,
            $start,
            status: $status
        );
    }

    private function createSchedule(
        Organization $customer,
        BillingCadenceEnum $cadence,
        string $start,
        ?string $endDate = null,
        BillingScheduleStatusEnum $status = BillingScheduleStatusEnum::ACTIVE,
    ): BillingSchedule {
        return new CreateBillingScheduleAction(
            data: BillingScheduleData::from($this->kanvasApp, $this->company, [
                'customer' => $customer,
                'currency' => 'USD',
                'lines' => [['description' => 'Monthly retainer', 'unit_price' => 2000]],
                'cadence' => $cadence->value,
                'start_date' => $start,
                'end_date' => $endDate,
                'anchor_day' => Carbon::parse($start)->day,
                'status' => $status->value,
            ]),
            user: static::$cachedUser,
        )->execute();
    }

    private function runJob(BillingSchedule $schedule, string $today): void
    {
        new RunBillingScheduleJob($this->kanvasApp, $schedule, $today)->handle();
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function invoicesFor(Organization $customer): Collection
    {
        return Invoice::query()
            ->where('customer_organization_id', $customer->getId())
            ->where('companies_id', $this->company->getId())
            ->orderBy('issued_date')
            ->get();
    }

    private function sentQuote(string $customerName): Quote
    {
        $this->seedTestOrganization($customerName);

        $created = new CreateQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: $customerName, lines: [
                ['description' => 'Discovery workshop', 'unit_price' => 1000.0, 'tax_amount' => 100.0],
                ['description' => 'Mobile app build', 'unit_price' => 3000.0],
            ]);

        $quote = Quote::getById($created['quote_id']);

        return new SendQuoteAction(quote: $quote, billable: $quote->customer, user: static::$cachedUser)->execute();
    }

    private function sentQuoteWithSow(string $customerName): Quote
    {
        $quote = $this->sentQuote($customerName);

        new RecordQuoteSowAction($quote, StatementOfWork::fromInput([
            'summary' => 'Build the app.',
            'scope' => ['Discovery workshop', 'Mobile app build'],
            'out_of_scope' => [],
            'assumptions' => [],
            'deliverables' => [['name' => 'The app', 'acceptance_criteria' => 'Published.']],
            'timeline' => ['start' => '2026-06-01', 'end' => '2026-06-30'],
            'payment_schedule' => [
                ['label' => 'Deposit', 'when' => 'on_signature', 'percent' => 50],
                ['label' => 'Balance', 'when' => 'date', 'date' => '2026-06-20', 'percent' => 50],
            ],
            'signatories' => [['name' => 'Jane Customer', 'role' => 'CEO']],
        ]), static::$cachedUser)->execute();

        return $quote->refresh();
    }
}
