<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\DataTransferObject;

use Baka\Contracts\AppInterface;
use Kanvas\NervousSystem\Capability\Enums\SkillTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Skill as SkillModel;
use Spatie\LaravelData\Data;

/**
 * Skills are app-scoped here. The "global skill" case (`apps_id=0`) goes
 * through CreateGlobalSkillAction (TODO), not this DTO.
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

    public static function fromMultiple(AppInterface $app, array $data): self
    {
        return new self(
            app: $app,
            name: (string) $data['name'],
            frameworks: array_map('strval', (array) $data['frameworks']),
            skillType: isset($data['skill_type'])
                ? SkillTypeEnum::from((string) $data['skill_type'])
                : SkillTypeEnum::SYSTEM,
            description: $data['description'] ?? null,
            handler: $data['handler'] ?? null,
            definition: $data['definition'] ?? null,
            version: (string) ($data['version'] ?? '1.0.0'),
            isActive: (bool) ($data['is_active'] ?? true),
        );
    }

    /**
     * Build a Skill DTO for an update mutation — overlays the input array
     * onto the existing model's values so partial updates work.
     */
    public static function forUpdate(SkillModel $skill, AppInterface $app, array $data): self
    {
        return new self(
            app: $app,
            name: (string) ($data['name'] ?? $skill->name),
            frameworks: isset($data['frameworks'])
                ? array_map('strval', (array) $data['frameworks'])
                : (array) $skill->frameworks,
            skillType: isset($data['skill_type'])
                ? SkillTypeEnum::from((string) $data['skill_type'])
                : SkillTypeEnum::from($skill->skill_type),
            description: $data['description'] ?? $skill->description,
            handler: $data['handler'] ?? $skill->handler,
            definition: $data['definition'] ?? $skill->definition,
            version: (string) ($data['version'] ?? $skill->version),
            isActive: (bool) ($data['is_active'] ?? $skill->is_active),
        );
    }
}
