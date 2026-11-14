<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Concerns\SyncsAttachedPhotos;
use Kanvas\Connectors\BrushCrazy\Concerns\UpsertsMirroredRows;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\PaintingMapper;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\PaintingRelations;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Themes\Models\Theme;
use stdClass;

/**
 * Writes one BrushCrazy painting into one studio company as a Theme. The bulk import and the
 * mirror webhook both call it, so the two cannot drift.
 *
 * Paintings are global at the source but Themes are per company, so a painting used in two studios
 * becomes two Themes. BRUSHCRAZY_PAINTING_ID on each is what SyncCalendarableFromBrushCrazyAction
 * resolves `theme_id` by, and resolution here is by that id only, never by name.
 */
class SyncPaintingFromBrushCrazyAction
{
    use SyncsAttachedPhotos;
    use UpsertsMirroredRows;

    protected IdMapRepository $idMaps;

    protected PaintingRelations $relations;

    /** @var array<int, array<string, mixed>> */
    protected array $related = [];

    protected ?string $mediaBaseUrl;

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        ?PaintingRelations $relations = null,
        ?IdMapRepository $idMaps = null,
    ) {
        $this->relations = $relations ?? new PaintingRelations(new Client($app));
        $this->idMaps = $idMaps ?? new IdMapRepository();
        $this->mediaBaseUrl = self::mediaBaseUrlOf($app);
    }

    /**
     * The webhook receives `painting` and `painting-direction` pointers; both resolve to the
     * painting, because a step is stored inside its painting's Theme.
     */
    public static function paintingIdFor(Client $client, string $entityType, int $entityId): ?int
    {
        $paintingId = match ($entityType) {
            PaintingMapper::PAINTING_MORPH => $client->table('paintings')
                ->where('id', $entityId)
                ->value('id'),
            PaintingMapper::DIRECTION_MORPH => $client->table('painting_directions')
                ->where('id', $entityId)
                ->value('painting_id'),
            default => null,
        };

        return $paintingId !== null ? (int) $paintingId : null;
    }

    /**
     * @param  array<int, int>  $bcPaintingIds
     */
    public function preload(array $bcPaintingIds): void
    {
        $this->related = $this->relations->forPaintings($bcPaintingIds);
        $this->preloadMirroredIds(Theme::class, CustomFieldEnum::BRUSHCRAZY_PAINTING_ID, $bcPaintingIds);
        $this->preloadMirroredFields(Theme::class, CustomFieldEnum::BRUSHCRAZY_PAINTING_ID);
    }

    public function fromRow(stdClass $row): Theme
    {
        $bcPaintingId = (int) $row->id;
        $related = $this->related[$bcPaintingId] ?? $this->relations->forPaintings([$bcPaintingId])[$bcPaintingId];
        $mapped = PaintingMapper::map($row, $related, $this->mediaBaseUrl);

        $theme = $this->upsertTheme($bcPaintingId, $mapped['theme']);

        $this->writeMirroredFields($theme, $mapped['custom_fields']);

        // Without a base URL there is nothing to point at; reading that as "no photos" would
        // detach every photo a previous run attached.
        if ($this->mediaBaseUrl !== null) {
            $this->syncPhotos($theme, $mapped['files']);
        }

        return $theme;
    }

    /**
     * The Event-domain traits (AppsIdTrait, CompaniesIdTrait) only fill tenancy on `creating`, which
     * does not fire here; the source created_at is kept on insert. A painting deleted at the source
     * becomes a flagged Theme rather than being skipped, so historical events keep their theme.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function upsertTheme(int $bcPaintingId, array $attributes): Theme
    {
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        return $this->upsertMirroredById(
            Theme::class,
            CustomFieldEnum::BRUSHCRAZY_PAINTING_ID,
            $bcPaintingId,
            $attributes,
            ['users_id' => $this->user->getId()] + ($createdAt !== null ? ['created_at' => $createdAt] : []),
        );
    }
}
