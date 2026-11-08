<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Models\DocumentSequence;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\ChecksIssuedNumbersInterface;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfProviderResolver;
use Throwable;

/**
 * Loads a DGII-authorized e-NCF range for one e-CF type. start/end take the full e-NCF (E310000000004) or the
 * bare number (4). The expiry is optional because DGII leaves it out for types 32 and 34.
 */
class ConfigureEcfRangeAction
{
    public const int ENCF_DIGITS = 10;

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly string $type,
        private readonly string $start,
        private readonly string $end,
        private readonly ?string $expiresAt = null,
    ) {
    }

    /**
     * What the provider check found, for the caller to tell the user; null when nothing needs saying.
     */
    public private(set) ?string $notice = null;

    public function execute(): DocumentSequence
    {
        $type = $this->ecfType();
        $start = $this->number($this->start, $type);
        $end = $this->number($this->end, $type);

        return new DocumentNumberAllocatorService()->configureRange(
            appsId: $this->app->getId(),
            companiesId: $this->company->getId(),
            documentType: DocumentTypeEnum::forEcf($type),
            prefix: $type->prefix(),
            rangeStart: $start,
            rangeEnd: $end,
            expiresAt: $this->expiry(),
            padding: self::ENCF_DIGITS,
            firstUnused: $end >= $start ? $this->firstUnusedAtProvider($type, $start, $end) : null,
        );
    }

    /**
     * The range is the DGII fact and is stored as given; only where issuing starts moves past numbers the
     * provider already holds. Numbers are issued in order, so the used ones are a prefix of the range: a galloping
     * search finds its end in ~2·log2(n) lookups. A provider that cannot be asked, or fails, never blocks the load.
     */
    private function firstUnusedAtProvider(EcfTypeEnum $type, int $start, int $end): ?int
    {
        $provider = $this->provider();
        if ($provider === null) {
            return null;
        }

        $isIssued = fn (int $number): bool => $provider->isNumberIssued($type, $this->encf($type, $number));

        try {
            if (! $isIssued($start)) {
                return null;
            }

            $issued = $start;
            $free = $end + 1;
            for ($step = 1; $start + $step <= $end; $step *= 2) {
                if (! $isIssued($start + $step)) {
                    $free = $start + $step;

                    break;
                }

                $issued = $start + $step;
            }

            while ($free - $issued > 1) {
                $middle = intdiv($issued + $free, 2);
                $isIssued($middle) ? $issued = $middle : $free = $middle;
            }
        } catch (Throwable $e) {
            $this->notice = 'Could not check the provider for numbers already issued (' . $e->getMessage() . '); '
                . 'issuing starts at the beginning of the range.';

            return null;
        }

        $this->notice = sprintf(
            '%s to %s are already issued at the e-CF provider (outside Kanvas), so issuing starts at %s.',
            $this->encf($type, $start),
            $this->encf($type, $issued),
            $free > $end ? 'nothing: the whole range is used' : $this->encf($type, $free)
        );

        return $free;
    }

    private function provider(): ?ChecksIssuedNumbersInterface
    {
        if (! $this->app instanceof Apps || ! $this->company instanceof Companies) {
            return null;
        }

        $resolver = new EcfProviderResolver();
        if (! $resolver->issuesEcf($this->company)) {
            return null;
        }

        $provider = $resolver->resolve($this->app, $this->company);

        return $provider instanceof ChecksIssuedNumbersInterface ? $provider : null;
    }

    private function encf(EcfTypeEnum $type, int $number): string
    {
        return $type->prefix() . str_pad((string) $number, self::ENCF_DIGITS, '0', STR_PAD_LEFT);
    }

    private function ecfType(): EcfTypeEnum
    {
        return EcfTypeEnum::parse($this->type)
            ?? throw new ValidationException("Unknown e-CF type '{$this->type}'. Use one of 31, 32, 33, 34, 41, 43, 44, 45, 46, 47.");
    }

    private function number(string $value, EcfTypeEnum $type): int
    {
        $value = strtoupper(trim($value));

        if (str_starts_with($value, 'E')) {
            if (! str_starts_with($value, $type->prefix())) {
                throw new ValidationException("{$value} is not an {$type->prefix()} e-NCF.");
            }

            $value = substr($value, strlen($type->prefix()));
        }

        if ($value === '' || ! ctype_digit($value) || strlen($value) > self::ENCF_DIGITS) {
            throw new ValidationException("'{$value}' is not a valid e-NCF sequence number.");
        }

        return (int) $value;
    }

    private function expiry(): ?Carbon
    {
        $value = Str::trimToNull($this->expiresAt);
        if ($value === null) {
            return null;
        }

        try {
            $expiry = Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            throw new ValidationException("'{$value}' is not a valid expiry date.");
        }

        if ($expiry->lt(Carbon::today())) {
            throw new ValidationException("The range expired on {$expiry->toDateString()}; load the range DGII authorized after it.");
        }

        return $expiry;
    }
}
