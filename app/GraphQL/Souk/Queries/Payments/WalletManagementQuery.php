<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Payments;

use Baka\Contracts\AppInterface;
use Exception;
use GraphQL\Type\Definition\ResolveInfo;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Souk\Wallet\Transaction;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

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

        return Transaction::query()
            ->where('wallet_id', $wallet->getKey());
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

        return Transaction::query()
            ->where('wallet_id', $wallet->getKey());
    }

    private function getPasoRapidoBalance(AppInterface $app, Companies $company, string $tag): array
    {
        try {
            $pasoRapidoService = new PasoRapidoService($app, $company);
            $customer = $pasoRapidoService->verifyCustomer($tag);

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

            return [
                'message' => $errorMessage,
                'data' => null,
            ];
        } catch (Exception $e) {
            return [
                'message' => $e->getMessage(),
                'data' => null,
            ];
        }
    }
}
