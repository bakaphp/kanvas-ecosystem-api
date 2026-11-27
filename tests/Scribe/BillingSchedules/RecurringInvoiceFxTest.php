<?php

declare(strict_types=1);

namespace Tests\Scribe\BillingSchedules;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\BillingSchedules\Actions\CreateBillingScheduleAction;
use Kanvas\Scribe\BillingSchedules\Actions\RunBillingScheduleAction;
use Kanvas\Scribe\BillingSchedules\DataTransferObject\BillingSchedule as BillingScheduleData;
use Kanvas\Scribe\BillingSchedules\Models\BillingSchedule;
use Kanvas\Scribe\FxRates\Models\FxRate;
use Tests\Scribe\ScribeTestCase;

class RecurringInvoiceFxTest extends ScribeTestCase
{
    public function test_a_recurring_invoice_in_another_currency_is_valued_at_the_published_rate(): void
    {
        $this->forgetEuroRates();
        FxRate::create([
            'apps_id' => $this->kanvasApp->getId(),
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'rate' => 2,
            'rate_date' => Carbon::today(),
            'source' => 'test',
        ]);

        $invoice = new RunBillingScheduleAction($this->euroSchedule(), Carbon::today())->execute();

        $this->assertSame('EUR', $invoice->currency);
        $this->assertSame(100.0, (float) $invoice->total_native);
        $this->assertSame(200.0, (float) $invoice->total_base);
    }

    public function test_a_recurring_invoice_is_not_booked_at_parity_when_no_rate_is_published(): void
    {
        $this->forgetEuroRates();
        $schedule = $this->euroSchedule();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No EUR/USD exchange rate is published');

        new RunBillingScheduleAction($schedule, Carbon::today())->execute();
    }

    private function euroSchedule(): BillingSchedule
    {
        return new CreateBillingScheduleAction(
            BillingScheduleData::fromMultiple($this->kanvasApp, $this->company, [
                'customer_organization_id' => $this->seedTestOrganization('Euro Retainer Customer')->getId(),
                'currency' => 'EUR',
                'start_date' => Carbon::today()->toDateString(),
                'lines' => [['description' => 'Euro service', 'quantity' => 1, 'unit_price' => 100]],
            ]),
            static::$cachedUser
        )->execute();
    }

    private function forgetEuroRates(): void
    {
        FxRate::query()
            ->whereIn('base_currency', ['EUR', 'USD'])
            ->whereIn('quote_currency', ['EUR', 'USD'])
            ->delete();
    }
}
