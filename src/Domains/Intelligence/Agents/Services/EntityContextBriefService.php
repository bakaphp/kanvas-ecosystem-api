<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Agents\Contracts\ProvidesAgentContext;

/**
 * Produces the deterministic brief an agent is grounded on when it is dropped
 * onto a record (a lead, order, invoice, ...). Models that implement
 * ProvidesAgentContext control their own brief; everything else gets a generic
 * attribute-based one so any entity works out of the box.
 */
class EntityContextBriefService
{
    /** @var list<string> */
    private const array COMMON_ATTRIBUTES = [
        'name',
        'title',
        'firstname',
        'lastname',
        'email',
        'phone',
        'status',
        'stage',
    ];

    /**
     * @return array<string, mixed>
     */
    public function brief(Model $entity): array
    {
        if ($entity instanceof ProvidesAgentContext) {
            return $entity->agentContextBrief();
        }

        return $this->genericBrief($entity);
    }

    /**
     * A single-line brief for injecting into the system prompt, e.g.
     * "People #4821 — firstname: Ana, email: ana@acme.com".
     */
    public function renderText(Model $entity): string
    {
        $brief = $this->brief($entity);
        $type = $brief['type'] ?? class_basename($entity);
        $id = $brief['id'] ?? $entity->getKey();
        unset($brief['type'], $brief['id']);

        $pairs = [];
        foreach ($brief as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $pairs[] = "{$key}: {$value}";
            }
        }

        $suffix = $pairs === [] ? '' : ' — ' . implode(', ', $pairs);

        return "{$type} #{$id}{$suffix}";
    }

    /**
     * @return array<string, mixed>
     */
    private function genericBrief(Model $entity): array
    {
        $brief = [
            'type' => class_basename($entity),
            'id' => $entity->getKey(),
        ];

        foreach (self::COMMON_ATTRIBUTES as $attribute) {
            $value = $entity->getAttribute($attribute);
            if (is_scalar($value) && $value !== '') {
                $brief[$attribute] = $value;
            }
        }

        return $brief;
    }
}
