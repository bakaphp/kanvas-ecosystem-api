<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\DataTransferObject;

use Baka\Contracts\AppInterface;
use Kanvas\NervousSystem\Capability\Enums\SkillTypeEnum;
use Spatie\LaravelData\Data;

/**
 * Skill catalog payload. Pass an app to scope the skill to that app
 * (the catalog row stores `apps_id`); the special "global skill"
 * (apps_id=0) case is created via CreateGlobalSkillAction (TODO),
 * not this DTO.
 */
class Skill extends Data
{
    /**
     * @param  array<int, string>  $frameworks  values from CapabilityFrameworkEnum
     * @param  array<string, mixed>|null  $definition
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly string $name,
        public readonly array $frameworks,
        public readonly SkillTypeEnum $skillType = SkillTypeEnum::SYSTEM,
        public readonly ?string $description = null,
        public readonly ?string $handler = null,
        public readonly ?array $definition = null,
        public readonly string $version = '1.0.0',
        public readonly bool $isActive = true,
    ) {
    }
}
