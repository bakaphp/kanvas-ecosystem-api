<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Contracts;

interface ContextToolInterface
{
    public function execute(array $params = []): array;
}
