<?php

declare(strict_types=1);

namespace Baka\Traits;

trait KanvasFactoryStateTrait
{
    public function company(int $companyId): self
    {
        return $this->state(function (array $attributes) use ($companyId) {
            return [
                'companies_id' => $companyId,
            ];
        });
    }

    public function app(int $appId): self
    {
        return $this->state(function (array $attributes) use ($appId) {
            return [
                'apps_id' => $appId,
            ];
        });
    }

    public function user(int $userId): self
    {
        return $this->state(function (array $attributes) use ($userId) {
            return [
                'users_id' => $userId,
            ];
        });
    }
}
