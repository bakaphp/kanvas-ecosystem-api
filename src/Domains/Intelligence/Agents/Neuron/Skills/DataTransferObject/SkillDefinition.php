<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Skills\DataTransferObject;

final readonly class SkillDefinition
{
    public function __construct(
        public string $name,
        public string $description,
        public string $filePath,
        public string $source,
    ) {
    }
}
