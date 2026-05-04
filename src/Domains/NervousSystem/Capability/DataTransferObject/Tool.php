<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\DataTransferObject;

use Baka\Contracts\AppInterface;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool as ToolModel;
use Spatie\LaravelData\Data;

class Tool extends Data
{
    /**
     * @param  array<int, string>  $frameworks  values from CapabilityFrameworkEnum
     * @param  array<string, mixed>|null  $inputSchema   JSONSchema
     * @param  array<string, mixed>|null  $outputSchema  JSONSchema
     * @param  array<int, string>|null  $requiresPermission  Bouncer abilities
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly string $name,
        public readonly array $frameworks,
        public readonly ToolTypeEnum $toolType = ToolTypeEnum::SYSTEM,
        public readonly ?string $description = null,
        public readonly ?string $handler = null,
        public readonly ?array $inputSchema = null,
        public readonly ?array $outputSchema = null,
        public readonly ?array $requiresPermission = null,
        public readonly string $version = '1.0.0',
        public readonly bool $isActive = true,
    ) {
    }

    public static function fromMultiple(AppInterface $app, array $data): self
    {
        return new self(
            app: $app,
            name: (string) $data['name'],
            frameworks: array_map('strval', (array) $data['frameworks']),
            toolType: isset($data['tool_type'])
                ? ToolTypeEnum::from((string) $data['tool_type'])
                : ToolTypeEnum::SYSTEM,
            description: $data['description'] ?? null,
            handler: $data['handler'] ?? null,
            inputSchema: $data['input_schema'] ?? null,
            outputSchema: $data['output_schema'] ?? null,
            requiresPermission: $data['requires_permission'] ?? null,
            version: (string) ($data['version'] ?? '1.0.0'),
            isActive: (bool) ($data['is_active'] ?? true),
        );
    }

    /**
     * Build a Tool DTO for an update mutation — overlays the input array
     * onto the existing model's values so partial updates work.
     */
    public static function forUpdate(ToolModel $tool, AppInterface $app, array $data): self
    {
        return new self(
            app: $app,
            name: (string) ($data['name'] ?? $tool->name),
            frameworks: isset($data['frameworks'])
                ? array_map('strval', (array) $data['frameworks'])
                : $tool->frameworks,
            toolType: isset($data['tool_type'])
                ? ToolTypeEnum::from((string) $data['tool_type'])
                : ToolTypeEnum::from($tool->tool_type),
            description: $data['description'] ?? $tool->description,
            handler: $data['handler'] ?? $tool->handler,
            inputSchema: $data['input_schema'] ?? $tool->input_schema,
            outputSchema: $data['output_schema'] ?? $tool->output_schema,
            requiresPermission: $data['requires_permission'] ?? $tool->requires_permission,
            version: (string) ($data['version'] ?? $tool->version),
            isActive: (bool) ($data['is_active'] ?? $tool->is_active),
        );
    }
}
