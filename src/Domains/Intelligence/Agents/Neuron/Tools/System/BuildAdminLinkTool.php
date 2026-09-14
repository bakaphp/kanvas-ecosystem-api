<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\AdminLinks\Services\AdminLinkRecordResolver;
use Kanvas\AdminLinks\Services\AdminLinkService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Turns a record the agent is already talking about into a link a human can click.
 *
 * The URL is assembled here rather than described to the model because the admin
 * uses four different identifier conventions across its routes — a model composing
 * the string by hand produces plausible dead links.
 */
#[AgentTool(name: 'Build Admin Link', category: 'ecosystem')]
class BuildAdminLinkTool extends Tool implements HasRunKey
{
    use HasKanvasContext;

    // Per-item by nature: a status report that links twelve projects calls this twelve times, and the
    // default per-name budget of 10 would abort the whole turn on the eleventh.
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'build_admin_link',
            description: 'Build a clickable Kanvas Admin link to a record or list screen. Use it whenever you '
                . 'mention a record a person might want to open — a project, a lead, an order, an agent — so they '
                . 'get a link instead of an id. Pass whatever id you already have (numeric id, uuid or slug); the '
                . 'tool looks the record up and works out the right one, so you never need to fetch another id first. '
                . 'Omit it for the list screen. '
                . 'Nervous System plans and tasks have no admin screen of their own: link to their project with '
                . 'section "agent_project" instead. Returns requires_company and section_permission — mention them '
                . 'to the person when true, because the link only opens if they have that company selected and '
                . 'that section enabled.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'section',
                type: PropertyType::STRING,
                description: 'Which admin screen to link to.',
                required: true,
                enum: AdminLinkSectionEnum::aliases()
            ),
            new ToolProperty(
                name: 'id',
                type: PropertyType::STRING,
                description: 'The record identifier you already have — numeric id, uuid or slug all work, the tool resolves the record and picks the form the route needs. Omit to link to the list screen instead of one record.',
                required: false
            ),
            new ToolProperty(
                name: 'tab',
                type: PropertyType::STRING,
                description: 'Optional tab on a detail screen, e.g. "history" on a lead, "nervous-system" or "tools" on an agent.',
                required: false
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $section, ?string $id = null, ?string $tab = null): array
    {
        if (! $this->hasTenantContext()) {
            return [
                'status' => 'error',
                'message' => 'No app in scope, a link cannot be built for this turn.',
            ];
        }

        $linkSection = AdminLinkSectionEnum::tryFromAlias($section);

        if ($linkSection === null) {
            return [
                'status' => 'error',
                'message' => 'Unknown section "' . $section . '". Pick one of the listed section values.',
            ];
        }

        $identifier = $id !== null && trim($id) !== '' ? trim($id) : null;
        $tab = $tab !== null && trim($tab) !== '' ? trim($tab) : null;
        $query = $tab !== null ? ['tab' => $tab] : [];

        // Resolve the record before building. Callers hold whatever id their last tool returned — every
        // lead tool hands back the numeric id while the leads route keys on the uuid — so building
        // straight from the input rejects a perfectly valid record and the model invents a placeholder.
        $resolver = new AdminLinkRecordResolver();
        $record = $identifier !== null
            ? $resolver->resolve(
                $linkSection,
                $identifier,
                $this->app,
                $this->company
            )
            : null;

        if ($record !== null && method_exists($record, 'adminLinkMeta')) {
            $meta = $record->adminLinkMeta($query);
        } elseif ($identifier !== null && $resolver->supports($linkSection)) {
            return [
                'status' => 'error',
                'message' => 'No ' . $linkSection->alias() . ' with identifier "' . $identifier
                    . '" exists for this company. Do not retry with the same id — look the record up first, or link to the list screen by omitting the id.',
            ];
        } else {
            try {
                $meta = new AdminLinkService()->meta(
                    $this->app,
                    $linkSection,
                    $identifier,
                    $query
                );
            } catch (ValidationException $e) {
                return [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        if ($meta->url === null) {
            return [
                'status' => 'error',
                'message' => 'This app has no admin URL configured, so no link can be built. Report it rather than guessing one.',
            ];
        }

        return [
            'status' => 'success',
            'url' => $meta->url,
            'requires_company' => $meta->requiresCompany,
            'section_permission' => $meta->sectionPermission,
        ];
    }
}
