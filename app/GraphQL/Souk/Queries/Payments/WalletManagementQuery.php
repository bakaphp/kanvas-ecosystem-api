<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Payments;

use Baka\Contracts\AppInterface;
use Exception;
use GraphQL\Type\Definition\ResolveInfo;
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

    private function getPasoRapidoBalance(AppInterface $app, Companies $company, string $tag): array
    {
        $pasoRapidoService = new PasoRapidoService($app, $company);

        try {
            $customer = $pasoRapidoService->verifyCustomer($tag);

            return [
                'message' => 'success',
                'data' => $customer->toArray(),
            ];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $errorMessage = json_decode((string) $response->getBody())->descripcionMensaje;
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
