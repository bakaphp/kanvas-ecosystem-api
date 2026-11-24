<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Customers\Actions\CreatePeopleFromUserAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Throwable;

class AssignCorporateOrderOwnerAction
{
    public const ACTOR_METADATA_KEY = 'corporate_actor_user_id';
    public const CREATOR_METADATA_KEY = ResolveCorporateOrderCreatorAction::CREATOR_METADATA_KEY;
    public const CORPORATE_TAG = 'corporative';

    public function __construct(
        protected Order $order,
        protected ?Users $creator = null,
        protected ?Companies $corporateCompany = null
    ) {
    }

    public function execute(): ?Order
    {
        $resolver = new ResolveCorporateOrderCreatorAction($this->order);
        $rawCompanyId = $resolver->metadataValue(ResolveCorporateOrderCreatorAction::COMPANY_METADATA_KEY);

        if ($rawCompanyId === null && $this->creator === null) {
            return null;
        }

        if (($this->corporateCompany ?? $resolver->company()) !== null) {
            $this->order->addTag(self::CORPORATE_TAG);
        }

        $creator = $this->creator ?? $resolver->execute();

        if ($creator === null) {
            Log::warning('Corporate order creator rejected, users_id left unchanged', [
                'order_id' => $this->order->getId(),
                'created_by_user_id' => $resolver->metadataValue(self::CREATOR_METADATA_KEY),
                'user_company_id' => $rawCompanyId,
            ]);

            return null;
        }

        $ownerChanges = (int) $this->order->users_id !== $creator->getId();
        $customer = $this->resolveCustomer($creator);
        $customerChanges = $customer !== null && (int) $this->order->people_id !== $customer->getId();

        if (! $ownerChanges && ! $customerChanges) {
            return null;
        }

        if ($ownerChanges) {
            $this->order->keepFirstMetadataData(self::ACTOR_METADATA_KEY, (int) $this->order->users_id);
            $this->order->users_id = $creator->getId();
        }

        if ($customerChanges) {
            $this->order->people_id = $customer->getId();
        }

        $this->order->saveQuietly();
        $this->order->searchable();

        return $this->order;
    }

    public function existingCustomer(Users $creator): ?People
    {
        $email = Str::trimToNull($creator->email);

        if ($email === null) {
            return null;
        }

        $resolver = new ResolveCorporateOrderCreatorAction($this->order);

        $companies = [$this->corporateCompany ?? $resolver->company(), $this->order->company, $this->branchCompanyOf($creator)];

        foreach ($companies as $company) {
            $existing = $company === null
                ? null
                : PeoplesRepository::getByEmail($email, $company, $this->order->app);

            if ($existing !== null) {
                return $existing;
            }
        }

        $profilePeopleId = (int) UsersAssociatedApps::query()
            ->where('users_id', $creator->getId())
            ->where('apps_id', $this->order->app->getId())
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->value('people_id');

        return $profilePeopleId > 0
            ? People::query()
                ->where('apps_id', $this->order->app->getId())
                ->where('is_deleted', 0)
                ->find($profilePeopleId)
            : null;
    }

    protected function resolveCustomer(Users $creator): ?People
    {
        try {
            return $this->existingCustomer($creator) ?? $this->createCustomerOf($creator);
        } catch (Throwable $exception) {
            Log::warning('Corporate order customer could not be resolved, people_id left unchanged', [
                'order_id' => $this->order->getId(),
                'creator_id' => $creator->getId(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    protected function branchCompanyOf(Users $creator): ?Companies
    {
        try {
            return $creator->getCurrentBranch()->company;
        } catch (Throwable) {
            return null;
        }
    }

    protected function createCustomerOf(Users $creator): People
    {
        return new CreatePeopleFromUserAction(
            $this->order->app,
            $creator->getCurrentBranch(),
            $creator
        )->execute();
    }
}
