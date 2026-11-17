<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

use Kanvas\Filesystem\Enums\MediaTypeEnum;

final readonly class Attachment
{
    public function __construct(
        public string $url,
        public ?string $name = null,
        public ?string $mimeType = null
    ) {
    }

    /**
     * What kind of media this is: what the sender declared, else the file name, else the URL. No
     * one of them is enough: a lead's files arrive as bare URLs, and a signed URL has no extension.
     */
    public function mediaType(): MediaTypeEnum
    {
        $extensions = [
            $this->mimeType !== null ? MediaTypeEnum::extensionForMime($this->mimeType) : '',
            self::extensionOf($this->name),
            self::extensionOf($this->url),
        ];

        foreach ($extensions as $extension) {
            $type = MediaTypeEnum::fromExtension($extension);

            if ($type !== MediaTypeEnum::UNKNOWN) {
                return $type;
            }
        }

        return MediaTypeEnum::UNKNOWN;
    }

    private static function extensionOf(?string $path): string
    {
        return strtolower(pathinfo((string) parse_url((string) $path, PHP_URL_PATH), PATHINFO_EXTENSION));
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
