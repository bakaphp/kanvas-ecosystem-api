<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kanvas\Insurance\Contracts\CanonicalQuoteProviderInterface;
use Kanvas\Insurance\Contracts\QuoteTaskRunner;
use Kanvas\Insurance\Contracts\VehicleReferenceQuoteProviderInterface;
use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;
use Kanvas\Insurance\DataTransferObject\InsuranceSearchEntry;
use Kanvas\Insurance\DataTransferObject\InsuranceSearchFailure;
use Kanvas\Insurance\DataTransferObject\InsuranceSearchResult;
use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Insurance\Enums\InsuranceCatalogStatusEnum;
use Kanvas\Insurance\Enums\InsuranceSearchFailureReasonEnum;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Models\Insurer;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Laravel\Octane\Exceptions\TaskTimeoutException;
use Throwable;

class InsuranceSearchService
{
    public function __construct(
        private readonly QuoteTaskRunner $runner,
        private readonly InsuranceVehicleModelResolver $vehicleModelResolver,
        private readonly InsuranceComparatorService $comparator,
    ) {
    }

    public function execute(CanonicalQuoteInput $input): InsuranceSearchResult
    {
        $company = InsuranceProviderFactory::credentialCompany($input->app, $input->company);
        $failures = [];
        $candidates = $this->candidates($input, $company, $failures);
        $quotes = [];
        $tasks = [];

        foreach ($candidates as $key => $candidate) {
            $cached = Cache::get($this->cacheKey($input, $company, $candidate['plan']));

            if ($cached instanceof QuoteResult) {
                $quotes[$key] = $cached;

                continue;
            }

            $tasks[$key] = new PlanQuoteTask(
                provider: $candidate['insurer']->provider,
                appId: $input->app->getId(),
                credentialCompanyId: $company->getId(),
                input: $input,
                planCode: (string) $candidate['plan']->external_code,
                vehicleModelId: $candidate['vehicleModelId'],
            );
        }

        $outcomes = $this->runInChunks($tasks);

        foreach (array_keys($tasks) as $key) {
            $outcome = $outcomes[$key] ?? false;
            $candidate = $candidates[$key];

            if ($outcome instanceof InsuranceSearchFailureReasonEnum) {
                $failures[] = $this->failure($candidate['insurer'], $outcome);

                continue;
            }

            if (! is_array($outcome)) {
                $failures[] = $this->failure($candidate['insurer'], InsuranceSearchFailureReasonEnum::TIMEOUT);

                continue;
            }

            if (isset($outcome['quote']) && $outcome['quote'] instanceof QuoteResult && $outcome['quote']->success) {
                $quotes[$key] = $outcome['quote'];
                $this->remember($input, $company, $candidate['plan'], $outcome['quote']);

                continue;
            }

            $failures[] = $this->failure(
                $candidate['insurer'],
                $outcome['reason'] ?? InsuranceSearchFailureReasonEnum::REJECTED
            );
        }

        return new InsuranceSearchResult(
            entries: $this->comparator->rank($this->entries($candidates, $quotes)),
            failures: $this->failuresOfInsurersWithoutQuotes($candidates, $quotes, $failures),
        );
    }

    private function runInChunks(array $tasks): array
    {
        $outcomes = [];
        $size = max(1, (int) config('insurance.task_workers', 2));
        $timeout = (int) config('insurance.search_timeout_ms', 8000);

        foreach (array_chunk($tasks, $size, true) as $chunk) {
            try {
                $outcomes += $this->runner->run($chunk, $timeout);
            } catch (TaskTimeoutException) {
                $outcomes += array_map(fn (): InsuranceSearchFailureReasonEnum => InsuranceSearchFailureReasonEnum::TIMEOUT, $chunk);
            } catch (Throwable $e) {
                Log::warning('Insurance search dispatch failed', ['exception' => $e::class]);
                $outcomes += array_map(fn (): InsuranceSearchFailureReasonEnum => InsuranceSearchFailureReasonEnum::UNAVAILABLE, $chunk);
            }
        }

        return $outcomes;
    }

