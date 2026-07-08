<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventDTO;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventResource;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Support\Setup as EventSetup;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Actions\CreateDealAction;
use Kanvas\Guild\Deals\DataTransferObject\Deal as DealData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Scribe\Bills\Actions\AllocateBillPaymentAction;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Expenses\Actions\ApproveExpenseAction;
use Kanvas\Scribe\Expenses\Actions\CreateExpenseAction;
use Kanvas\Scribe\Expenses\Actions\SubmitExpenseForApprovalAction;
use Kanvas\Scribe\Expenses\DataTransferObject\Expense as ExpenseData;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseLine as ExpenseLineData;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Invoices\Actions\AllocateInvoicePaymentAction;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Quotes\Actions\AcceptQuoteAction;
use Kanvas\Scribe\Quotes\Actions\CreateQuoteAction;
use Kanvas\Scribe\Quotes\Actions\SendQuoteAction;
use Kanvas\Scribe\Quotes\DataTransferObject\Quote as QuoteData;
use Kanvas\Scribe\Quotes\DataTransferObject\QuoteLine as QuoteLineData;
use Kanvas\Scribe\SalesReceipts\Actions\CreateSalesReceiptAction;
use Kanvas\Scribe\SalesReceipts\DataTransferObject\SalesReceipt as SalesReceiptData;
use Kanvas\Scribe\SalesReceipts\DataTransferObject\SalesReceiptLine as SalesReceiptLineData;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem as OrderItemDto;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

/**
 * One-shot demo-data generator for a sales "mission control" showcase. Fills an existing
 * app/company with ~6 months of backdated volume as ONE CONNECTED GRAPH rather than isolated
 * rows — every transactional record hangs off a real account:
 *
 *   Pipeline
 *     └─ Organization (account)
 *          ├─ People (contacts, attached to the org)
 *          ├─ Leads (raised by those contacts)
 *          ├─ Deals (spread across pipeline stages; the "Won" ones drive revenue)
 *          │     └─ Quote (sent → accepted) ─► Invoice (issued, billed to the org) ─► Payment
 *          ├─ Orders (commerce, real product variants as line items)
 *          └─ Bills (org as vendor) ─► received ─► Payment
 *
 * So a dashboard can show "Account X: N contacts, $Y pipeline, $Z invoiced, $W collected" and
 * the numbers tie out across CRM, Commerce and Accounting.
 *
 * Bulk/leaf rows (products, contacts, leads, orders) use factories with a backdated created_at.
 * Deals + every Accounting document go through their real Actions (they carry invariants
 * factories can't satisfy: pipeline placement, GL balancing, fiscal-period posting). Accounting
 * documents post with the clock frozen inside the matching open fiscal period.
 *
 * NOT idempotent — it appends. Re-running adds another batch. Meant to be run once against a
 * throwaway demo tenant.
 */
class SeedDemoDataCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:seed-demo-data
        {app_id : Target app id}
        {company_id : Target company id}
        {--months=6 : How many months back to spread the data}
        {--organizations=40 : Accounts to build — the spine everything links to}
        {--products=150 : Product catalog size (line-item source for orders/quotes/invoices)}
        {--events=40 : Standalone events to create}
        {--expenses=150 : Internal expenses to create + approve (not account-linked)}
        {--fresh : Delete existing tenant data (CRM + Commerce + Accounting + Events) before seeding}
        {--force : Skip the --fresh confirmation prompt (for non-interactive runs)}';

    protected $description = 'Seed ~6 months of fully-linked demo volume (CRM ↔ Commerce ↔ Accounting) for a mission-control demo.';

    private Apps $app;
    private Companies $company;
    private Users $user;
    private int $months;
    private Carbon $referenceNow;
    private Currencies $usd;
    private ?Pipeline $pipeline = null;
    private Collection $openStages;
    private ?PipelineStage $wonStage = null;
    private int $expenseAccountId = 0;
    private ?int $appointmentTypeId = null;
    private ?int $appointmentCategoryId = null;

    /** @var array<string, int> */
    private array $stats = [];

    /** @var array<int, Variants> */
    private array $variants = [];

    public function handle(): int
    {
        $appId = (int) $this->argument('app_id');
        $companyId = (int) $this->argument('company_id');

        /** @var Apps $app */
        $app = Apps::getById($appId);
        $company = Companies::getById($companyId);

        $this->overwriteAppService($app);

        $this->app = $app;
        $this->company = $company;
        $this->user = Users::getById((int) $company->users_id);

        // Event participant creation (and other trait-driven inserts) read companies_id from the
        // authenticated user's current company — establish that context for the CLI run.
        Auth::loginUsingId($this->user->getId());
        $this->months = (int) $this->option('months');
        $this->referenceNow = Carbon::now();

        $this->info("Seeding linked demo data for app #{$app->getId()} '{$app->name}' / company #{$company->getId()} '{$company->name}'");
        $this->line("Window: last {$this->months} months · owner user #{$this->user->getId()}");
        $this->newLine();

        if ($this->option('fresh') && ! $this->wipeDemoData()) {
            return self::FAILURE;
        }

        $this->seedMasterData();
        $this->seedInventoryCatalog((int) $this->option('products'));
        $this->seedAccounts((int) $this->option('organizations'));
        $this->seedEvents((int) $this->option('events'));
        $this->seedExpenses((int) $this->option('expenses'));

        $this->printSummary();

        return self::SUCCESS;
    }

    private function seedMasterData(): void
    {
        $this->line('· Master data (events + chart of accounts + fiscal periods + pipeline)…');

        new EventSetup($this->app, $this->user, $this->company)->run();
        new ChartOfAccountsSeederService()->seedUsDefault($this->app->getId(), $this->company->getId());
        $this->ensureFiscalPeriods();

        $this->usd = Currencies::where('code', 'USD')->firstOrFail();
        $this->expenseAccountId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);

        $this->pipeline = Pipeline::query()
            ->where('companies_id', $this->company->getId())
            ->where('is_default', 1)
            ->first()
            ?? Pipeline::query()->where('companies_id', $this->company->getId())->first();

        $stages = $this->pipeline?->stages()->get() ?? new Collection();
        $this->wonStage = $stages->firstWhere('name', 'Won') ?? $stages->last();
        $this->openStages = $this->wonStage !== null
            ? $stages->reject(fn (PipelineStage $s): bool => $s->getId() === $this->wonStage->getId())->values()
            : $stages->values();

        if ($this->pipeline === null) {
            $this->warn('  No pipeline found — deals will be created without a stage (no funnel view).');
        }

        // The STANDARD event setup creates an "Appointment" type + category; fall back to the
        // first available if a tenant customized its catalog.
        $this->appointmentTypeId = EventType::fromApp($this->app)->fromCompany($this->company)->where('name', 'Appointment')->first()?->getId()
            ?? EventType::fromApp($this->app)->fromCompany($this->company)->first()?->getId();
        $this->appointmentCategoryId = EventCategory::fromApp($this->app)->fromCompany($this->company)->where('name', 'Appointment')->first()?->getId()
            ?? EventCategory::fromApp($this->app)->fromCompany($this->company)->first()?->getId();
    }

    /**
     * One OPEN monthly fiscal period per month in the window, so backdated documents can post.
     */
    private function ensureFiscalPeriods(): void
    {
        for ($i = 0; $i <= $this->months; $i++) {
            $cursor = $this->referenceNow->copy()->subMonthsNoOverflow($i);
            $start = $cursor->copy()->startOfMonth()->toDateString();
            $end = $cursor->copy()->endOfMonth()->toDateString();

            $exists = FiscalPeriod::query()
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->company->getId())
                ->whereDate('period_start', $start)
                ->whereDate('period_end', $end)
                ->exists();

            if ($exists) {
                continue;
            }

            FiscalPeriod::create([
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'period_start' => $start,
                'period_end' => $end,
                'status' => FiscalPeriodStatusEnum::OPEN,
            ]);
        }
    }

    private function seedInventoryCatalog(int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $bar = $this->progress('Inventory catalog (products + variants)', $count);

        for ($i = 0; $i < $count; $i++) {
            $date = $this->randomDate();
            /** @var Products $product */
            $product = Products::factory()
                ->withAppId($this->app->getId())
                ->withCompanyId($this->company->getId())
                ->withUserId($this->user->getId())
                ->create([
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            $this->bump('products');

            $variant = $product->variants()->first();
            if ($variant !== null) {
                $variant->setRelation('product', $product);
                $this->variants[] = $variant;
                $this->bump('variants');
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function seedAccounts(int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $bar = $this->progress('Accounts (org → people → leads → deals → cash)', $count);

        for ($i = 0; $i < $count; $i++) {
            try {
                $this->buildAccountCluster();
            } catch (Throwable) {
                $this->bump('accounts_failed');
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Build one fully-linked account: the organization, its contacts, leads raised by them,
     * deals in the pipeline, and — for won deals — the quote → invoice → payment chain, plus
     * a few orders and vendor bills. Every child points back at the same organization/people.
     */
    private function buildAccountCluster(): void
    {
        $base = $this->randomDate();

        $org = Organization::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'name' => fake()->unique()->company(),
            'email' => fake()->companyEmail(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'zip' => fake()->postcode(),
            'total_employees' => random_int(5, 500),
            'created_at' => $base,
            'updated_at' => $base,
        ]);
        $this->bump('organizations');

        $people = $this->seedContactsForOrg($org, random_int(2, 6), $base);

        foreach ($people as $person) {
            // ~half the contacts came in as a lead first; most of those get a sales appointment
            // tied to that lead.
            if (random_int(1, 10) <= 5) {
                $lead = $this->seedLeadForContact($person, $base);

                if (random_int(1, 10) <= 6) {
                    $this->seedAppointment($person, $base, $lead, 'lead_appointments');
                }
            }
        }

        $dealCount = random_int(1, 4);
        for ($d = 0; $d < $dealCount; $d++) {
            $this->seedDealForOrg($org, $people->random(), $base);
        }

        $orderCount = random_int(0, 3);
        for ($o = 0; $o < $orderCount; $o++) {
            $this->seedOrderForContact($org, $people->random(), $base);
        }

        $billCount = random_int(0, 2);
        for ($b = 0; $b < $billCount; $b++) {
            $this->seedBillForVendor($org, $base);
        }

        if (random_int(1, 10) <= 4) {
            $this->seedSalesReceiptForOrg($org, $base);
        }
    }

    /**
     * @return Collection<int, People>
     */
    private function seedContactsForOrg(Organization $org, int $count, Carbon $base): Collection
    {
        $people = new Collection();

        for ($i = 0; $i < $count; $i++) {
            $date = $this->afterBase($base, 10);
            /** @var People $person */
            $person = People::factory()
                ->withAppId($this->app->getId())
                ->withCompanyId($this->company->getId())
                ->withUserId($this->user->getId())
                ->withContacts()
                ->create([
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            $person->organizations()->syncWithoutDetaching([
                $org->getId() => ['created_at' => $date],
            ]);
            $people->push($person);
            $this->bump('people');
        }

        return $people;
    }

    private function seedLeadForContact(People $person, Carbon $base): Lead
    {
        $date = $this->afterBase($base, 5);
        /** @var Lead $lead */
        $lead = Lead::factory()
            ->withAppId($this->app->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->user->getId())
            ->withPeopleId($person->getId())
            ->create([
                'firstname' => $person->firstname,
                'lastname' => $person->lastname,
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        $this->bump('leads');

        return $lead;
    }

    /**
     * A scheduled Appointment event: the contact is the participant, and the event is tied back to
     * its Lead/Deal through the event_resources pivot (so $lead->events / $deal->events resolve).
     * The pivot row is written directly with the resource's morph class — the DTO's slug path only
     * maps a hardcoded set (no 'deal' entry), so this keeps lead + deal uniform. Slot is in the
     * future because a pass can't be issued for an already-finished event.
     */
    private function seedAppointment(People $person, Carbon $base, Model $resource, string $statKey): void
    {
        if ($this->appointmentTypeId === null || $this->appointmentCategoryId === null) {
            return;
        }

        $email = $person->contacts()->where('contacts_types_id', 1)->value('value');
        if ($email === null) {
            return;
        }

        $slot = $this->referenceNow->copy()
            ->addDays(random_int(1, 45))
            ->setTime(random_int(8, 16), random_int(0, 1) === 0 ? 0 : 30);

        try {
            $event = new CreateEventAction(
                EventDTO::fromMultiple($this->app, $this->user, $this->company, [
                    'name' => "Appointment — {$person->firstname} {$person->lastname} " . fake()->unique()->uuid(),
                    'description' => 'Sales appointment',
                    'category_id' => $this->appointmentCategoryId,
                    'type_id' => $this->appointmentTypeId,
                    'dates' => [[
                        'date' => $slot->toDateString(),
                        'start_time' => $slot->format('H:i'),
                        'end_time' => $slot->copy()->addHour()->format('H:i'),
                    ]],
                    'participants' => [[
                        'firstname' => $person->firstname,
                        'lastname' => $person->lastname,
                        'contacts' => [['contacts_types_id' => 1, 'value' => $email]],
                    ]],
                ]),
            )->disableWorkflow()->execute();

            EventResource::create([
                'apps_id' => $event->apps_id,
                'companies_id' => $event->companies_id,
                'event_id' => $event->getId(),
                'resources_id' => $resource->getId(),
                'resources_type' => $resource->getMorphClass(),
            ]);

            $event->forceFill(['created_at' => $base, 'updated_at' => $base])->saveQuietly();
            $this->bump($statKey);
        } catch (Throwable) {
            $this->bump($statKey . '_failed');
        }
    }

    private function seedDealForOrg(Organization $org, People $person, Carbon $base): void
    {
        $date = $this->afterBase($base, 20);
        $isWon = random_int(1, 100) <= 30;

        $stage = $isWon
            ? $this->wonStage
            : ($this->openStages->isNotEmpty() ? $this->openStages->random() : null);

        try {
            $deal = new CreateDealAction(
                new DealData(
                    app: $this->app,
                    company: $this->company,
                    user: $this->user,
                    title: fake()->catchPhrase() . ' — ' . $org->name,
                    description: fake()->sentence(),
                    people: $person,
                    organization: $org,
                    pipeline: $this->pipeline,
                    pipelineStage: $stage,
                ),
                false,
            )->execute();

            $deal->forceFill(['created_at' => $date, 'updated_at' => $date])->saveQuietly();
            $this->bump($isWon ? 'deals_won' : 'deals_open');
        } catch (Throwable) {
            $this->bump('deals_failed');

            return;
        }

        // A few deals carry their own appointment (a scheduled meeting on the opportunity).
        if (random_int(1, 10) <= 3) {
            $this->seedAppointment($person, $date, $deal, 'deal_appointments');
        }

        if ($isWon) {
            $this->seedWonDealRevenue($org, $date);
        }
    }

    /**
     * A won deal produces an accepted quote, then an invoice billed to the same org (linked via
     * quote_id), then — most of the time — a payment that settles the invoice.
     */
    private function seedWonDealRevenue(Organization $org, Carbon $dealDate): void
    {
        $lines = $this->pickProducts(random_int(1, 3));
        $subtotal = array_sum(array_map(fn (array $l): float => $l['price'] * $l['qty'], $lines));
        $tax = round($subtotal * 0.07, 2);

        $quoteDate = $this->afterBase($dealDate, 7);
        $quoteId = $this->tryDoc('quotes', $quoteDate, function () use ($org, $lines, $quoteDate): ?int {
            $quote = new CreateQuoteAction(
                new QuoteData(
                    app: $this->app,
                    company: $this->company,
                    billable: $org,
                    lines: new DataCollection(QuoteLineData::class, array_map(
                        fn (array $l): QuoteLineData => new QuoteLineData(
                            description: $l['name'],
                            quantity: (float) $l['qty'],
                            unit_price_native: $l['price'],
                        ),
                        $lines,
                    )),
                    currency: 'USD',
                    fx_rate_to_base: 1.0,
                    issued_date: $quoteDate,
                    valid_until: $quoteDate->copy()->addDays(30),
                ),
                $this->user,
            )->execute();

            $quote = new SendQuoteAction(quote: $quote, billable: $org, user: $this->user)->execute();
            new AcceptQuoteAction(quote: $quote, user: $this->user)->execute();

            return (int) $quote->getId();
        });

        $invoiceDate = $this->afterBase($quoteDate ?? $dealDate, 10);
        $invoice = $this->tryDoc('invoices', $invoiceDate, function () use ($org, $lines, $subtotal, $tax, $invoiceDate, $quoteId) {
            $draft = new CreateInvoiceAction(
                data: new InvoiceData(
                    app: $this->app,
                    company: $this->company,
                    billable: $org,
                    lines: new DataCollection(InvoiceLineData::class, array_map(
                        fn (array $l): InvoiceLineData => new InvoiceLineData(
                            description: $l['name'],
                            quantity: $l['qty'],
                            unit_price_native: $l['price'],
                            tax_amount_native: round($l['price'] * $l['qty'] * 0.07, 2),
                        ),
                        $lines,
                    )),
                    currency: 'USD',
                    fx_rate_to_base: 1.0,
                    issued_date: $invoiceDate,
                    due_date: $invoiceDate->copy()->addDays(30),
                    quote_id: $quoteId,
                ),
                user: $this->user,
            )->execute();

            return new IssueInvoiceAction(invoice: $draft, billable: $org, user: $this->user)->execute();
        });

        // ~70% of issued invoices get collected within the window.
        if ($invoice !== null && random_int(1, 10) <= 7) {
            $payDate = $this->afterBase($invoiceDate, 25);
            $this->tryDoc('invoice_payments', $payDate, fn () => new AllocateInvoicePaymentAction(
                invoice: $invoice,
                amountNative: $subtotal + $tax,
                method: PaymentMethodEnum::ACH,
                user: $this->user,
            )->execute());
        }
    }

    private function seedOrderForContact(Organization $org, People $person, Carbon $base): void
    {
        if ($this->variants === []) {
            return;
        }

        $date = $this->afterBase($base, 20);

        try {
            /** @var Order $order */
            $order = Order::factory()
                ->withAppId($this->app->getId())
                ->withCompanyId($this->company->getId())
                ->withUserId($this->user->getId())
                ->withPeopleId($person->getId())
                ->state(random_int(1, 10) <= 7 ? ['status' => 'completed', 'fulfillment_status' => 'fulfilled'] : [])
                ->create([
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);

            foreach ($this->pickVariants(random_int(1, 3)) as $variant) {
                $order->addItem(new OrderItemDto(
                    app: $this->app,
                    variant: $variant,
                    name: $variant->name,
                    sku: (string) $variant->sku,
                    quantity: random_int(1, 4),
                    price: (float) random_int(20, 600),
                    tax: 0.0,
                    discount: 0.0,
                    currency: $this->usd,
                ));
            }
            $this->bump('orders');
        } catch (Throwable) {
            $this->bump('orders_failed');
        }
    }

    private function seedBillForVendor(Organization $vendor, Carbon $base): void
    {
        $lines = $this->pickProducts(random_int(1, 2));
        $amount = array_sum(array_map(fn (array $l): float => $l['price'] * $l['qty'], $lines));
        $billDate = $this->afterBase($base, 20);

        $bill = $this->tryDoc('bills', $billDate, function () use ($vendor, $lines, $billDate) {
            $bill = new CreateBillAction(
                new BillData(
                    app: $this->app,
                    company: $this->company,
                    vendor: $vendor,
                    lines: new DataCollection(BillLineData::class, array_map(
                        fn (array $l): BillLineData => new BillLineData(
                            description: $l['name'],
                            quantity: (float) $l['qty'],
                            unit_price_native: $l['price'],
                            expense_account_id: $this->expenseAccountId,
                        ),
                        $lines,
                    )),
                    currency: 'USD',
                    fx_rate_to_base: 1.0,
                    bill_date: $billDate,
                    due_date: $billDate->copy()->addDays(30),
                ),
                $this->user,
            )->execute();

            return new ReceiveBillAction(bill: $bill, vendor: $vendor, user: $this->user)->execute();
        });

        // ~60% of received bills get paid.
        if ($bill !== null && random_int(1, 10) <= 6) {
            $payDate = $this->afterBase($billDate, 25);
            $this->tryDoc('bill_payments', $payDate, fn () => new AllocateBillPaymentAction(
                bill: $bill,
                amountNative: (float) $amount,
                method: PaymentMethodEnum::ACH,
                user: $this->user,
            )->execute());
        }
    }

    private function seedSalesReceiptForOrg(Organization $org, Carbon $base): void
    {
        $lines = $this->pickProducts(random_int(1, 2));
        $date = $this->afterBase($base, 20);

        $this->tryDoc('sales_receipts', $date, fn () => new CreateSalesReceiptAction(
            data: new SalesReceiptData(
                app: $this->app,
                company: $this->company,
                billable: $org,
                lines: new DataCollection(SalesReceiptLineData::class, array_map(
                    fn (array $l): SalesReceiptLineData => new SalesReceiptLineData(
                        description: $l['name'],
                        quantity: (float) $l['qty'],
                        unit_price_native: $l['price'],
                    ),
                    $lines,
                )),
                receipt_date: $date,
                currency: 'USD',
                fx_rate_to_base: 1.0,
            ),
            user: $this->user,
        )->execute());
    }

    private function seedEvents(int $count): void
    {
        $categoryId = EventCategory::fromApp($this->app)->fromCompany($this->company)->first()?->getId();
        $typeId = EventType::fromApp($this->app)->fromCompany($this->company)->first()?->getId();

        if ($count <= 0 || $categoryId === null || $typeId === null) {
            return;
        }

        $bar = $this->progress('Events', $count);

        for ($i = 0; $i < $count; $i++) {
            $date = $this->randomDate();
            try {
                $event = new CreateEventAction(
                    EventDTO::fromMultiple($this->app, $this->user, $this->company, [
                        'name' => fake()->catchPhrase() . ' ' . fake()->unique()->uuid(),
                        'description' => fake()->sentence(),
                        'category_id' => $categoryId,
                        'type_id' => $typeId,
                        'dates' => [],
                    ]),
                )->disableWorkflow()->execute();

                $event->forceFill(['created_at' => $date, 'updated_at' => $date])->saveQuietly();
                $this->bump('events');
            } catch (Throwable) {
                $this->bump('events_failed');
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function seedExpenses(int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $bar = $this->progress('Expenses (internal, create + approve)', $count);

        try {
            for ($i = 0; $i < $count; $i++) {
                $date = $this->randomDate();
                Carbon::setTestNow($date->copy()->setTime(12, 0));

                try {
                    $expense = new CreateExpenseAction(
                        data: new ExpenseData(
                            app: $this->app,
                            company: $this->company,
                            lines: new DataCollection(ExpenseLineData::class, [
                                new ExpenseLineData(
                                    description: fake()->sentence(3),
                                    amount_native: (float) random_int(20, 1500),
                                    expense_account_id: $this->expenseAccountId,
                                ),
                            ]),
                            expense_date: $date,
                            currency: 'USD',
                            fx_rate_to_base: 1.0,
                            paid_by: ExpensePaidByEnum::COMPANY_CARD,
                        ),
                        user: $this->user,
                    )->execute();

                    $expense = new SubmitExpenseForApprovalAction(expense: $expense, user: $this->user)->execute();
                    new ApproveExpenseAction(expense: $expense, approver: $this->user)->execute();
                    $this->bump('expenses');
                } catch (Throwable) {
                    $this->bump('expenses_failed');
                }

                $bar->advance();
            }
        } finally {
            Carbon::setTestNow();
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Run a Scribe-document creation with the clock frozen inside the matching open fiscal period,
     * counting success/failure. Returns whatever the callback returns, or null on failure.
     */
    private function tryDoc(string $type, Carbon $date, callable $fn): mixed
    {
        Carbon::setTestNow($date->copy()->setTime(12, 0));

        try {
            $result = $fn();
            $this->bump($type);

            return $result;
        } catch (Throwable) {
            $this->bump($type . '_failed');

            return null;
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array<int, array{name: string, price: float, qty: int}>
     */
    private function pickProducts(int $count): array
    {
        $lines = [];
        foreach ($this->pickVariants($count) as $variant) {
            $lines[] = [
                'name' => (string) ($variant->product->name ?? $variant->name),
                'price' => (float) random_int(50, 2500),
                'qty' => random_int(1, 4),
            ];
        }

        // Fall back to a generic line when there is no product catalog.
        if ($lines === []) {
            $lines[] = ['name' => fake()->bs(), 'price' => (float) random_int(50, 2500), 'qty' => random_int(1, 4)];
        }

        return $lines;
    }

    /**
     * @return array<int, Variants>
     */
    private function pickVariants(int $count): array
    {
        if ($this->variants === []) {
            return [];
        }

        $keys = (array) array_rand($this->variants, min($count, count($this->variants)));

        return array_map(fn (int $k): Variants => $this->variants[$k], $keys);
    }

    private function accountIdBySubType(AccountSubTypeEnum $subType): int
    {
        $row = Account::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', $subType->value)
            ->first();

        return (int) ($row?->id ?? 0);
    }

    /**
     * A backdated timestamp offset forward from $base but never past "now", so a cluster's
     * children (contacts → leads → deals → invoices) stay in chronological order.
     */
    private function afterBase(Carbon $base, int $maxDays): Carbon
    {
        $date = $base->copy()->addDays(random_int(0, $maxDays))->addHours(random_int(0, 23));

        return $date->greaterThan($this->referenceNow) ? $this->referenceNow->copy() : $date;
    }

    private function randomDate(): Carbon
    {
        return $this->referenceNow->copy()
            ->subDays(random_int(0, $this->months * 30))
            ->subHours(random_int(0, 23))
            ->subMinutes(random_int(0, 59));
    }

    /**
     * Delete this tenant's transactional data (CRM + Commerce + Events + Accounting) before a fresh
     * seed. Every delete is scoped to companies_id — NEVER app-only — so it can't touch other
     * tenants sharing the app. Children are removed before parents, and FK checks are disabled per
     * session as a backstop. Master data (pipeline, chart of accounts, fiscal periods, event
     * catalog) is preserved so the re-seed works without duplicating config. Returns false if the
     * operator declines the confirmation.
     */
    private function wipeDemoData(): bool
    {
        $appId = $this->app->getId();
        $companyId = $this->company->getId();

        if ($companyId <= 0) {
            $this->error('Refusing to wipe: company id must be > 0 (never wipe global-scoped rows).');

            return false;
        }

        $preview = [
            ['crm', 'organizations'], ['crm', 'peoples'], ['crm', 'leads'], ['crm', 'deals'],
            ['commerce', 'orders'], ['event', 'events'],
            ['accounting', 'invoices'], ['accounting', 'quotes'], ['accounting', 'bills'],
            ['accounting', 'expenses'], ['accounting', 'sales_receipts'], ['accounting', 'payments'],
        ];

        $this->warn("--fresh: will DELETE existing data for app {$appId} / company {$companyId}:");
        $total = 0;
        foreach ($preview as [$conn, $table]) {
            $count = DB::connection($conn)->table($table)
                ->where('apps_id', $appId)
                ->where('companies_id', $companyId)
                ->count();
            $total += $count;
            $this->line(sprintf('  %-11s %-16s %d', $conn, $table, $count));
        }

        if ($total === 0) {
            $this->info('  Nothing to wipe.');
            $this->newLine();

            return true;
        }

        if (! $this->option('force') && ! $this->confirm("Delete the above for company {$companyId}? This cannot be undone.")) {
            $this->warn('Aborted — nothing deleted.');

            return false;
        }

        $connections = ['crm', 'commerce', 'event', 'accounting'];
        foreach ($connections as $conn) {
            DB::connection($conn)->statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            $this->deleteByParent('crm', 'peoples_contacts', 'peoples_id', 'peoples');
            $this->deleteByParent('crm', 'peoples_address', 'peoples_id', 'peoples');
            $this->deleteByParent('crm', 'organizations_peoples', 'organizations_id', 'organizations');
            $this->deleteDirect('crm', 'deals');
            $this->deleteDirect('crm', 'leads');
            $this->deleteDirect('crm', 'peoples');
            $this->deleteDirect('crm', 'organizations');

            $this->deleteByParent('commerce', 'order_items', 'order_id', 'orders');
            $this->deleteDirect('commerce', 'orders');

            $this->deleteByParent('event', 'event_version_participants', 'event_version_id', 'event_versions');
            $this->deleteByParent('event', 'event_version_dates', 'event_version_id', 'event_versions');
            $this->deleteDirect('event', 'participant_passes');
            $this->deleteDirect('event', 'participants');
            $this->deleteDirect('event', 'event_resources');
            $this->deleteDirect('event', 'event_versions');
            $this->deleteDirect('event', 'events');

            $this->deleteByParent('accounting', 'journal_entry_lines', 'journal_entry_id', 'journal_entries');
            $this->deleteByParent('accounting', 'invoice_lines', 'invoice_id', 'invoices');
            $this->deleteByParent('accounting', 'invoice_tax_lines', 'invoice_id', 'invoices');
            $this->deleteByParent('accounting', 'quote_lines', 'quote_id', 'quotes');
            $this->deleteByParent('accounting', 'bill_lines', 'bill_id', 'bills');
            $this->deleteByParent('accounting', 'expense_lines', 'expense_id', 'expenses');
            $this->deleteByParent('accounting', 'sales_receipt_lines', 'sales_receipt_id', 'sales_receipts');
            $this->deleteDirect('accounting', 'payments');
            $this->deleteDirect('accounting', 'journal_entries');
            $this->deleteDirect('accounting', 'invoices');
            $this->deleteDirect('accounting', 'quotes');
            $this->deleteDirect('accounting', 'bills');
            $this->deleteDirect('accounting', 'expenses');
            $this->deleteDirect('accounting', 'sales_receipts');
        } finally {
            foreach ($connections as $conn) {
                DB::connection($conn)->statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        $this->info('  Wipe complete.');
        $this->newLine();

        return true;
    }

    private function deleteDirect(string $conn, string $table): void
    {
        DB::connection($conn)->table($table)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->delete();
    }

    /**
     * Delete child rows whose parent belongs to this tenant. The child tables carry no companies_id,
     * so scope through the parent's apps_id + companies_id via a same-connection subquery.
     */
    private function deleteByParent(string $conn, string $child, string $fk, string $parent): void
    {
        DB::connection($conn)->table($child)
            ->whereIn($fk, function ($query) use ($parent): void {
                $query->select('id')
                    ->from($parent)
                    ->where('apps_id', $this->app->getId())
                    ->where('companies_id', $this->company->getId());
            })
            ->delete();
    }

    private function bump(string $key): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;
    }

    private function progress(string $label, int $count): ProgressBar
    {
        $this->line("· {$label} ({$count})…");

        return $this->output->createProgressBar($count);
    }

    private function printSummary(): void
    {
        $this->newLine();
        $this->info('Done. Linked demo data seeded:');

        ksort($this->stats);
        foreach ($this->stats as $key => $value) {
            $line = "  {$key}: {$value}";
            str_contains($key, '_failed') ? $this->warn($line) : $this->line($line);
        }
    }
}
