<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Organizations\DataTransferObject\Organization;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Optional;

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
        public readonly int $leads_owner_id = 0,
        public readonly int $type_id = 0,
        public readonly int $status_id = 0,
        public readonly int $source_id = 0,
        public readonly int $receiver_id = 0,
        public readonly ?string $description = null,
        public readonly ?string $reason_lost = null,
        /** @var Kanvas\Guild\Customers\DataTransferObject\People[] */
        public readonly DataCollection|Optional $participants,
        public readonly Organization|null $organization = null,
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
            $request['leads_owner_id'] ?? 0,
            $request['type_id'] ?? 0,
            $request['status_id'] ?? 0,
            $request['source_id'] ?? 0,
            $request['receiver_id'] ?? 0,
            $request['description'] ?? null,
            $request['reason_lost'] ?? null,
            $request['participants'] ?? new Optional(),
            isset($request['organization']) ? Organization::from([
                $branch->company,
                $user,
                ...$request['organization'],
            ]) : null,
            $request['custom_fields'] ?? [],
        );
    }
}
