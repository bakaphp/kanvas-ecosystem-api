<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Traits;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Bavix\Wallet\Internal\Exceptions\ModelNotFoundException as WalletModelNotFoundException;
use Bavix\Wallet\Traits\HasWallets;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Souk\Wallet\Wallet;

trait HasWalletsTrait
{
    use HasWallets;

    public function createAppWallet(AppInterface $app, array $data): Wallet
    {
        $data['slug'] = ! isset($data['slug']) ? Str::simpleSlug($data['name'] . ' ' . $app->getId()) : Str::simpleSlug($data['slug'] . ' ' . $app->getId());

        $wallet = $this->getWalletByApp($app, $data['slug']);
        if ($wallet) {
            return $wallet;
        }
        $wallet = $this->createWallet($data);

        return $wallet;
    }

    public function getWalletByApp(AppInterface $app, string $slug): ?Wallet
    {
        // Check if the slug already ends with -{appId}, if not, append it
        $appIdSuffix = '-' . $app->getId();
        if (! Str::endsWith($slug, $appIdSuffix)) {
            $slug .= ' ' . $app->getId();
        }
        $slug = Str::simpleSlug($slug);

        // Try to get the wallet with the given slug.
        try {
            return $this->getWalletOrFail($slug);
        } catch (ModelNotFoundException|WalletModelNotFoundException $exception) {
            // If the wallet is not found, return null.
            return null;
        }
    }
}
