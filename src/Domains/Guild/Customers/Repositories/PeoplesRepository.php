<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Repositories;

use Baka\Contracts\CompanyInterface;
use Baka\Traits\SearchableTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleRelationship;

class PeoplesRepository
{
    use SearchableTrait;

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

    public static function getByEmail(string $email, CompanyInterface $company): ?People
    {
        /**
         * @psalm-suppress MixedReturnStatement
         */
        return People::from('peoples as p')
            ->join('contacts as c', 'p.id', '=', 'c.peoples_id')
            ->where('c.value', $email)
            ->where('c.contacts_types_id', ContactTypeEnum::EMAIL->value) // Assuming EMAIL is a constant in ContactsTypes model
            ->where('p.companies_id', $company->getId())
            ->where('p.is_deleted', 0)
            ->first();
    }

    public static function getByValue(string $value, CompanyInterface $company): ?People
    {
        return People::from('peoples as p')
            ->join('contacts as c', 'p.id', '=', 'c.peoples_id')
            ->where('c.value', $value)
            ->where('p.companies_id', $company->getId())
            ->where('p.is_deleted', 0)
            ->first();
    }

    public static function getByDaysCreated(int $days, Apps $app): Collection
    {
        return People::whereRaw('DATEDIFF(NOW(), created_at) = ?', [$days])
        ->where('apps_id', $app->getId())
        ->get();
    }

    public static function findByEmailOrCreate(string $email, UserInterface $user, CompanyInterface $company, ?string $name): People
    {
        $people = self::getByEmail($email, $company);

        if (! $people) {
            $people = new People();
            $people->companies_id = $company->getId();
            $people->name = $name ?? explode('@', $email)[0];
            $people->users_id = $user->getId();

            $people->saveOrFail();

            $people->contacts()->save(
                new Contact([
                    'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                    'value' => $email,
                    'weight' => 100,
                ])
            );
        }

        return $people;
    }
}
