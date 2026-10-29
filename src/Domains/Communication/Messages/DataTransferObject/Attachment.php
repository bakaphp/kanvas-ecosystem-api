<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

final readonly class Attachment
{
    public function __construct(
        public string $url,
        public ?string $name = null,
        public ?string $mimeType = null
    ) {
    }

    /**
     * @return array{url: string, name: string|null, mime_type: string|null}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'name' => $this->name,
            'mime_type' => $this->mimeType,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self((string) $data['url'], $data['name'] ?? null, $data['mime_type'] ?? null);
    }
}
