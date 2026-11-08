<?php

declare(strict_types=1);

namespace Kanvas\Scribe\BillingSchedules\Actions;

use Baka\Support\Money;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\BillingSchedules\DataTransferObject\BillingSchedule as BillingScheduleData;
use Kanvas\Scribe\BillingSchedules\DataTransferObject\BillingScheduleLine as BillingScheduleLineData;
use Kanvas\Scribe\BillingSchedules\Enums\BillingCadenceEnum;
use Kanvas\Scribe\BillingSchedules\Models\BillingSchedule;
use Kanvas\Scribe\PaymentTerms\Models\PaymentTerm;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Sow\DataTransferObject\StatementOfWork;
use Kanvas\Social\Messages\Models\Message;

/**
 * A signed SOW's payment_schedule becomes billing: one `once` schedule per payment line, due today
 * for `on_signature` and on its date for dated lines (plan decision 8: signing is what bills, there
 * is no delivery tracking).
 *
 * Each line bills its share of the quote: the pre-tax amount and the tax split in the same ratio, so
 * the invoices add up to the quote total with the tax where it belongs.
 *
 * Idempotent: the source key is the SOW message id plus the line's position, so accepting the same
 * quote twice returns the schedules the first acceptance made.
 */
class CreateBillingSchedulesFromQuoteAction
{
    public function __construct(
        public readonly Quote $quote,
        public readonly ?UserInterface $user = null,
    ) {
    }

    /**
     * @return list<BillingSchedule>
     */
    public function execute(): array
    {
        /** @var Message|null $sowMessage */
        $sowMessage = $this->quote->sowMessage;

        if ($sowMessage === null || $this->quote->customer_organization_id === null) {
            return [];
        }

        $sow = StatementOfWork::fromMessage($sowMessage);
        $total = (float) $this->quote->total_native;

        if ($sow->paymentSchedule === [] || $total <= 0) {
            return [];
        }

        $customer = Organization::getByIdFromCompanyApp(
            $this->quote->customer_organization_id,
            $this->quote->company,
            $this->quote->app,
        );
        $netTermsDays = $this->netTermsDays($sow);
        $amounts = $sow->paymentAmounts($total);
        $preTax = (float) $this->quote->subtotal_native - (float) $this->quote->discount_native;
        $tax = (float) $this->quote->tax_native;
        $reference = $this->quote->quote_number ?? ('#' . $this->quote->getId());

        $schedules = [];

        foreach ($sow->paymentSchedule as $index => $line) {
            $ratio = $amounts[$index] / $total;
            $start = $line['when'] === StatementOfWork::WHEN_DATE && $line['date'] !== null
                ? Carbon::parse($line['date'])->startOfDay()
                : Carbon::today();

            $schedules[] = new CreateBillingScheduleAction(
                data: new BillingScheduleData(
                    app: $this->quote->app,
                    company: $this->quote->company,
                    customer: $customer,
                    currency: $this->quote->currency,
                    lines: [
                        new BillingScheduleLineData(
                            description: "{$line['label']} — quote {$reference}",
                            quantity: 1.0,
                            unit_price: Money::stored($preTax * $ratio),
                            tax_amount: Money::stored($tax * $ratio),
                        ),
                    ],
                    cadence: BillingCadenceEnum::ONCE,
                    start_date: $start,
                    name: "{$line['label']} (quote {$reference})",
                    net_terms_days: $netTermsDays,
                    notes: $sow->summary,
                    sourceQuote: $this->quote,
                    source_key: "sow:{$sowMessage->getId()}:{$index}",
                ),
                user: $this->user,
            )->execute();
        }

        return $schedules;
    }

    private function netTermsDays(StatementOfWork $sow): ?int
    {
        if ($sow->paymentTermsId === null) {
            return null;
        }

        return PaymentTerm::query()
            ->where('id', $sow->paymentTermsId)
            ->fromApp($this->quote->apps_id)
            ->fromCompany($this->quote->companies_id)
            ->notDeleted()
            ->value('net_days');
    }
}
