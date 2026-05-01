<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\DataTransferObject;

use Baka\Contracts\AppInterface;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Spatie\LaravelData\Data;

/**
 * Tool catalog payload. Pass an app to scope the tool.
 */
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
}
