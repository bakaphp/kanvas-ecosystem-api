<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Spatie\LaravelData\Data;

class Pipeline extends Data
{
    /**
     * __construct.
     */
    public function __construct(
        public readonly CompaniesBranches $branch,
        public readonly UserInterface $user,
        public readonly SystemModules $systemModule,
        public readonly string $name,
        public readonly int $weight = 0,
        public readonly bool $isDefault = false,
        public readonly array $stages = [],
        public readonly ?string $slug = null,
    ) {
    }

    /**
     *  @psalm-suppress ArgumentTypeCoercion
     */
    public static function viaRequest(
        UserInterface $user,
        CompaniesBranches $branch,
        AppInterface $app,
        array $request
    ): self {
        CompaniesRepository::userAssociatedToCompanyAndBranch(
            $branch->company,
            $branch,
            $user
        );

        //for now all pipelines are for leads
        $systemModule = SystemModulesRepository::getByModelName(Lead::class, $app);

        return new self(
            $branch,
            $user,
            $systemModule,
            (string) $request['name'],
            (int) ($request['weight'] ?? 0),
            (bool) ($request['is_default'] ?? false),
            (array) ($request['stages'] ?? []),
            $request['slug'] ?? null
        );
    }
}
