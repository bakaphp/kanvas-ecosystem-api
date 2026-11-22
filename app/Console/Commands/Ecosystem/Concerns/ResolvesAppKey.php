<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Concerns;

use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;

trait ResolvesAppKey
{
    private function resolveKey(Apps $app): ?AppKey
    {
        $identifier = $this->option('key');
        $name = $this->option('name');

        if ($identifier === null && $name === null) {
            return null;
        }

        $query = AppKey::notDeleted()->where('apps_id', $app->getId());

        if ($identifier !== null) {
            $query->where(
                fn ($query) => $query->where('client_id', $identifier)
                    ->orWhere('client_secret_id', $identifier)
            );
        }

        if ($name !== null) {
            $query->where('name', $name);
        }

        return $query->first();
    }
}
