<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\DataTransferObject\Concerns\DeclaresFileHeader;
use Kanvas\SystemModules\Models\SystemModules;
use Spatie\LaravelData\Data;

class FilesystemMapper extends Data
{
    use DeclaresFileHeader;

    public function __construct(
        public AppInterface $app,
        public CompaniesBranches $branch,
        public UserInterface $user,
        public SystemModules $systemModule,
        public string $name,
        public array $header,
        public array $mapping,
        public array $configuration = [],
        public bool $is_default = false,
        public bool $has_header = false,
        public ?string $description = null,
    ) {
    }

    public static function viaRequest(
        AppInterface $app,
        CompaniesBranches $branch,
        UserInterface $user,
        SystemModules $systemModule,
        array $data
    ): self {
        $header = $data['header'] ?? $data['file_header'] ?? [];

        return new self(
            app: $app,
            branch: $branch,
            user: $user,
            systemModule: $systemModule,
            name: $data['name'],
            description: $data['description'] ?? null,
            header: $header,
            mapping: $data['mapping'],
            configuration: json_decode(json_encode($data['configuration'] ?? []), true),
            is_default: $data['is_default'] ?? false,
            // Unstated, the header itself is the answer: a mapper given one reads a file with one,
            // a connector mapper given none does not. Stating it and contradicting it is the error.
            has_header: (bool) ($data['has_header'] ?? ! empty($header)),
        );
    }
}
