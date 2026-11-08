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
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfProviderResolver;
use Throwable;

/**
 * Loads a range the company's tax authority authorized for one document type, for a regime that numbers from
 * authorized ranges (DR: e-NCF). start/end take the full number (E310000000004) or the bare sequence (4). The
 * expiry is optional because some types have none (DR: 32 and 34).
 */
class ConfigureEcfRangeAction
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly string $type,
        private readonly string $start,
        private readonly string $end,
        private readonly ?string $expiresAt = null,
        private readonly ?string $country = null,
    ) {
    }

    /**
     * What the provider check found, for the caller to tell the user; null when nothing needs saying.
     */
    public private(set) ?string $notice = null;

    public function execute(): DocumentSequence
    {
        $regime = $this->regime();
        $type = $this->documentType($regime);
        $start = $this->number($this->start, $type);
        $end = $this->number($this->end, $type);

        return new DocumentNumberAllocatorService()->configureRange(
            appsId: $this->app->getId(),
            companiesId: $this->company->getId(),
            documentType: DocumentTypeEnum::forFiscal($type),
            prefix: $type->series(),
            rangeStart: $start,
            rangeEnd: $end,
            expiresAt: $this->expiry(),
            padding: $type->sequenceDigits(),
            firstUnused: $end >= $start ? $this->firstUnusedAtProvider($type, $start, $end) : null,
            country: $regime->country(),
        );
    }

    /**
     * The range is the authority's fact and is stored as given; only where issuing starts moves past numbers the
     * provider already holds. Numbers are issued in order, so the used ones are a prefix of the range: a galloping
     * search finds its end in ~2·log2(n) lookups. A provider that cannot be asked, or fails, never blocks the load.
     */
    private function firstUnusedAtProvider(FiscalDocumentTypeInterface $type, int $start, int $end): ?int
    {
        $provider = $this->provider();
        if ($provider === null) {
            return null;
        }

        $isIssued = fn (int $number): bool => $provider->isNumberIssued($type, $this->format($type, $number));

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
            '%s to %s are already issued at the provider (outside Kanvas), so issuing starts at %s.',
            $this->format($type, $start),
            $this->format($type, $issued),
            $free > $end ? 'nothing: the whole range is used' : $this->format($type, $free)
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

    private function format(FiscalDocumentTypeInterface $type, int $number): string
    {
        return $type->series() . str_pad((string) $number, $type->sequenceDigits(), '0', STR_PAD_LEFT);
    }

    /**
     * An explicit country lets support load a range before the provider is connected or the company's country set.
     */
    private function regime(): FiscalRegimeInterface
    {
        if (Str::trimToNull($this->country) !== null) {
            return FiscalRegimes::for($this->country);
        }

        $regime = $this->company instanceof Companies ? FiscalRegimes::forCompany($this->company) : null;

        return $regime ?? throw new ValidationException(
            'This company has no electronic-invoicing regime yet: connect its provider (or set its country) before loading a range.'
        );
    }

    private function documentType(FiscalRegimeInterface $regime): FiscalDocumentTypeInterface
    {
        $codes = implode(', ', array_map(fn (FiscalDocumentTypeInterface $type): string => $type->code(), $regime->documentTypes()));

        return $regime->documentType($this->type)
            ?? throw new ValidationException("Unknown document type '{$this->type}'. Use one of {$codes}.");
    }

    private function number(string $value, FiscalDocumentTypeInterface $type): int
    {
        $value = strtoupper(trim($value));
        $series = strtoupper($type->series());

        if (! ctype_digit($value)) {
            if (! str_starts_with($value, $series)) {
                throw new ValidationException("{$value} is not in the {$type->series()} series.");
            }

            $value = substr($value, strlen($series));
        }

        if ($value === '' || ! ctype_digit($value) || strlen($value) > $type->sequenceDigits()) {
            throw new ValidationException("'{$value}' is not a valid sequence number.");
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
            throw new ValidationException("The range expired on {$expiry->toDateString()}; load the range the tax authority authorized after it.");
        }

        return $expiry;
    }
}
