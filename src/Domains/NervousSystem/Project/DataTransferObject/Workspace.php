<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Project\Enums\WorkspaceStatusEnum;
use Kanvas\NervousSystem\Project\Models\Workspace as WorkspaceModel;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class Workspace extends Data
{
    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly Users $owner,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly WorkspaceStatusEnum $status = WorkspaceStatusEnum::ACTIVE,
        public readonly ?Agent $oversightAgent = null,
    ) {
    }

    public static function fromMultiple(
        Apps $app,
        Users $requestingUser,
        Companies $company,
        array $data,
    ): self {
        /** @var Agent|null $oversightAgent */
        $oversightAgent = isset($data['agent_id'])
            ? Agent::getByIdFromCompanyApp((int) $data['agent_id'], $company, $app)
            : null;

        return new self(
            app: $app,
            company: $company,
            owner: $requestingUser,
            name: (string) $data['name'],
            description: $data['description'] ?? null,
            status: isset($data['status'])
                ? WorkspaceStatusEnum::from((string) $data['status'])
                : WorkspaceStatusEnum::ACTIVE,
            oversightAgent: $oversightAgent,
        );
    }

    public static function forUpdate(
        WorkspaceModel $workspace,
        Apps $app,
        Companies $company,
        Users $owner,
        array $data,
    ): self {
        /** @var Agent|null $oversightAgent */
        $oversightAgent = array_key_exists('agent_id', $data)
            ? ($data['agent_id'] !== null
                ? Agent::getByIdFromCompanyApp((int) $data['agent_id'], $company, $app)
                : null)
            : ($workspace->agent_id !== null
                ? Agent::getByIdFromCompanyApp($workspace->agent_id, $company, $app)
                : null);

        return new self(
            app: $app,
            company: $company,
            owner: $owner,
            name: (string) ($data['name'] ?? $workspace->name),
            description: array_key_exists('description', $data) ? $data['description'] : $workspace->description,
            status: isset($data['status'])
                ? WorkspaceStatusEnum::from((string) $data['status'])
                : WorkspaceStatusEnum::from($workspace->status),
            oversightAgent: $oversightAgent,
        );
    }
}
