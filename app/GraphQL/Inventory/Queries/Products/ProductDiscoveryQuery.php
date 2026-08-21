<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Queries\Products;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Recommendations\Actions\RecommendProductsAction;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Jobs\LogRecommendationImpressionJob;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoveryResolver;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;

/**
 * A Query, so clients cache it — which is why the attribution id comes FROM the
 * client. A server-minted one would be frozen into that cache and every repeat
 * would report the first search's id.
 */
class ProductDiscoveryQuery
{
    /**
     * @param array{input: array{query: string, request_id?: mixed, company_id?: mixed, session_id?: string, limit?: int}} $request
     *
     * @return array{request_id: string, recommendations: array}
     */
    public function discover(mixed $rootValue, array $request): array
    {
        $app = app(Apps::class);
        $input = $request['input'];
        $query = (string) $input['query'];

        $user = $this->actingUser();
        $company = $this->resolveCompany($app, $user, $input['company_id'] ?? null);
        $requestId = $this->resolveRequestId($input['request_id'] ?? null);

        $recommendations = new RecommendProductsAction($app, $company)
            ->execute($query, (int) ($input['limit'] ?? 8));

        $this->logImpression(
            app: $app,
            company: $company,
            requestId: $requestId,
            query: $query,
            recommendations: $recommendations,
            sessionId: $input['session_id'] ?? null,
            user: $user,
        );

        return [
            'request_id' => $requestId,
            'recommendations' => $recommendations,
        ];
    }

    /** App-key callers must supply it; a minted one dies in their cache. */
    private function resolveRequestId(mixed $requestId): string
    {
        $requestId = is_string($requestId) ? trim($requestId) : '';

        if ($requestId === '') {
            if ($this->isAppKeyRequest()) {
                throw new ValidationException(
                    'request_id is required when calling with an app key. Generate one per search '
                    . 'and send it back with any click or purchase so the outcome can be attributed.'
                );
            }

            return (string) Str::uuid();
        }

        // Unique indexed column — free text would let a caller fill it with junk.
        if (! Str::isUuid($requestId)) {
            throw new ValidationException('request_id must be a UUID.');
        }

        return $requestId;
    }

    /**
     * An app-key request authenticates as a fallback user whose current company is
     * arbitrary, so it must name one. A real user may only name one they belong to.
     */
    private function resolveCompany(AppInterface $app, ?Users $user, mixed $companyId): CompanyInterface
    {
        if ($companyId === null || $companyId === '') {
            if ($this->isAppKeyRequest() || $user === null) {
                throw new ValidationException(
                    'company_id is required when calling with an app key: there is no user to infer the company from.'
                );
            }

            return $user->getCurrentCompany();
        }

        /** @var Companies|null $company */
        $company = AppsRepository::getActiveCompaniesForAppBuilder($app)
            ->where('companies.id', (int) $companyId)
            ->first();

        if ($company === null) {
            throw new ValidationException('No company ' . (int) $companyId . ' is available on this app.');
        }

        if (! $this->isAppKeyRequest() && $user !== null) {
            UsersRepository::belongsToCompany($user, $company);
        }

        return $company;
    }

    /** `@guard` accepts an app key with no real end user behind it. */
    private function isAppKeyRequest(): bool
    {
        return app()->bound(AppKey::class);
    }

    private function actingUser(): ?Users
    {
        try {
            /** @var Users|null $user */
            $user = auth()->user();

            return $user;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, array{product: array}> $recommendations
     */
    private function logImpression(
        AppInterface $app,
        CompanyInterface $company,
        string $requestId,
        string $query,
        array $recommendations,
        ?string $sessionId,
        ?Users $user,
    ): void {
        $intent = ProductIntent::fromSentence($query, new IntentLexiconService($app));

        LogRecommendationImpressionJob::dispatch(
            $app,
            $company,
            $requestId,
            $query,
            array_column(array_column($recommendations, 'product'), 'id'),
            $this->isAppKeyRequest() ? null : $user?->getId(),
            $sessionId,
            new ProductDiscoveryResolver($app, $company)->isOnTypesense() ? 'typesense' : 'sql',
            $intent->minPrice,
            $intent->maxPrice,
            $intent->inStockOnly,
        );
    }
}
