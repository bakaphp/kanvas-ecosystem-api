<?php

declare(strict_types=1);

namespace Kanvas\Scribe\BillingSchedules\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\BillingSchedules\Enums\BillingCadenceEnum;
use Kanvas\Scribe\BillingSchedules\Enums\BillingScheduleStatusEnum;
use Kanvas\Scribe\BillingSchedules\Models\BillingSchedule as BillingScheduleModel;
use Kanvas\Scribe\BillingSchedules\Models\BillingScheduleLine as BillingScheduleLineModel;
use Kanvas\Scribe\Quotes\Models\Quote;
use Spatie\LaravelData\Data;

class BillingSchedule extends Data
{
    /**
     * @param list<BillingScheduleLine> $lines
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Organization $customer,
        public readonly string $currency,
        public readonly array $lines,
        public readonly BillingCadenceEnum $cadence,
        public readonly Carbon $start_date,
        public readonly ?Carbon $end_date = null,
        public readonly ?int $anchor_day = null,
        public readonly ?string $name = null,
        public readonly ?int $net_terms_days = null,
        public readonly ?string $notes = null,
        public readonly ?Quote $sourceQuote = null,
        public readonly ?string $source_key = null,
        public readonly BillingScheduleStatusEnum $status = BillingScheduleStatusEnum::ACTIVE,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function assertValid(): void
    {
        if ($this->lines === []) {
            throw new ValidationException('A billing schedule needs at least one line with a description and a unit_price.');
        }

        if ($this->anchor_day !== null && ($this->anchor_day < 1 || $this->anchor_day > 31)) {
            throw new ValidationException('anchor_day must be between 1 and 31.');
        }

        if ($this->end_date !== null && $this->end_date->lt($this->start_date)) {
            throw new ValidationException('end_date cannot be before start_date.');
        }
    }

    /**
     * The first period to bill: the start date, moved forward to the anchor day when one is set and
     * the start date is past it in its month.
     */
    public function firstRunDate(): Carbon
    {
        if ($this->anchor_day === null || $this->cadence === BillingCadenceEnum::ONCE) {
            return $this->start_date->copy();
        }

        $candidate = $this->start_date->copy()->setDay(min($this->anchor_day, $this->start_date->daysInMonth));

        if ($candidate->lt($this->start_date)) {
            $candidate = $this->start_date->copy()->startOfMonth()->addMonthNoOverflow();
            $candidate->setDay(min($this->anchor_day, $candidate->daysInMonth));
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromMultiple(AppInterface $app, CompanyInterface $company, array $data): self
    {
        /** @var Organization $customer */
        $customer = ($data['customer'] ?? null) instanceof Organization
            ? $data['customer']
            : Organization::getByIdFromCompanyApp((int) $data['customer_organization_id'], $company, $app);

        /** @var Quote|null $quote */
        $quote = isset($data['source_quote_id'])
            ? Quote::getByIdFromCompanyApp((int) $data['source_quote_id'], $company, $app)
            : null;

        return new self(
            app: $app,
            company: $company,
            customer: $customer,
            currency: strtoupper((string) ($data['currency'] ?? 'USD')),
            lines: BillingScheduleLine::listFromInput((array) ($data['lines'] ?? [])),
            cadence: BillingCadenceEnum::from((string) ($data['cadence'] ?? BillingCadenceEnum::MONTHLY->value)),
            start_date: Carbon::parse((string) ($data['start_date'] ?? Carbon::today()->toDateString()))->startOfDay(),
            end_date: isset($data['end_date']) ? Carbon::parse((string) $data['end_date'])->startOfDay() : null,
            anchor_day: isset($data['anchor_day']) ? (int) $data['anchor_day'] : null,
            name: Str::trimToNull($data['name'] ?? null),
            net_terms_days: isset($data['net_terms_days']) ? (int) $data['net_terms_days'] : null,
            notes: Str::trimToNull($data['notes'] ?? null),
            sourceQuote: $quote,
            source_key: Str::trimToNull($data['source_key'] ?? null),
            status: isset($data['status'])
                ? BillingScheduleStatusEnum::from((string) $data['status'])
                : BillingScheduleStatusEnum::ACTIVE,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function forUpdate(BillingScheduleModel $schedule, array $data): self
    {
        return new self(
            app: $schedule->app,
            company: $schedule->company,
            customer: $schedule->customer,
            currency: strtoupper((string) ($data['currency'] ?? $schedule->currency)),
            lines: isset($data['lines'])
                ? BillingScheduleLine::listFromInput((array) $data['lines'])
                : $schedule->lines->map(fn (BillingScheduleLineModel $line): BillingScheduleLine => BillingScheduleLine::fromModel($line))->all(),
            cadence: isset($data['cadence']) ? BillingCadenceEnum::from((string) $data['cadence']) : $schedule->cadence,
            start_date: isset($data['start_date'])
                ? Carbon::parse((string) $data['start_date'])->startOfDay()
                : $schedule->start_date,
            end_date: array_key_exists('end_date', $data)
                ? ($data['end_date'] !== null ? Carbon::parse((string) $data['end_date'])->startOfDay() : null)
                : $schedule->end_date,
            anchor_day: isset($data['anchor_day']) ? (int) $data['anchor_day'] : $schedule->anchor_day,
            name: array_key_exists('name', $data) ? Str::trimToNull($data['name']) : $schedule->name,
            net_terms_days: isset($data['net_terms_days']) ? (int) $data['net_terms_days'] : $schedule->net_terms_days,
            notes: array_key_exists('notes', $data) ? Str::trimToNull($data['notes']) : $schedule->notes,
            sourceQuote: $schedule->sourceQuote,
            source_key: $schedule->source_key,
            status: isset($data['status']) ? BillingScheduleStatusEnum::from((string) $data['status']) : $schedule->status,
        );
    }
}
