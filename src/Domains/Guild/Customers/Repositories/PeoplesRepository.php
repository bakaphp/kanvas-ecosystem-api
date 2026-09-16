<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\SearchableTrait;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleRelationship;
use Override;

class PeoplesRepository
{
    use SearchableTrait;

    #[Override]
    public static function getModel(): Model
    {
        return new People();
    }

    public static function getRelationshipTypeById(int $id, CompanyInterface $company): PeopleRelationship
    {
        try {
            return PeopleRelationship::fromCompany($company)
                ->fromApp()
                ->notDeleted()
                ->where('id', $id)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    public static function getByEmail(string $email, CompanyInterface $company, ?AppInterface $app = null): ?People
    {
        /**
         * @psalm-suppress MixedReturnStatement
         */
        $query = People::from('peoples as p')
            ->withoutGlobalScopes()
            ->join('peoples_contacts as c', 'p.id', '=', 'c.peoples_id')
            ->where('c.value', $email)
            ->where('c.contacts_types_id', ContactTypeEnum::EMAIL->value) // Assuming EMAIL is a constant in ContactsTypes model
            ->where('c.is_deleted', 0)
            ->where('p.companies_id', $company->getId())
            ->where('p.is_deleted', 0);

        if ($app) {
            $query->where('p.apps_id', $app->getId());
        }

        return $query->select('p.*')->first();
    }

    public static function getByValue(string $value, CompanyInterface $company, AppInterface $app): ?People
    {
        return People::from('peoples as p')
            ->withoutGlobalScopes()
            ->join('peoples_contacts as c', 'p.id', '=', 'c.peoples_id')
            ->where('c.value', $value)
            ->where('c.is_deleted', 0)
            ->where('p.companies_id', $company->getId())
            ->where('p.apps_id', $app->getId())
            ->where('p.is_deleted', 0)
            ->select('p.*')
            ->first();
    }

    public static function getMatchingEmailPhone(
        AppInterface $app,
        CompanyInterface $company,
        ?string $email = null,
        ?string $phone = null
    ): ?People {
        if (empty($email) && empty($phone)) {
            throw new Exception('Email or Phone is required');
        }
        $q = People::from('peoples as p')
            ->withoutGlobalScopes()
            ->where('p.companies_id', $company->getId())
            ->where('p.apps_id', $app->getId())
            ->where('p.is_deleted', 0)
            ->select('p.*');

        if ($email) {
            $q->whereExists(function ($sub) use ($email) {
                $sub->from('peoples_contacts as ce')
                    ->whereColumn('ce.peoples_id', 'p.id')
                    ->where('ce.contacts_types_id', ContactTypeEnum::EMAIL->value)
                    ->where('ce.is_deleted', 0)
                    ->whereRaw('LOWER(ce.value) = ?', [$email]);
            });
        }

        if ($phone) {
            $q->whereExists(function ($sub) use ($phone) {
                $sub->from('peoples_contacts as cp')
                    ->whereColumn('cp.peoples_id', 'p.id')
                    ->whereIn('cp.contacts_types_id', [ContactTypeEnum::CELLPHONE->value, ContactTypeEnum::PHONE->value])
                    ->where('cp.is_deleted', 0)
                    ->whereRaw('REGEXP_REPLACE(cp.value, "[^0-9]", "") = ?', [$phone]);
            });
        }

        return $q->first();
    }

    public static function getByDaysCreated(int $days, Apps $app): Collection
    {
        return People::whereRaw('DATEDIFF(NOW(), created_at) = ?', [$days])
        ->where('apps_id', $app->getId())
        ->notDeleted()
        ->get();
    }

    /**
     * @return Builder<People>
     */
    public static function getByPhoneNumber(AppInterface $app, CompanyInterface $company, array $phoneNumbers): Builder
    {
        return People::whereHas(
            'contacts',
            function (Builder $query) use ($phoneNumbers) {
                $query->where(function (Builder $q) use ($phoneNumbers) {
                    foreach ($phoneNumbers as $phone) {
                        $q->orWhereRaw("REGEXP_REPLACE(value, '[^0-9]', '') = ?", [$phone]);
                    }
                })
                ->whereIn('contacts_types_id', [
                    ContactTypeEnum::CELLPHONE->value,
                    ContactTypeEnum::PHONE->value,
                ]);
            }
        )
        ->fromCompany($company)
        ->fromApp($app)
        ->notDeleted();
    }
}
