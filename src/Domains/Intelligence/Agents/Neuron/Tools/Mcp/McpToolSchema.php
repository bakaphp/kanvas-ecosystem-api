<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Mcp;

use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DecodesJsonObjectParam;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use stdClass;
use Throwable;

/**
 * Turns an MCP `inputSchema` into Neuron properties every provider accepts.
 *
 * NeuronAI's own mapping flattens nested schemas — an array of arrays loses its inner `items`, an object
 * its `properties` — and Gemini rejects the whole request, every tool in the turn, for either. A free-form
 * object (no declared properties) has no Gemini shape at all, so it travels as a JSON string and is
 * decoded back before the call reaches the server.
 */
final class McpToolSchema
{
    use DecodesJsonObjectParam;

    private const string JSON_OBJECT_HINT = 'Pass it as a JSON-encoded object.';

    /**
     * @param array<string, mixed> $schema
     * @return list<ToolPropertyInterface>
     */
    public function properties(array $schema): array
    {
        return $this->propertiesOf($this->inlineRefs($schema));
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function decodeArguments(array $schema, array $arguments): array
    {
        return $this->decodeObject($this->inlineRefs($schema), $arguments);
    }

    /**
     * An unresolved `$ref` has no type and would reach the model as a string — Google Calendar's `attendees`
     * as bare emails, which the server rejects (KANVAS-ECOSYSTEM-6EC). A reference into its own ancestry (a
     * recursive filter) has no finite shape, so it keeps only its type there and a nested object travels as JSON.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function inlineRefs(array $schema): array
    {
        $root = $schema;
        unset($schema['$defs'], $schema['definitions']);

        return $this->inline($schema, $root, []);
    }

    /**
     * @param array<string, mixed> $root
     * @param list<string> $trail
     */
    private function inline(mixed $node, array $root, array $trail): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        $ref = $node['$ref'] ?? null;

        if (is_string($ref)) {
            unset($node['$ref']);
            $target = $this->pointer($root, $ref);

            if ($target === null) {
                return $node;
            }

            // Siblings of a `$ref` (usually its `description`) describe this use, so they win over the target's.
            return in_array($ref, $trail, true)
                ? [...array_intersect_key($target, ['type' => true, 'description' => true]), ...$node]
                : $this->inline([...$target, ...$node], $root, [...$trail, $ref]);
        }

        foreach ($node as $key => $child) {
            $node[$key] = $this->inline($child, $root, $trail);
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $root
     * @return array<string, mixed>|null
     */
    private function pointer(array $root, string $ref): ?array
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }

        $node = $root;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));

            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return is_array($node) ? $node : null;
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<ToolPropertyInterface>
     */
    private function propertiesOf(array $schema): array
    {
        $required = (array) ($schema['required'] ?? []);
        $properties = [];

        foreach ($this->declared($schema) as $name => $child) {
            $properties[] = $this->property((string) $name, $child, in_array($name, $required, true));
        }

        return $properties;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function decodeObject(array $schema, array $arguments): array
    {
        foreach ($this->declared($schema) as $name => $child) {
            if (array_key_exists($name, $arguments)) {
                $arguments[$name] = $this->decode($child, $arguments[$name]);
            }
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function property(string $name, array $schema, bool $required): ToolPropertyInterface
    {
        $description = is_string($schema['description'] ?? null) ? $schema['description'] : null;
        $type = $this->typeOf($schema);

        if ($type === PropertyType::ARRAY) {
            return new ArrayProperty(
                name: $name,
                description: $description,
                required: $required,
                items: $this->property('items', $this->items($schema), false),
            );
        }

        if ($type === PropertyType::OBJECT && $this->declared($schema) !== []) {
            return new ObjectProperty(
                name: $name,
                description: $description,
                required: $required,
                properties: $this->propertiesOf($schema),
            );
        }

        if ($type === PropertyType::OBJECT) {
            return new ToolProperty(
                name: $name,
                type: PropertyType::STRING,
                description: trim($description . ' ' . self::JSON_OBJECT_HINT),
                required: $required,
            );
        }

        $enum = (array) ($schema['enum'] ?? []);

        return new ToolProperty(
            name: $name,
            type: $type,
            description: $description,
            required: $required,
            // Gemini takes string enums only; a numeric or mixed one is better dropped than rejected.
            enum: $type === PropertyType::STRING && $enum === array_filter($enum, 'is_string') ? array_values($enum) : [],
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function decode(array $schema, mixed $value): mixed
    {
        $type = $this->typeOf($schema);

        if ($type === PropertyType::ARRAY && is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->decode($this->items($schema), $item), $value);
        }

        if ($type !== PropertyType::OBJECT) {
            return $value;
        }

        if ($this->declared($schema) !== []) {
            return is_array($value) ? $this->asJsonObject($this->decodeObject($schema, $value)) : $value;
        }

        return $this->asJsonObject(is_string($value) ? $this->decodeJsonObjectParam($value) : $value);
    }

    /**
     * PHP has one array type, so an object with no keys — `{}` the model sent, or a free-form map it left
     * empty — encodes back as `[]` and the server rejects it ("expected record, received array", Browserless
     * on every `commands[].params`). Only the empty case is ambiguous; anything with keys encodes as an
     * object already, and a list stays a list so a genuine shape error still reaches the model as one.
     */
    private function asJsonObject(mixed $value): mixed
    {
        return $value === [] ? new stdClass() : $value;
    }

    /**
     * An untyped schema (`anyOf`, `type: "null"`, …) falls back to a string rather than failing the tool.
     *
     * @param array<string, mixed> $schema
     */
    private function typeOf(array $schema): PropertyType
    {
        $type = $schema['type'] ?? null;

        if (is_string($type) || is_array($type)) {
            try {
                return PropertyType::fromSchema($type);
            } catch (Throwable) {
                return PropertyType::STRING;
            }
        }

        return match (true) {
            isset($schema['properties']) => PropertyType::OBJECT,
            isset($schema['items']) => PropertyType::ARRAY,
            default => PropertyType::STRING,
        };
    }

    /**
     * Google Sheets publishes `items: []` for a cell of any type; a tuple (`items` as a list) has no single
     * item shape either. Both become strings.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function items(array $schema): array
    {
        $items = $schema['items'] ?? [];

        return is_array($items) && ! array_is_list($items) ? $items : [];
    }

    /**
     * `readOnly` fields are the server's to fill (Calendar's `attendees[].self`); offering them only invites
     * the model to send something the server rejects.
     *
     * @param array<string, mixed> $schema
     * @return array<array-key, array<string, mixed>>
     */
    private function declared(array $schema): array
    {
        $properties = $schema['properties'] ?? [];

        return is_array($properties)
            ? array_filter($properties, fn (mixed $child): bool => is_array($child) && ($child['readOnly'] ?? false) !== true)
            : [];
    }
}
