<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Mcp;

use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DecodesJsonObjectParam;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
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
    public function decodeArguments(array $schema, array $arguments): array
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
                properties: $this->properties($schema),
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
            return is_array($value) ? $this->decodeArguments($schema, $value) : $value;
        }

        return is_string($value) ? $this->decodeJsonObjectParam($value) : $value;
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
     * @param array<string, mixed> $schema
     * @return array<array-key, array<string, mixed>>
     */
    private function declared(array $schema): array
    {
        $properties = $schema['properties'] ?? [];

        return is_array($properties) ? array_filter($properties, 'is_array') : [];
    }
}
