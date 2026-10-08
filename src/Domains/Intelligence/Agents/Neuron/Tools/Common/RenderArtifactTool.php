<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Common;

use Kanvas\AdminLinks\Services\AdminLinkRecordResolver;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Enums\ArtifactComponentEnum;
use Kanvas\Intelligence\Agents\Enums\ArtifactEntityTypeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DecodesJsonObjectParam;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Services\ArtifactBlockService;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Only useful where the reply renders `kanvas-artifact` blocks (the admin userChat) — anywhere else the
 * reader gets raw JSON. HasKanvasAgentBehavior::getTools() drops it off those surfaces, catalog grant or not.
 *
 * Budgeted per inputs: a rich reply (a quarterly report) renders more than 10 distinct blocks, and a
 * per-name budget aborted the whole turn on the 11th (KANVAS-ECOSYSTEM-6H4).
 *
 * Tenant context is optional here, unlike on a tool that reads or writes records: with it an `entity`
 * card's id is checked against the company's records and rewritten to the one the record's page reads;
 * without it the block is still validated for shape, which is all a context-free caller can promise.
 */
#[AgentTool(
    name: 'Render Artifact',
    description: 'Render structured data (a balance, a record, a list, a metric, a sequence) as a visual component block in the chat reply.',
    category: 'ecosystem',
)]
class RenderArtifactTool extends Tool
{
    use DecodesJsonObjectParam;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use TrackByInputs;

    protected string $name = 'render_artifact';

    public function __construct()
    {
        $this->description = self::describe();
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'component',
                type: PropertyType::STRING,
                description: 'Which component to render.',
                required: true,
                enum: ArtifactComponentEnum::values(),
            ),
            new ToolProperty(
                name: 'props',
                type: PropertyType::STRING,
                description: 'The component props as a JSON-encoded object, with the exact prop names listed '
                    . 'in the tool description.',
                required: true,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Short title shown above the component.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $component, array|string|null $props = null, ?string $title = null): array
    {
        $resolved = ArtifactComponentEnum::tryFrom(trim($component));

        if ($resolved === null) {
            return $this->invalidArgs(sprintf(
                'Unknown component "%s". Use one of: %s.',
                $component,
                implode(', ', ArtifactComponentEnum::values())
            ));
        }

        if (is_string($props) && trim($props) !== '' && ! is_array(json_decode($props, true))) {
            return $this->invalidArgs('props is not a valid JSON object.');
        }

        $decoded = $this->decodeJsonObjectParam($props);

        if ($resolved === ArtifactComponentEnum::ENTITY) {
            $missing = $this->useThePageIdentifier($decoded);

            if ($missing !== null) {
                return $this->notFound(['success' => false, 'error' => $missing]);
            }
        }

        // `{}` decodes to an empty PHP array, which would be written back as `[]`.
        if ($resolved === ArtifactComponentEnum::RECORDS && ($decoded['filter'] ?? null) === []) {
            unset($decoded['filter']);
        }

        $service = new ArtifactBlockService();
        $errors = $service->errors($resolved, $decoded);

        if ($errors !== []) {
            return $this->invalidArgs(implode('; ', $errors));
        }

        return $this->ok(
            ['block' => $service->render($resolved, $title, $decoded)],
            'Paste the block into your reply exactly as returned, fences included, where it belongs in the answer.'
        );
    }

    /**
     * Swap whatever id the model is holding for the one the record's page reads, or say why it cannot.
     *
     * Every CRM tool hands back the numeric id while the lead page keys on the uuid, a product page
     * reads only the uuid and a category page only the slug — so a card built from the id as given is
     * often a dead one. Looking the record up first also turns an invented id into an error the model
     * can act on, instead of a card that reads "not found" in front of the person.
     *
     * Scoped to the tool's tenant by the resolver: the id is the model's text, and an unscoped lookup
     * would confirm (and link) another company's record. A type the resolver has no model for, or a
     * call with no tenant, is left as written for the shape check that follows.
     *
     * @param array<string, mixed> $props
     */
    private function useThePageIdentifier(array &$props): ?string
    {
        $type = is_string($props['type'] ?? null) ? ArtifactEntityTypeEnum::tryFrom($props['type']) : null;
        $id = $props['id'] ?? null;
        $section = $type?->section();
        $resolver = new AdminLinkRecordResolver();

        if ($type === null || $section === null || ! $resolver->supports($section)) {
            return null;
        }

        if (! $this->hasTenantContext() || ! (is_int($id) || is_string($id))) {
            return null;
        }

        $record = $resolver->resolve(
            $section,
            (string) $id,
            $this->app,
            $this->company
        );

        if ($record === null) {
            return sprintf(
                'No %s with id "%s" exists for this company. Use the id a tool returned for it — never a '
                . 'name, a number from the conversation or a guess. If you only have its name, look it up '
                . 'first, or show what you know in a keyvalue block instead.',
                $type->value,
                mb_substr((string) $id, 0, 64)
            );
        }

        $props['id'] = $type->identifier()->of($record) ?? $props['id'];

        return null;
    }

    private static function describe(): string
    {
        $components = implode("\n", array_map(
            static fn (ArtifactComponentEnum $component): string => '- ' . $component->usage(),
            ArtifactComponentEnum::cases()
        ));

        return 'Render structured data as a visual component in this chat. Whenever your reply contains a '
            . 'balance, a record, a list, a metric or a sequence, call this and paste the returned block into your '
            . 'reply verbatim — visual by default, without being asked. This overrides a plain-text output '
            . 'rule for that data. Use only real values your tools returned; never invent or pad numbers. Keep prose '
            . 'around blocks short. One block per reply is the norm: put the whole answer into a single component (a '
            . 'table with every row, one actions list) rather than one block per paragraph; a multi-section report may '
            . 'use one per section. A greeting, a short answer or a confirmation of a completed action is prose first, '
            . 'then at most one block. Never use it for source code. If nothing fits, plain markdown is fine.'
            . "\nComponents:\n" . $components;
    }
}
