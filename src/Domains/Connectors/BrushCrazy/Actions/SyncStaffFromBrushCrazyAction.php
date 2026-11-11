<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Concerns\UpsertsMirroredRows;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\StaffMapper;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Connectors\BrushCrazy\Support\StudioCredits;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Guild\Customers\Models\People;
use stdClass;

/**
 * Writes one BrushCrazy staff member into one studio company as a People plus a Facilitator. The
 * bulk import and the mirror webhook both call it.
 *
 * Staff get no Kanvas user or login: the Facilitator is what classes link their instructor to.
 *
 * The People comes from SyncCustomerFromBrushCrazyAction, so a staff member who also bought in the
 * studio is one People, not two. The Facilitator is resolved by BRUSHCRAZY_USER_ID on the
 * Facilitator itself, never by name or People.
 */
class SyncStaffFromBrushCrazyAction
{
    use UpsertsMirroredRows;

    protected IdMapRepository $idMaps;

    protected SyncCustomerFromBrushCrazyAction $people;

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        ?IdMapRepository $idMaps = null,
        ?StudioCredits $credits = null,
    ) {
        $this->idMaps = $idMaps ?? new IdMapRepository();
        $this->people = new SyncCustomerFromBrushCrazyAction(
            app: $app,
            user: $user,
            context: $context,
            idMaps: $this->idMaps,
            credits: $credits,
        );
    }

    /**
     * @param  array<int, int>  $bcUserIds
     */
    public function preload(array $bcUserIds): void
    {
        $this->people->preload($bcUserIds);
        $this->preloadMirroredIds(Facilitator::class, CustomFieldEnum::BRUSHCRAZY_USER_ID, $bcUserIds);
        $this->preloadMirroredFields(Facilitator::class, CustomFieldEnum::BRUSHCRAZY_USER_ID);
    }

    /**
     * Reads everything one user needs from the source. Null when the user is not staff of this
     * studio and never was here, so a pointer delivered to every studio only writes where it belongs.
     *
     * A user who stopped being staff because their only employment row was soft-deleted no longer
     * matches staffOfStudio(), so their Facilitator is deactivated here — nothing else would reach it.
     */
    public function fromUserId(Client $client, int $bcUserId): ?Facilitator
    {
        $bcStudioId = $this->context->bcStudioId;

        $row = PullStaffFromBrushCrazyAction::staffOfStudio($client, $bcStudioId)
            ->where('users.id', $bcUserId)
            ->first();

        if ($row === null) {
            return $this->deactivate($bcUserId);
        }

        $employments = PullStaffFromBrushCrazyAction::employmentsOf($client, $bcStudioId, [$bcUserId]);
        $owners = PullStaffFromBrushCrazyAction::ownersOf($client, $bcStudioId, [$bcUserId]);

        return $this->fromRow(
            $row,
            $employments[$bcUserId] ?? [],
            isset($owners[$bcUserId])
        );
    }

    /**
     * @param  array<int, stdClass>  $employments  this user's `studio_employees` rows in this studio
     */
    public function fromRow(stdClass $userRow, array $employments, bool $isOwner): Facilitator
    {
        $mapped = StaffMapper::map($userRow, $employments, $isOwner);
        $people = $this->people->fromRow($userRow);

        $facilitator = $this->upsertFacilitator(
            (int) $userRow->id,
            $people,
            $mapped['is_deleted']
        );

        $this->writeMirroredFields($facilitator, $mapped['custom_fields']);

        return $facilitator->setRelation('people', $people);
    }

    /**
     * Facilitator has no SlugTrait and `slug` is NOT NULL, so it is set on insert. The slug carries
     * the source id so two staff sharing a name cannot collide on (slug, apps_id, companies_id), and
     * it is never rewritten after insert.
     */
    protected function upsertFacilitator(int $bcUserId, People $people, bool $isDeleted): Facilitator
    {
        $facilitator = $this->upsertMirroredById(
            Facilitator::class,
            CustomFieldEnum::BRUSHCRAZY_USER_ID,
            $bcUserId,
            ['people_id' => $people->getId(), 'is_deleted' => $isDeleted],
            [
                'uuid' => Str::uuid()->toString(),
                'users_id' => $this->user->getId(),
                'slug' => Str::slug($people->name . ' bc ' . $bcUserId),
            ],
        );

        // saveQuietly() skips the observer that would drop the GraphQL cache.
        if ($facilitator->wasChanged()) {
            $facilitator->clearLightHouseCache(withKanvasConfiguration: false);
        }

        return $facilitator;
    }

    /**
     * Marked inactive rather than deleted: the classes this person taught must keep showing them.
     */
    public function deactivate(int $bcUserId): ?Facilitator
    {
        $facilitatorId = $this->mirroredId(Facilitator::class, CustomFieldEnum::BRUSHCRAZY_USER_ID, $bcUserId);

        if ($facilitatorId === null) {
            return null;
        }

        $facilitator = Facilitator::withTrashed()
            ->where('id', $facilitatorId)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->context->companyId())
            ->first();

        $facilitator?->set(CustomFieldEnum::BRUSHCRAZY_STAFF_ACTIVE->value, false);

        return $facilitator;
    }
}
