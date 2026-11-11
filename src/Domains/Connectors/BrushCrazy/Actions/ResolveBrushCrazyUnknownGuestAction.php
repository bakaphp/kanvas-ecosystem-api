<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleType;

/**
 * The one People per studio company that stands in for BrushCrazy's "Guest" — a sale with no
 * `customer_id` (`Sale::customer()` defaults to a User named Guest). Souk requires a people_id on
 * every Order, and a registration needs a participant, so every slice that meets a buyer-less row
 * resolves this same People.
 *
 * Found by the presence of BRUSHCRAZY_UNKNOWN_GUEST in the company, whatever its value.
 */
class ResolveBrushCrazyUnknownGuestAction
{
    public const string EXTERNAL_ID = 'unknown-guest';

    private const int LOCK_SECONDS = 30;

    private const int LOCK_WAIT_SECONDS = 15;

    protected IdMapRepository $idMaps;

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        ?IdMapRepository $idMaps = null,
    ) {
        $this->idMaps = $idMaps ?? new IdMapRepository();
    }

    /**
     * Two syncs on different classes of one company would each miss the other's new row and create
     * a second guest; the find and the create happen under one lock.
     */
    public function execute(): People
    {
        return Cache::lock(
            'brushcrazy-unknown-guest:' . $this->app->getId() . ':' . $this->context->companyId(),
            self::LOCK_SECONDS
        )->block(self::LOCK_WAIT_SECONDS, fn (): People => $this->findOrCreate());
    }

    protected function findOrCreate(): People
    {
        $existingId = $this->existingId();

        if ($existingId !== null) {
            $people = People::withTrashed()->where('id', $existingId)->first();

            if ($people !== null) {
                return $people;
            }
        }

        $people = People::withoutEvents(function () {
            $people = new People();
            $people->forceFill([
                'uuid' => Str::uuid()->toString(),
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->context->companyId(),
                'users_id' => $this->user->getId(),
                'people_types_id' => $this->peopleTypeId(),
                'firstname' => 'Unknown',
                'lastname' => 'Guest',
                'name' => 'Unknown Guest',
            ]);
            $people->saveQuietly();

            return $people;
        });

        $people->disableWorkflows();
        $people->set(CustomFieldEnum::BRUSHCRAZY_UNKNOWN_GUEST->value, self::EXTERNAL_ID);

        return $people;
    }

    protected function existingId(): ?int
    {
        $ids = $this->idMaps->load(
            $this->context->companyId(),
            People::class,
            CustomFieldEnum::BRUSHCRAZY_UNKNOWN_GUEST,
        );

        return $ids !== [] ? min($ids) : null;
    }

    protected function peopleTypeId(): int
    {
        return $this->idMaps->lookup(
            $this->context->companyId(),
            PeopleType::class,
            'people-type:customer'
        );
    }
}
