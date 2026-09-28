<?php

declare(strict_types=1);

namespace Tests\Traits;

/**
 * Checks the schema a provider mapper emits, not the properties it was built from. Gemini rejects the
 * whole request — every tool in the turn — for one property outside its supported subset.
 */
trait ValidatesGeminiSchema
{
    /**
     * Gemini accepts only this OpenAPI subset. An unlisted keyword is rejected outright,
     * so drift in NeuronAI's schema generation surfaces here rather than in production.
     */
    private const array GEMINI_ALLOWED_KEYWORDS = [
        'type',
        'format',
        'title',
        'description',
        'nullable',
        'enum',
        'items',
        'minItems',
        'maxItems',
        'properties',
        'required',
        'anyOf',
        'propertyOrdering',
    ];

    private const array VALID_TYPES = ['string', 'integer', 'number', 'boolean', 'array', 'object'];

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function schemaViolations(string $path, array $schema, bool $isRoot = true): array
    {
        $violations = [];
        $type = $schema['type'] ?? null;

        if (! in_array($type, self::VALID_TYPES, true)) {
            $violations[] = "{$path}: type '" . var_export($type, true) . "' is not a valid JSON-schema type.";
        }

        if ($type === 'array' && ! isset($schema['items'])) {
            $violations[] = "{$path}: array declared without an `items` schema.";
        }

        // A root parameter object with no properties is the legitimate no-argument case;
        // a nested one describes nothing the model can fill in.
        if ($type === 'object' && ! $isRoot && ! isset($schema['properties'])) {
            $violations[] = "{$path}: object declared without `properties`.";
        }

        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, self::GEMINI_ALLOWED_KEYWORDS, true)) {
                $violations[] = "{$path}: keyword `{$keyword}` is outside Gemini's supported OpenAPI subset.";
            }
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ($schema['required'] ?? [] as $required) {
            if (! array_key_exists($required, $properties)) {
                $violations[] = "{$path}: `{$required}` is required but not declared in `properties`.";
            }
        }

        foreach ($properties as $name => $child) {
            if (is_array($child)) {
                $violations = [...$violations, ...$this->schemaViolations("{$path}.{$name}", $child, false)];
            }
        }

        if (is_array($schema['items'] ?? null)) {
            $violations = [...$violations, ...$this->schemaViolations("{$path}[]", $schema['items'], false)];
        }

        return $violations;
    }
}
