<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Models;

use Baka\Casts\Json;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\DataTransferObject\SectionConfig;
use Kanvas\Models\BaseModel;

/**
 * One card on "What can we help you with?". Ships platform-global rows (apps_id = 0) on purpose:
 * onboarding creates the app that reads the catalog, so a brand-new app owns no rows yet. An app
 * row with the same slug overrides the global one for that app only.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property string $slug
 * @property string $label
 * @property string $title
 * @property string|null $description
 * @property string|null $icon
 * @property bool $is_available
 * @property int $display_order
 * @property array|null $config
 * @property bool $is_active
 */
class OnboardingSection extends BaseModel
{
    use UuidTrait;
    use SlugTrait;

    protected $table = 'onboarding_sections';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $section): void {
            $section->config()->validate($section);
        });
    }

    protected function casts(): array
    {
        return [
            'config' => Json::class,
            'is_available' => 'boolean',
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    public function scopeFromAppOrGlobal(Builder $query, mixed $app = null): Builder
    {
        $app = $app instanceof Apps ? $app : app(Apps::class);

        return $query->whereIn('apps_id', [0, $app->getId()]);
    }

    public function config(): SectionConfig
    {
        return SectionConfig::fromArray($this->config);
    }

    /**
     * @return list<string> requirement slugs declared by this row plus every active row of its app or app 0
     */
    public function declaredRequirementSlugs(): array
    {
        $slugs = array_map(
            fn (array $requirement): string => (string) ($requirement['slug'] ?? ''),
            $this->config()->requirements,
        );

        $siblings = static::query()
            ->whereIn('apps_id', [0, (int) $this->apps_id])
            ->where('is_active', true)
            ->notDeleted()
            ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->getKey()))
            ->get(['config']);

        foreach ($siblings as $sibling) {
            foreach ($sibling->config()->requirements as $requirement) {
                $slugs[] = (string) ($requirement['slug'] ?? '');
            }
        }

        return array_values(array_unique(array_filter($slugs)));
    }
}
