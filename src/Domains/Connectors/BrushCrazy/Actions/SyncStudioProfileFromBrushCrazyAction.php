<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Concerns\SyncsAttachedPhotos;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\PaintingMapper;
use Kanvas\Connectors\BrushCrazy\Support\Money;
use Kanvas\Connectors\BrushCrazy\Support\PaintingRelations;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;

/**
 * What the public site shows for a studio, copied onto its company: contact and address columns,
 * and custom fields for the rest (hours, social links, shop content, homepage slides, add-on
 * prices). Photos go on the company as files: `photo` (the studio), `content-photo` and
 * `slide-{id}`.
 *
 * Left out on purpose: `billing`, `ein`, `aps_*`, `bridge_url` and `cash_drawer` are payment and
 * back-office credentials the public site never reads.
 */
class SyncStudioProfileFromBrushCrazyAction
{
    use SyncsAttachedPhotos;

    public const string STUDIO_MORPH = 'studio';

    public const string CONTENT_MORPH = 'studio-content';

    public const string SLIDE_MORPH = 'studio-slide';

    public const string POS_SETTING_MORPH = 'pos-setting';

    public const array MORPHS = [self::STUDIO_MORPH, self::CONTENT_MORPH, self::SLIDE_MORPH, self::POS_SETTING_MORPH];

    public const string CONTENT_PHOTO_FIELD = 'content-photo';

    public function __construct(
        protected AppInterface $app,
        protected StudioContext $context,
        protected ?Client $client = null,
    ) {
    }

