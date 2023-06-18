<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Spatie\LaravelData\Data;

class Lead extends Data
{
    /**
     * __construct.
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompaniesBranches $branch,
        public readonly UserInterface $user,
        public readonly string $title,
        public readonly int $pipeline_stage_id,
        public readonly People $people,
        public readonly ?string $description = null,
        public readonly ?int $type_id = null,
        public readonly ?int $status_id = null,
        public readonly ?int $source_id = null,
        public readonly ?int $receiver_id = null,
        public readonly ?string $reason_lost = null,
        public readonly array $custom_fields = [],
    ) {
    }

    /**
     *  @psalm-suppress ArgumentTypeCoercion
     */
    public static function viaRequest(array $request): self
    {
        $branch = CompaniesBranches::getById($request['branch_id']);
        $user = auth()->user();
        CompaniesRepository::userAssociatedToCompanyAndBranch(
            $branch->company,
            $branch,
            $user
        );

        return new self(
            app(Apps::class),
            $branch,
            $user,
            (string) $request['title'],
            (int) $request['pipeline_stage_id'],
            People::from([
                'app' => app(Apps::class),
                'branch' => $branch,
                'user' => $user,
                ...$request['people'],
            ]),
            $request['description'] ?? null,
            $request['type_id'] ?? null,
            $request['status_id'] ?? null,
            $request['source_id'] ?? null,
            $request['receiver_id'] ?? null,
            $request['reason_lost'] ?? null,
            $request['custom_fields'] ?? [],
        );
    }
}
