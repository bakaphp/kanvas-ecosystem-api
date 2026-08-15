<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Payments;

use Baka\Exceptions\LightHouseCustomException;
use Exception;
use GraphQL\Type\Definition\ResolveInfo;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Connectors\Movipass\Jobs\SyncVehicleTagDataJob;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Souk\Wallet\Transaction;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class WalletManagementQuery
{
    public function getBalance(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): float|array
    {
        $tag = strtolower($args['tag']);
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $useFakeWallet = (bool) ($app->get('use-fake-wallet-paso-rapido') ?? false);

        if ($useFakeWallet) {
            return $this->getPasoRapidoBalance($app, $company, $tag);
        }

        if (! $company->hasWallet($tag) && $tag !== 'default') {
            throw new ModelNotFoundException(
                'Wallet not found for the given tag.',
            );
        }

        $wallet = $company->createAppWallet($app, ['name' => $tag]);

        return [
            'balance' => (float) $wallet->balanceFloatNum,
        ];
    }

    public function getUserWalletBalance(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): float|array
    {
        $tag = strtolower($args['tag']);
        $app = app(Apps::class);
        $userId = $args['userId'] ?? null;
        $user = auth()->user();

        if ($user->isAdmin() && $userId !== null && $userId !== $user->getId()) {
            $user = $user->getById($userId);
        }

        if (! $user->hasWallet($tag) && $tag !== 'default') {
            throw new ModelNotFoundException(
                'Wallet not found for the given tag.'
            );
        }

        $wallet = $user->createAppWallet($app, ['name' => $tag]);

        return [
            'balance' => (float) $wallet->balanceFloatNum,
            'message' => '',
        ];
    }

    public function getTransactions(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        $tag = strtolower($args['tag']);
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        if (! $company->hasWallet($tag) && $tag !== 'default') {
            throw new ModelNotFoundException(
                'Wallet not found for the given tag.',
            );
        }

        $wallet = $company->createAppWallet($app, ['name' => $tag]);

        $query = Transaction::query()->where('wallet_id', $wallet->getKey());

        return $this->applyMetaFilter($query, $args['hasMeta'] ?? null);
    }

    public function getUserTransactions(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        $tag = strtolower($args['tag']);
        $app = app(Apps::class);
        $user = auth()->user();

        if (! $user->hasWallet($tag) && $tag !== 'default') {
            throw new ModelNotFoundException(
                'Wallet not found for the given tag.',
            );
        }

        $wallet = $user->createAppWallet($app, ['name' => $tag]);

        $query = Transaction::query()->where('wallet_id', $wallet->getKey());

        return $this->applyMetaFilter($query, $args['hasMeta'] ?? null);
    }

    public function getCompanyWalletBalance(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): float|array
    {
        $tag = strtolower($args['tag']);
        $app = app(Apps::class);
        $company = Companies::getById((int) $args['company_id']);

        CompaniesRepository::hasAccessToThisApp($company, $app);
        CompaniesRepository::userAssociatedToCompany($company, auth()->user());

        if (! $company->hasWallet($tag) && $tag !== 'default') {
            throw new ModelNotFoundException(
                'Wallet not found for the given tag.',
            );
        }

        $wallet = $company->createAppWallet($app, ['name' => $tag]);

        return [
            'balance' => (float) $wallet->balanceFloatNum,
            'message' => '',
        ];
    }

    public function getCompanyTransactions(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        $tag = strtolower($args['tag']);
        $app = app(Apps::class);
        $company = Companies::getById((int) $args['company_id']);

        CompaniesRepository::hasAccessToThisApp($company, $app);
        CompaniesRepository::userAssociatedToCompany($company, auth()->user());

        if (! $company->hasWallet($tag) && $tag !== 'default') {
            throw new ModelNotFoundException(
                'Wallet not found for the given tag.',
            );
        }

        $wallet = $company->createAppWallet($app, ['name' => $tag]);

        $query = Transaction::query()->where('wallet_id', $wallet->getKey());

        return $this->applyMetaFilter($query, $args['hasMeta'] ?? null);
    }

    private function applyMetaFilter(Builder $query, ?array $hasMeta): Builder
    {
        if (empty($hasMeta['path']) || ! isset($hasMeta['value'])) {
            return $query;
        }

        $operator = match ($hasMeta['operator'] ?? 'EQ') {
            'LIKE' => 'LIKE',
            default => '=',
        };
        $query->whereRaw(
            "JSON_UNQUOTE(JSON_EXTRACT(meta, ?)) {$operator} ?",
            ['$.' . $hasMeta['path'], $hasMeta['value']],
        );

        return $query;
    }

    private function getPasoRapidoBalance(Apps $app, Companies $company, string $tag): array
    {
        try {
            $pasoRapidoService = new PasoRapidoService($app, $company);
            $customer = $pasoRapidoService->verifyCustomer($tag);

            SyncVehicleTagDataJob::dispatch($app, $tag, $customer);

            return [
                'message' => 'success',
                'data' => $customer->toArray(),
            ];
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $body = json_decode((string) $response->getBody());
                $errorMessage = $body->descripcionMensaje ?? $body->message ?? $e->getMessage();
            } else {
                $errorMessage = $e->getMessage();
            }

            Log::warning('PasoRapido balance lookup failed', [
                'tag' => $tag,
                'status' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : null,
                'message' => $errorMessage,
                'user_id' => auth()->id() ?? 0,
            ]);

            return [
                'message' => $errorMessage,
                'data' => null,
            ];
        } catch (LightHouseCustomException | TooManyRequestsHttpException $e) {
            Log::warning('PasoRapido balance lookup rejected', [
                'tag' => $tag,
                'message' => $e->getMessage(),
                'user_id' => auth()->id() ?? 0,
            ]);

            return [
                'message' => $e->getMessage(),
                'data' => null,
            ];
        } catch (Exception $e) {
            report($e);

            return [
                'message' => $e->getMessage(),
                'data' => null,
            ];
        }
    }
}
