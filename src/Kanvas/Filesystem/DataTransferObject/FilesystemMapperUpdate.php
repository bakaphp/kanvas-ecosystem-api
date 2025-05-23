<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Spatie\LaravelData\Data;

class FilesystemMapperUpdate extends Data
{
    public function __construct(
        public AppInterface $app,
        public CompaniesBranches $branch,
        public UserInterface $user,
        public string $name,
        public array $header,
        public array $mapping,
        public array $configuration = [],
        public bool $is_default = false,
        public ?string $description = null
    ) {
    }

    public static function viaRequest(
        AppInterface $app,
        CompaniesBranches $branch,
        UserInterface $user,
        array $data
    ): self {
        return new self(
            $app,
            $branch,
            $user,
            $data['name'],
            $data['header'] ?? $data['file_header'],
            $data['mapping'],
            json_decode(json_encode($data['configuration'] ?? []), true),
            $data['is_default'] ?? false,
            $data['description'] ?? null,
        );
    }
}
