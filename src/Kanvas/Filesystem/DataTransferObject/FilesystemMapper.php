<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\SystemModules\Models\SystemModules;
use Spatie\LaravelData\Data;

class FilesystemMapper extends Data
{
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
        return new self(
            app: $app,
            branch: $branch,
            user: $user,
            systemModule: $systemModule,
            name: $data['name'],
            description: $data['description'] ?? null,
            header: $data['header'] ?? $data['file_header'],
            mapping: $data['mapping'],
            configuration: isset($data['configuration']) ? json_decode(json_encode($data['configuration']), true) : [],
            is_default: $data['is_default'] ?? false
        );
    }
}
