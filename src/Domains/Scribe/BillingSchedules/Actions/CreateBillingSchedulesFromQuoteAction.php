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
 * Each payment bills its share of every quote line (price, discount and tax in the same ratio, rate and quantity
 * kept), so each invoice shows the quote's own lines; the last payment takes each line's rounding remainder, so the
 * invoices add up to the quote line by line.
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
        $lineShares = $this->lineShares(array_map(fn (float $amount): float => $amount / $total, $amounts));
        $reference = $this->quote->quote_number ?? ('#' . $this->quote->getId());

        $schedules = [];

        foreach ($sow->paymentSchedule as $index => $line) {
            $start = $line['when'] === StatementOfWork::WHEN_DATE && $line['date'] !== null
                ? Carbon::parse($line['date'])->startOfDay()
                : Carbon::today();

            $schedules[] = new CreateBillingScheduleAction(
                data: new BillingScheduleData(
                    app: $this->quote->app,
                    company: $this->quote->company,
                    customer: $customer,
                    currency: $this->quote->currency,
                    lines: $lineShares[$index],
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
            return PaymentTerm::defaultFor($this->quote->app, $this->quote->company)?->net_days;
        }

        return PaymentTerm::query()
            ->where('id', $sow->paymentTermsId)
            ->fromApp($this->quote->apps_id)
            ->fromCompany($this->quote->companies_id)
            ->notDeleted()
            ->value('net_days');
    }

    /**
     * Per payment, every quote line at that payment's share; the last payment gets what the earlier ones left of
     * each amount, so no line drifts by a rounding cent.
     *
     * @param list<float> $ratios
     * @return list<list<BillingScheduleLineData>>
     */
    private function lineShares(array $ratios): array
    {
        $last = array_key_last($ratios);
        $shares = array_fill(0, count($ratios), []);

        foreach ($this->quote->lines as $quoteLine) {
            $billed = ['unit_price' => Money::of(0), 'discount' => Money::of(0), 'tax' => Money::of(0)];

            foreach ($ratios as $index => $ratio) {
                $part = fn (string $key, mixed $amount): float => $index === $last
                    ? Money::stored(Money::of($amount ?? 0)->minus($billed[$key]))
                    : Money::stored(Money::of($amount ?? 0)->multipliedBy(Money::of($ratio)));

                $unitPrice = $part('unit_price', $quoteLine->unit_price_native);
                $discount = $part('discount', $quoteLine->discount_amount_native);
                $tax = $part('tax', $quoteLine->tax_amount_native);
                $billed = [
                    'unit_price' => $billed['unit_price']->plus(Money::of($unitPrice)),
                    'discount' => $billed['discount']->plus(Money::of($discount)),
                    'tax' => $billed['tax']->plus(Money::of($tax)),
                ];

                $shares[$index][] = new BillingScheduleLineData(
                    description: (string) $quoteLine->description,
                    quantity: (float) $quoteLine->quantity,
                    unit_price: $unitPrice,
                    tax_amount: $tax,
                    tax_rate: $quoteLine->tax_rate !== null ? (float) $quoteLine->tax_rate : null,
                    item_id: $quoteLine->item_id,
                    discount_amount: $discount,
                );
            }
        }

        return $shares;
    }
}
