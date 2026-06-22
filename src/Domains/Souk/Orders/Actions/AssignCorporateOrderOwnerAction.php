<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;

class AssignCorporateOrderOwnerAction
{
    public const ACTOR_METADATA_KEY = 'corporate_actor_user_id';
    public const CREATOR_METADATA_KEY = 'created_by_user_id';

    public function __construct(
        protected Order $order
    ) {
    }

    public function execute(): ?Order
    {
        $metadata = $this->order->metadata ?? [];

        // user_company_id lives under metadata.data for corporate orders; top-level is the
        // legacy fallback (both paths are the convention — see SyncOrderProvidersAction).
        $companyId = (int) ($metadata['data']['user_company_id'] ?? $metadata['user_company_id'] ?? 0);
        if (! $companyId) {
            return null;
        }

        $company = Companies::getById($companyId);
        $targetUser = $this->resolveTargetUser($company, $metadata);

        if ($targetUser === null || (int) $this->order->users_id === $targetUser->getId()) {
            return null;
        }

        $this->order->metadata = [
            ...$metadata,
            'data' => [
                ...($metadata['data'] ?? []),
                // first writer wins: a retry must not overwrite the original owner captured on the first pass
                self::ACTOR_METADATA_KEY => $metadata['data'][self::ACTOR_METADATA_KEY] ?? (int) $this->order->users_id,
            ],
        ];
        $this->order->users_id = $targetUser->getId();
        $this->order->saveQuietly();

        return $this->order;
    }

    private function resolveTargetUser(Companies $company, array $metadata): ?Users
    {
        $creatorId = (int) ($metadata['data'][self::CREATOR_METADATA_KEY] ?? $metadata[self::CREATOR_METADATA_KEY] ?? 0);

        if ($creatorId) {
            $creator = Users::find($creatorId);
            if ($creator !== null && $this->belongsToCompanyApp($creator, $company)) {
                return $creator;
            }
        }

        return $company->user;
    }

    private function belongsToCompanyApp(Users $user, Companies $company): bool
    {
        try {
            UsersRepository::belongsToThisApp($user, $this->order->app, $company);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
