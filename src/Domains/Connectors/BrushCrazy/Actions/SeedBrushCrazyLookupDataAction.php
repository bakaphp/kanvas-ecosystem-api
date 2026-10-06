<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Enums\EventClassEnum;
use Kanvas\Connectors\BrushCrazy\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;

/**
 * Seeds every lookup row the import needs before it can insert a single event.
 *
 * `events` carries six NOT-NULL foreign keys (theme, theme_area, status, type, class, category),
 * and production has 16,909 of 21,752 calendarables with no painting at all — 100% of workshops
 * and 97.6% of private events. So the Unassigned fallbacks are the main path, not an edge case:
 * without them the very first insert fails.
 */
class SeedBrushCrazyLookupDataAction
{
    public const UNASSIGNED = 'Unassigned';

    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function execute(): array
    {
        $types = $this->seedEventTypes();
        $classes = $this->seedEventClasses();

        return [
            'event_types' => count($types),
            'event_classes' => count($classes),
            'event_statuses' => $this->seedEventStatuses(),
            'event_categories' => $this->seedCategoryRoots($types, $classes),
            'participant_types' => $this->seedParticipantType(),
            'themes' => $this->seedUnassignedTheme(),
            'theme_areas' => $this->seedUnassignedThemeArea(),
        ];
    }

    /**
     * @return array<string, EventType>
     */
    protected function seedEventTypes(): array
    {
        $types = [];

        foreach (CalendarableTypeEnum::cases() as $morph) {
            $type = EventType::firstOrCreate(
                $this->scope(['name' => $morph->eventTypeName()]),
                ['users_id' => $this->user->getId()],
            );

            $type->set(CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY->value, 'type:' . $morph->value);
            $types[$morph->value] = $type;
        }

        return $types;
    }

    /**
     * @return array<string, EventClass>
     */
    protected function seedEventClasses(): array
    {
        $classes = [];

        foreach (EventClassEnum::cases() as $case) {
            $class = EventClass::firstOrCreate(
                $this->scope(['name' => $case->value]),
                ['users_id' => $this->user->getId(), 'is_default' => $case === EventClassEnum::PUBLIC],
            );

            $class->set(CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY->value, 'class:' . $case->value);
            $classes[$case->value] = $class;
        }

        return $classes;
    }

    /**
     * updateOrCreate rather than firstOrCreate: the transition graph is configuration, so a
     * re-run has to be able to correct it.
     */
    protected function seedEventStatuses(): int
    {
        foreach (EventStatusEnum::cases() as $case) {
            $status = EventStatus::updateOrCreate(
                $this->scope(['slug' => $case->value]),
                [
                    'users_id' => $this->user->getId(),
                    'name' => $case->label(),
                    'is_default' => $case->isDefault(),
                    'valid_transitions' => $case->validTransitions(),
                ],
            );

            $status->set(CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY->value, 'status:' . $case->value);
        }

        return count(EventStatusEnum::cases());
    }

    /**
     * One root category per morph. Leaves come from the source's free-text `type` column, which
     * production shows has only 7 distinct (morph, type) pairs — no long tail to worry about.
     *
     * @param  array<string, EventType>  $types
     * @param  array<string, EventClass>  $classes
     */
    protected function seedCategoryRoots(array $types, array $classes): int
    {
        $default = $classes[EventClassEnum::PUBLIC->value];

        foreach (CalendarableTypeEnum::cases() as $morph) {
            $category = EventCategory::firstOrCreate(
                $this->scope(['slug' => 'bc-cat-' . $morph->value]),
                [
                    'users_id' => $this->user->getId(),
                    'name' => $morph->eventTypeName(),
                    'event_type_id' => $types[$morph->value]->getId(),
                    'event_class_id' => $default->getId(),
                    'position' => 0,
                ],
            );

            $category->set(CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY->value, 'category:' . $morph->value);
        }

        return count(CalendarableTypeEnum::cases());
    }

    protected function seedParticipantType(): int
    {
        $type = ParticipantType::firstOrCreate(
            $this->scope(['name' => 'Attendee']),
            ['users_id' => $this->user->getId()],
        );

        $type->set(CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY->value, 'participant-type:attendee');

        return 1;
    }

    protected function seedUnassignedTheme(): int
    {
        $theme = Theme::firstOrCreate(
            $this->scope(['name' => self::UNASSIGNED]),
            ['users_id' => $this->user->getId(), 'is_default' => true],
        );

        $theme->set(CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY->value, 'theme:unassigned');

        return 1;
    }

    /**
     * Studios get their own ThemeArea when they are resolved; this one only catches rows whose
     * studio could not be mapped, so theme_area_id is never null.
     */
    protected function seedUnassignedThemeArea(): int
    {
        $area = ThemeArea::firstOrCreate(
            $this->scope(['name' => self::UNASSIGNED]),
            ['users_id' => $this->user->getId(), 'is_default' => true],
        );

        $area->set(CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY->value, 'theme-area:unassigned');

        return 1;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function scope(array $attributes): array
    {
        return $attributes + [
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
        ];
    }
}
