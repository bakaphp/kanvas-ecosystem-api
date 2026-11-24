<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Research\DataTransferObject;

/**
 * @param list<array{url: string, title: ?string, content: string}> $pages
 */
final readonly class CollectedPages
{
    public function __construct(
        public string $website,
        public string $collector,
        public array $pages,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->pages === [];
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return array_column($this->pages, 'url');
    }
}
