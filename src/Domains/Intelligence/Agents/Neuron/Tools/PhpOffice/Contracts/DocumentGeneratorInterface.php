<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\PhpOffice\Contracts;

interface DocumentGeneratorInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function generate(array $data): string;
}
