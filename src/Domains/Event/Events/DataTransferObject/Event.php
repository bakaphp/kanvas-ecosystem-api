<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class Event extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly UserInterface $user,
        public readonly CompanyInterface $company,
        public readonly string $name,
        public readonly Theme $theme,
        public readonly ThemeArea $themeArea,
        public readonly EventStatus $status,
        public readonly EventType $type,
        public readonly EventCategory $category,
        public readonly EventClass $class,
        #[DataCollectionOf(EventDate::class)]
        public readonly DataCollection $dates,
        public readonly ?string $description = null,
        public readonly ?string $slug = null,
    ) {
    }

    public static function fromMultiple(AppInterface $app, UserInterface $user, CompanyInterface $company, array $data): self
    {
        return new self(
            app: $app,
            user: $user,
            company: $company,
            name: $data['name'],
            theme: self::getEntityByIdOrDefault(Theme::class, $app, $company, $data['theme_id'] ?? null),
            themeArea: self::getEntityByIdOrDefault(ThemeArea::class, $app, $company, $data['theme_area_id'] ?? null),
            status: self::getEntityByIdOrDefault(EventStatus::class, $app, $company, $data['status_id'] ?? null),
            type: EventType::getByIdFromCompanyApp($data['type_id'], $company, $app),
            category: EventCategory::getByIdFromCompanyApp($data['category_id'], $company, $app),
            class: self::getEntityByIdOrDefault(EventClass::class, $app, $company, $data['class_id'] ?? null),
            dates: EventDate::collect($data['dates'] ?? [], DataCollection::class),
            description: $data['description'] ?? null,
            slug: $data['slug'] ?? null,
        );
    }

    protected static function getEntityByIdOrDefault(
        string $entityClass,
        $app,
        $company,
        ?int $idField,
        string $defaultCondition = 'is_default',
        int $defaultValue = 1
    ): Model {
        return isset($idField)
            ? $entityClass::fromApp($app)->fromCompany($company)->where('id', $idField)->firstOrFail()
            : $entityClass::fromApp($app)->fromCompany($company)->where($defaultCondition, $defaultValue)->firstOrFail();
    }
}
