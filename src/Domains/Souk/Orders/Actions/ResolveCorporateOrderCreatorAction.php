<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;

class ResolveCorporateOrderCreatorAction
{
    public const CREATOR_METADATA_KEY = 'created_by_user_id';
    public const COMPANY_METADATA_KEY = 'user_company_id';

    public function __construct(
        protected Order $order
    ) {
    }

    public function execute(): ?Users
    {
        $company = $this->company();
        $creatorId = $this->creatorId();

        if ($company === null || $creatorId === null) {
            return null;
        }

        $creator = Users::query()->notDeleted()->find($creatorId);

        if ($creator === null) {
            return null;
        }

        try {
            UsersRepository::belongsToThisApp($creator, $this->order->app, $company);
        } catch (Throwable) {
            return null;
        }

        return $creator;
    }

    public function metadataValue(string $key): mixed
    {
        $metadata = $this->order->metadata ?? [];

        return $metadata['data'][$key] ?? $metadata[$key] ?? null;
    }

    private function company(): ?Companies
    {
        $companyId = (int) $this->metadataValue(self::COMPANY_METADATA_KEY);

        if ($companyId <= 0) {
            return null;
        }

        try {
            return Companies::getById($companyId);
        } catch (Throwable) {
            return null;
        }
    }

    private function creatorId(): ?int
    {
        $raw = $this->metadataValue(self::CREATOR_METADATA_KEY);

        if (! is_int($raw) && ! is_string($raw)) {
            return null;
        }

        $creatorId = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $creatorId === false ? null : $creatorId;
    }
}
