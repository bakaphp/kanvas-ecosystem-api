<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Event Configuration', category: 'crm')]
class EventConfigurationTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'get_event_configuration',
            description: 'List the company-scoped Event catalog IDs required by create_calendar_event: themes, theme areas, '
                . 'statuses, types, classes, and categories. Call this before creating an appointment, choose the most '
                . 'appropriate records, and pass their IDs unchanged. Never invent an Event configuration ID.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The lead ID used to resolve the correct app and company catalogs.',
                required: true,
            ),
        ];
    }

    public function __invoke(int $lead_id): array
    {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $scope = static fn ($query) => $query
            ->where('apps_id', $lead->apps_id)
            ->where('companies_id', $lead->companies_id)
            ->where('is_deleted', 0)
            ->orderBy('name');

        $mapLookup = static fn ($models): array => $models
            ->map(static fn ($model): array => [
                'id' => $model->getId(),
                'name' => $model->name,
                'is_default' => (bool) ($model->is_default ?? false),
            ])
            ->all();

        $themes = $mapLookup($scope(Theme::query())->get());
        $themeAreas = $mapLookup($scope(ThemeArea::query())->get());
        $statuses = $mapLookup($scope(EventStatus::query())->get());
        $types = $mapLookup($scope(EventType::query())->get());
        $classes = $mapLookup($scope(EventClass::query())->get());
        $categories = $scope(EventCategory::query())
            ->with(['eventType', 'eventClass'])
            ->get()
            ->map(static fn (EventCategory $category): array => [
                'id' => $category->getId(),
                'name' => $category->name,
                'is_default' => (bool) ($category->is_default ?? false),
                'event_type_id' => $category->event_type_id,
                'event_type_name' => $category->eventType?->name,
                'event_class_id' => $category->event_class_id,
                'event_class_name' => $category->eventClass?->name,
            ])
            ->all();

        $defaults = [
            'theme_id' => $this->defaultId($themes),
            'theme_area_id' => $this->defaultId($themeAreas),
            'status_id' => $this->defaultId($statuses),
            'type_id' => $this->defaultId($types),
            'class_id' => $this->defaultId($classes),
            'category_id' => $this->defaultId($categories),
        ];
        $defaultsComplete = ! in_array(null, $defaults, true);

        return [
            'status' => 'success',
            'lead_id' => $lead_id,
            'event_configuration' => [
                'themes' => $themes,
                'theme_areas' => $themeAreas,
                'statuses' => $statuses,
                'types' => $types,
                'classes' => $classes,
                'categories' => $categories,
                'defaults' => $defaults,
            ],
            'complete' => $themes !== []
                && $themeAreas !== []
                && $statuses !== []
                && $types !== []
                && $classes !== []
                && $categories !== [],
            'defaults_complete' => $defaultsComplete,
            'instruction' => $defaultsComplete
                ? 'Pass every ID from event_configuration.defaults unchanged to create_calendar_event.'
                : 'One or more defaults are missing. Select one ID from every catalog and ensure the category event_type_id '
                    . 'and event_class_id match the selected type_id and class_id.',
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function defaultId(array $items): ?int
    {
        foreach ($items as $item) {
            if ($item['is_default'] === true) {
                return (int) $item['id'];
            }
        }

        return null;
    }
}