    /**
     * Every pointer re-reads the whole profile: it is a handful of rows per studio.
     */
    public function execute(): bool
    {
        $studioId = $this->context->bcStudioId;
        $studio = $this->client()->table('studios')->where('id', $studioId)->first();

        if ($studio === null) {
            return false;
        }

        $content = $this->client()->table('studio_contents')
            ->where('studio_id', $studioId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $slides = $this->client()->table('studio_slides')
            ->where('studio_id', $studioId)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->get()
            ->all();

        $this->syncRows(
            $studio,
            $content,
            $slides,
            $this->client()->table('pos_settings')->whereNull('deleted_at')->orderBy('id')->get()->all()
        );

        $this->syncPhotos($this->company(), $this->photos($studio, $content, $slides));

        return true;
    }

    /**
     * @param  array<int, object>  $slides
     * @param  array<int, object>  $posSettings
     */
    public function syncRows(
        object $studio,
        ?object $content,
        array $slides,
        array $posSettings
    ): void {
        $company = $this->company();

        $postalCode = Str::trimToNull($studio->postal_code ?? null);

        // The site formats every class time in `timezone`; CreateCompaniesAction never writes it.
        $company->forceFill(array_filter([
            'email' => Str::trimToNull(self::firstLine($studio->email ?? null)),
            'phone' => Str::trimToNull($studio->phone_number ?? null),
            'address' => Str::trimToNull(self::streetAddress($studio)),
            'address_2' => Str::trimToNull($studio->suite ?? null),
            'city' => Str::trimToNull($studio->locality ?? null),
            'state' => Str::trimToNull($studio->administrative_area_level_1 ?? null),
            'zip' => $postalCode,
            'zipcode' => $postalCode,
            'country_code' => Str::trimToNull($studio->country_code ?? null),
            'timezone' => Str::trimToNull($this->context->timezone),
        ], fn ($value) => $value !== null));

        if ($company->isDirty()) {
            $company->saveOrFail();
        }

        $this->setField(CustomFieldEnum::BRUSHCRAZY_STUDIO_PROFILE, [
            'community' => $studio->community ?? null,
            'contact' => $studio->contact ?? null,
            'email' => $studio->email ?? null,
            'phone_number' => $studio->phone_number ?? null,
            'fax_number' => $studio->fax_number ?? null,
            'street_number' => $studio->street_number ?? null,
            'route' => $studio->route ?? null,
            'suite' => $studio->suite ?? null,
            'locality' => $studio->locality ?? null,
            'administrative_area_level_1' => $studio->administrative_area_level_1 ?? null,
            'postal_code' => $studio->postal_code ?? null,
            'country_code' => $studio->country_code ?? null,
            'operating_hours' => Str::jsonToArray($studio->operating_hours ?? null),
            'first_business_day' => $studio->first_business_day ?? null,
            'liquor_license' => (bool) ($studio->liquor_license ?? false),
            'byob' => (bool) ($studio->byob ?? false),
            'creative_juices' => (bool) ($studio->creative_juices ?? false),
            'description' => $studio->description ?? null,
        ]);
        // The two the public site reads, so they are written public (custom_fields only returns
        // those). The rest of the profile keeps the contact person and fax private.
        $this->setField(
            CustomFieldEnum::LEGACY_LOCATION_SLUG,
            self::legacySlug($studio),
            isPublic: true
        );
        $this->setField(
            CustomFieldEnum::OPERATING_HOURS,
            Str::jsonToArray($studio->operating_hours ?? null),
            isPublic: true
        );
        $this->setField(CustomFieldEnum::BRUSHCRAZY_STUDIO_SETTINGS, Str::jsonToArray($studio->settings ?? null));
        $this->setField(CustomFieldEnum::BRUSHCRAZY_STUDIO_SALES_TAX, Str::jsonToArray($studio->sales_tax ?? null));
        $this->setField(CustomFieldEnum::BRUSHCRAZY_STUDIO_OCCUPANCY, $studio->occupancy !== null ? (int) $studio->occupancy : null);
        $this->setField(CustomFieldEnum::BRUSHCRAZY_STUDIO_LAT, $studio->lat !== null ? (float) $studio->lat : null);
        $this->setField(CustomFieldEnum::BRUSHCRAZY_STUDIO_LNG, $studio->lng !== null ? (float) $studio->lng : null);
        $this->setField(CustomFieldEnum::BRUSHCRAZY_STUDIO_SOCIAL, array_filter([
            'facebook' => Str::trimToNull($studio->facebook ?? null),
            'twitter' => Str::trimToNull($studio->twitter ?? null),
            'instagram' => Str::trimToNull($studio->instagram ?? null),
            'pinterest' => Str::trimToNull($studio->pinterest ?? null),
            'youtube' => Str::trimToNull($studio->youtube ?? null),
            'snapchat' => Str::trimToNull($studio->snapchat ?? null),
        ]));

        $this->setField(CustomFieldEnum::BRUSHCRAZY_STUDIO_CONTENT, $content === null ? null : [
            'availability_slots' => Str::jsonToArray($content->availability_slots ?? null),
            'brush_rental' => Str::jsonToArray($content->brush_rental ?? null),
            'delivery_fees' => Str::jsonToArray($content->delivery_fees ?? null),
            'delivery_hours' => Str::jsonToArray($content->delivery_hours ?? null),
        ]);

        $this->setField(CustomFieldEnum::BRUSHCRAZY_STUDIO_SLIDES, array_map(fn (object $slide) => [
            'id' => (int) $slide->id,
            'title' => $slide->title ?? null,
            'sort_order' => (int) $slide->sort_order,
            'photo_field' => self::slideField((int) $slide->id),
        ], $slides));

        $this->setField(CustomFieldEnum::BRUSHCRAZY_POS_SETTINGS, array_map(fn (object $setting) => [
            'name' => (string) $setting->name,
            'type' => (string) $setting->type,
            'amount_cents' => $setting->amount !== null ? Money::fromRaw($setting->amount)->cents : null,
            'value' => $setting->value ?? null,
        ], $posSettings));
    }

    public static function slideField(int $bcSlideId): string
    {
        return 'slide-' . $bcSlideId;
    }

    /**
     * Without a media base url the company's photos are left as they are.
     *
     * @param  array<int, object>  $slides
     * @return array<string, array{media_id: int, url: string}>
     */
    protected function photos(object $studio, ?object $content, array $slides): array
    {
        $mediaBaseUrl = self::mediaBaseUrlOf($this->app);

        if ($mediaBaseUrl === null) {
            return $this->attachedPhotos($this->company());
        }

        $owners = [
            self::STUDIO_MORPH => [(int) $studio->id],
            self::CONTENT_MORPH => $content !== null ? [(int) $content->id] : [],
            self::SLIDE_MORPH => array_map(fn (object $slide) => (int) $slide->id, $slides),
        ];

        $photos = PaintingMapper::photos(
            PaintingRelations::mediaQuery($this->client(), $owners)->get()->all(),
            $mediaBaseUrl
        );

        $files = [];

        foreach ($photos as $key => $photo) {
            [$morph, $id] = explode(':', $key, 2);

            $field = match ($morph) {
                self::STUDIO_MORPH => PaintingMapper::PHOTO_FIELD,
                self::CONTENT_MORPH => self::CONTENT_PHOTO_FIELD,
                default => self::slideField((int) $id),
            };

            $files[$field] = $photo;
        }

        return $files;
    }

    /**
     * A public field is always written: the equality shortcut would leave a row first stored
     * private (is_public is part of the upsert) invisible to the site for good.
     */
    protected function setField(CustomFieldEnum $field, mixed $value, bool $isPublic = false): void
    {
        $company = $this->company();

        if (! $isPublic && $company->get($field->value) == $value) {
            return;
        }

        $company->disableWorkflows();
        $company->set($field->value, $value, $isPublic);
    }

    /**
     * BrushCrazy's own `Studio::getSlugAttribute()`, the segment of every brushcrazy.com/locations
     * URL: locality-state, plus the community when the studio has one. Null for Corporate, which
     * has no locality and no public page.
     */
    public static function legacySlug(object $studio): ?string
    {
        $locality = Str::slug((string) ($studio->locality ?? ''));

        if ($locality === '') {
            return null;
        }

        return implode('-', array_filter([
            $locality,
            Str::slug((string) ($studio->administrative_area_level_1 ?? ''), ''),
            Str::slug((string) ($studio->community ?? ''), ''),
        ], fn (string $part) => $part !== ''));
    }

    protected function company(): Companies
    {
        return $this->context->company;
    }

    protected static function streetAddress(object $studio): string
    {
        return trim(($studio->street_number ?? '') . ' ' . ($studio->route ?? ''));
    }

    /**
     * `studios.email` is a text column that sometimes holds a list; the company column takes one.
     */
    protected static function firstLine(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_split('/[\s,;]+/', trim($value))[0] ?? null;
    }

    protected function client(): Client
    {
        return $this->client ??= new Client($this->app);
    }
}