    private function candidates(CanonicalQuoteInput $input, CompanyInterface $company, array &$failures): array
    {
        $candidates = [];

        $insurers = Insurer::query()
            ->fromApp($input->app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('status', InsuranceCatalogStatusEnum::ACTIVE->value)
            ->whereNotNull('provider')
            ->with([
                'plans' => fn ($plans) => $plans
                    ->where('is_deleted', 0)
                    ->where('status', InsuranceCatalogStatusEnum::ACTIVE->value)
                    ->where('type', $input->category->value)
                    ->whereNotNull('external_code'),
            ])
            ->orderBy('id')
            ->get();

        foreach ($insurers as $insurer) {
            if ($insurer->plans->isEmpty()) {
                continue;
            }

            $provider = $this->providerFor($insurer, $input, $company, $failures);

            if ($provider === null) {
                continue;
            }

            $vehicleModelId = $this->vehicleModelIdFor($provider, $insurer, $input);

            if ($vehicleModelId === false) {
                continue;
            }

            foreach ($insurer->plans as $plan) {
                if (! $this->canQuoteVehicle($provider, $input, $plan)) {
                    continue;
                }

                if ($this->lacksRequiredInformation($provider, $input, $plan)) {
                    $failures[] = $this->failure($insurer, InsuranceSearchFailureReasonEnum::INCOMPLETE_INFORMATION);

                    continue;
                }

                $candidates[$insurer->getId() . ':' . $plan->getId()] = [
                    'insurer' => $insurer,
                    'plan' => $plan,
                    'vehicleModelId' => $vehicleModelId,
                ];
            }
        }

        return $candidates;
    }

    private function providerFor(
        Insurer $insurer,
        CanonicalQuoteInput $input,
        CompanyInterface $company,
        array &$failures
    ): ?CanonicalQuoteProviderInterface {
        try {
            $provider = InsuranceProviderFactory::make($insurer->provider, $input->app, $company);
        } catch (Throwable) {
            $failures[] = $this->failure($insurer, InsuranceSearchFailureReasonEnum::UNAVAILABLE);

            return null;
        }

        return $provider instanceof CanonicalQuoteProviderInterface ? $provider : null;
    }

    private function vehicleModelIdFor(
        CanonicalQuoteProviderInterface $provider,
        Insurer $insurer,
        CanonicalQuoteInput $input
    ): string|false|null {
        if (! $provider->requiresVehicleModelId()) {
            return null;
        }

        return $this->vehicleModelResolver->resolve($input->app, $insurer->provider, $input->vehicle) ?? false;
    }

    private function canQuoteVehicle(
        CanonicalQuoteProviderInterface $provider,
        CanonicalQuoteInput $input,
        InsurancePlan $plan
    ): bool {
        return ! $provider instanceof VehicleReferenceQuoteProviderInterface
            || $provider->canQuoteVehicle($input, (string) $plan->external_code);
    }

    private function lacksRequiredInformation(
        CanonicalQuoteProviderInterface $provider,
        CanonicalQuoteInput $input,
        InsurancePlan $plan
    ): bool {
        return $provider instanceof VehicleReferenceQuoteProviderInterface
            && ! $provider->hasRequiredInformation($input, (string) $plan->external_code);
    }

    private function entries(array $candidates, array $quotes): array
    {
        $entries = [];

        foreach ($quotes as $key => $quote) {
            $entries[] = $this->entry($candidates[$key]['insurer'], $candidates[$key]['plan'], $quote);
        }

        return $entries;
    }

    private function entry(Insurer $insurer, InsurancePlan $plan, QuoteResult $quote): InsuranceSearchEntry
    {
        return new InsuranceSearchEntry(
            insurerId: $insurer->getId(),
            insurerName: $insurer->name,
            insurerLogo: $insurer->logo,
            provider: $insurer->provider,
            planId: $plan->getId(),
            planName: $plan->name,
            planSlug: $plan->slug,
            category: $plan->type,
            deductible: $plan->deductible !== null ? (float) $plan->deductible : null,
            validityDays: $plan->validity_days !== null ? (int) $plan->validity_days : null,
            sponsored: (bool) $plan->sponsored,
            recommendedScore: (int) $plan->recommended_score,
            commission: InsuranceCommissionCalculator::effective($quote, (float) $plan->commission_rate),
            quote: $quote,
        );
    }

    private function failuresOfInsurersWithoutQuotes(array $candidates, array $quotes, array $failures): array
    {
        $insurersWithQuotes = [];

        foreach (array_keys($quotes) as $key) {
            $insurersWithQuotes[$candidates[$key]['insurer']->getId()] = true;
        }

        $perInsurer = [];

        foreach ($failures as $failure) {
            if (isset($insurersWithQuotes[$failure->insurerId]) || isset($perInsurer[$failure->insurerId])) {
                continue;
            }

            $perInsurer[$failure->insurerId] = $failure;
        }

        return array_values($perInsurer);
    }

    private function failure(Insurer $insurer, InsuranceSearchFailureReasonEnum $reason): InsuranceSearchFailure
    {
        return new InsuranceSearchFailure(
            insurerId: $insurer->getId(),
            insurerName: $insurer->name,
            reason: $reason,
            message: $reason->customerMessage(),
        );
    }

    private function remember(
        CanonicalQuoteInput $input,
        CompanyInterface $company,
        InsurancePlan $plan,
        QuoteResult $quote
    ): void {
        $ttl = (int) config('insurance.search_cache_ttl', 300);

        if ($ttl <= 0) {
            return;
        }

        Cache::put($this->cacheKey($input, $company, $plan), $quote->withoutRaw(), $ttl);
    }

    private function cacheKey(CanonicalQuoteInput $input, CompanyInterface $company, InsurancePlan $plan): string
    {
        return InsuranceSearchCacheKey::make(
            $input->app->getId(),
            $company->getId(),
            $plan->getId(),
            $input->signature()
        );
    }
}
