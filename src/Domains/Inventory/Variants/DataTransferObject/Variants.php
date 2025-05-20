<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\DataTransferObject;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Spatie\LaravelData\Data;

class Variants extends Data
{
    public function __construct(
        public Products $product,
        public string $name,
        public string $sku,
        public ?string $description = null,
        public ?int $status_id = null,
        public ?string $short_description = null,
        public ?string $html_description = null,
        public ?string $ean = null,
        public ?string $barcode = null,
        public ?string $serial_number = null,
        public ?string $slug = null,
        public array $files = [],
        public ?float $weight = null,
        public ?bool $is_published = true
    ) {
    }

    public static function viaRequest(array $request, UserInterface $user): self
    {
        if ($user->isAppOwner()) {
            $product = ProductsRepository::getById((int) $request['products_id']);
        } else {
            $product = ProductsRepository::getById((int) $request['products_id'], $user->getCurrentCompany());
        }

        return new self(
            $product,
            $request['name'],
            $request['sku'],
            $request['description'] ?? null,
            $request['status_id'] ?? null,
            $request['short_description'] ?? null,
            $request['html_description'] ?? null,
            $request['ean'] ?? null,
            $request['barcode'] ?? null,
            $request['serial_number'] ?? null,
            $request['slug'] ?? null,
            $request['files'] ?? [],
            $request['weight'] ?? null,
            $request['is_published'] ?? true
        );
    }

    public function getDescription(): ?string
    {
        if (empty($this->description) && ! empty($this->html_description)) {
            $html = $this->html_description;
            $html = str_replace(['</p>', '</div>', '<br>', '<br />'], "\n", $html);

            $plainText = Str::of($html)
                ->stripTags()
                ->replaceMatches('/\n\s+\n/', "\n\n") // Normalize whitespace between paragraphs
                ->replaceMatches('/[\r\n]{3,}/', "\n\n") // Limit consecutive line breaks
                ->trim();

            $this->description = (string) $plainText;
        }

        return $this->description;
    }
}
