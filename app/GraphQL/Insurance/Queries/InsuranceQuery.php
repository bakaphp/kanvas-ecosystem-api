<?php

declare(strict_types=1);

namespace App\GraphQL\Insurance\Queries;

use App\GraphQL\Concerns\ResolvesActingContext;
use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use InvalidArgumentException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Contracts\CatalogProviderInterface;
use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\InsuranceSearchEntry;
use Kanvas\Insurance\DataTransferObject\InsuranceSearchFailure;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Insurance\Services\InsurancePlanProductResolver;
use Kanvas\Insurance\Services\InsuranceQuoteBinding;
use Kanvas\Insurance\Services\InsuranceSearchService;

class InsuranceQuery
{
    use ResolvesActingContext;

    public function catalog(mixed $rootValue, array $request): array
    {
        $provider = $this->resolveProvider($request['provider'] ?? null);

        if (! $provider instanceof CatalogProviderInterface) {
            throw new ValidationException($provider->name() . ' does not expose catalogs');
        }

        return $provider->getCatalog(
            (string) $request['catalog'],
            (array) ($request['params'] ?? [])
        );
    }

    public function quote(mixed $rootValue, array $request): array
    {
        $provider = $this->resolveProvider($request['provider'] ?? null);

        $quote = $provider->quote(
            new InsuranceQuoteRequest(
                product: (string) $request['product'],
                payload: (array) ($request['input'] ?? []),
            )
        );

        return [
            'provider' => $provider->name(),
            'product' => (string) $request['product'],
            'success' => $quote->success,
            'message' => $quote->message,
            'quote_number' => $quote->quoteNumber,
            'premium' => $quote->premium,
            'rate_per_km' => $quote->ratePerKm,
            'tax' => $quote->tax,
            'total' => $quote->total,
            'currency' => $quote->currency,
            'commission' => $quote->commission,
            'can_emit' => $quote->canEmit,
            'inspection_status' => $quote->inspectionStatus,
            'data' => $quote->raw,
        ];
    }

    public function search(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        $input = $request['input'];
        $input['underwriting']['ip_address'] = request()->ip();

        $canonicalInput = CanonicalQuoteInput::fromMultiple($ctx->app, $ctx->company, $input);
        $result = app(InsuranceSearchService::class)->execute($canonicalInput);

        foreach ($result->entries as $entry) {
            InsuranceQuoteBinding::bind(
                $ctx->app->getId(),
                $ctx->user->getId(),
                $entry->quote->quoteNumber,
                $entry->planId,
                $canonicalInput->vehicleVariantId
            );
        }

        $variantIds = $this->planVariantIds($ctx->app, $ctx->company, $result->entries);

        return [
            'entries' => array_map(
                fn (InsuranceSearchEntry $entry, int $index): array => $this->presentEntry(
                    $entry,
                    $index + 1,
                    $variantIds[$entry->planId] ?? null
                ),
                $result->entries,
                array_keys($result->entries)
            ),
            'failures' => array_map($this->presentFailure(...), $result->failures),
        ];
    }

    protected function planVariantIds(AppInterface $app, CompanyInterface $company, array $entries): array
    {
        $plans = InsurancePlan::query()
            ->with('insurer')
            ->whereIn('id', array_map(fn (InsuranceSearchEntry $entry): int => $entry->planId, $entries))
            ->get();

        $resolver = InsurancePlanProductResolver::forActing($app, $company);
        $variantIds = [];

        foreach ($plans as $plan) {
            $variantIds[$plan->getId()] = $resolver->variantFor($plan)?->getId();
        }

        return $variantIds;
    }

    protected function presentEntry(InsuranceSearchEntry $entry, int $rank, ?int $variantId): array
    {
        $quote = $entry->quote;

        return [
            'rank' => $rank,
            'insurer' => ['id' => $entry->insurerId, 'name' => $entry->insurerName, 'logo' => $entry->insurerLogo],
            'plan' => [
                'id' => $entry->planId,
                'name' => $entry->planName,
                'slug' => $entry->planSlug,
                'type' => $entry->category->value,
                'deductible' => $entry->deductible,
                'validity_days' => $entry->validityDays,
                'variant_id' => $variantId,
            ],
            'quote_number' => $quote->quoteNumber,
            'price' => $entry->price(),
            'premium' => $quote->premium,
            'tax' => $quote->tax,
            'total' => $quote->total,
            'currency' => $quote->currency,
            'can_emit' => $quote->canEmit,
            'inspection_status' => $quote->inspectionStatus,
            'is_sponsored' => $entry->sponsored,
            'is_recommended' => $entry->isRecommended(),
        ];
    }

    protected function presentFailure(InsuranceSearchFailure $failure): array
    {
        return [
            'insurer' => ['id' => $failure->insurerId, 'name' => $failure->insurerName, 'logo' => null],
            'reason' => $failure->reason->value,
            'message' => $failure->message,
        ];
    }

    protected function resolveProvider(?string $requested): InsuranceProviderInterface
    {
        $ctx = $this->actingContext();

        try {
            return InsuranceProviderFactory::forQuoting($ctx->app, $ctx->company, $requested);
        } catch (InvalidArgumentException $e) {
            throw new ValidationException($e->getMessage());
        }
    }
}
