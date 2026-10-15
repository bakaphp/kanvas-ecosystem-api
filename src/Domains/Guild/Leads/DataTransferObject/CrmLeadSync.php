<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmCustomer;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmFollowers;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmIdentity;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmLeadInfo;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmParticipantAdd;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmParticipantRemove;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmParticipants;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

/**
 * One request to bring a CRM customer and lead into Kanvas. Every section is optional and `null`
 * means "the CRM did not give it", never "clear it".
 */
class CrmLeadSync extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly CompaniesBranches $branch,
        public readonly Users $user,
        public readonly string $requestId,
        public readonly CrmIdentity $identity,
        public readonly ?CrmCustomer $customer = null,
        public readonly ?CrmLeadInfo $lead = null,
        public readonly ?CrmParticipants $participants = null,
        public readonly ?CrmFollowers $followers = null
    ) {
    }

    /**
     * The branch is the caller's own current one unless the request names another, which must belong to
     * the company.
     */
    public static function fromMultiple(
        AppInterface $app,
        Users $user,
        CompanyInterface $company,
        array $data
    ): self {
        $branch = isset($data['branch_id'])
            ? CompaniesBranches::getByIdFromCompany((int) $data['branch_id'], $company)
            : $user->getCurrentBranch();

        return new self(
            app: $app,
            company: $company,
            branch: $branch,
            user: $user,
            requestId: (string) $data['request_id'],
            identity: CrmIdentity::from($data['identity']),
            customer: isset($data['customer']) ? CrmCustomer::from($data['customer']) : null,
            lead: isset($data['lead']) ? CrmLeadInfo::from($data['lead']) : null,
            participants: isset($data['participants']) ? self::participants($data['participants']) : null,
            followers: isset($data['followers']) ? self::followers($data['followers']) : null,
        );
    }

    private static function participants(array $data): CrmParticipants
    {
        return new CrmParticipants(
            add: array_map(fn (array $row): CrmParticipantAdd => CrmParticipantAdd::from($row), $data['add'] ?? []),
            remove: array_map(fn (array $row): CrmParticipantRemove => CrmParticipantRemove::from($row), $data['remove'] ?? []),
        );
    }

    private static function followers(array $data): CrmFollowers
    {
        return new CrmFollowers(
            attach: $data['attach'] ?? [],
            detach: $data['detach'] ?? [],
        );
    }
}
