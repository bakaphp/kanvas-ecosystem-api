<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Reynolds\DataTransferObject\Crm\CrmCustomer;
use Kanvas\Connectors\Reynolds\DataTransferObject\Crm\CrmFollowers;
use Kanvas\Connectors\Reynolds\DataTransferObject\Crm\CrmIdentity;
use Kanvas\Connectors\Reynolds\DataTransferObject\Crm\CrmLeadInfo;
use Kanvas\Connectors\Reynolds\DataTransferObject\Crm\CrmParticipantAdd;
use Kanvas\Connectors\Reynolds\DataTransferObject\Crm\CrmParticipantRemove;
use Kanvas\Connectors\Reynolds\DataTransferObject\Crm\CrmParticipants;
use Kanvas\Connectors\Reynolds\Enums\CrmTypeEnum;
use Kanvas\Connectors\Reynolds\Exceptions\CrmLeadSyncException;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Exceptions\CannotCreateData;
use TypeError;
use ValueError;

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
     * the company. Nothing validates the request before this (it arrives as free-form workflow
     * params), so what is required is checked here and a malformed section is a refusal, not a crash.
     */
    public static function fromMultiple(
        AppInterface $app,
        Users $user,
        CompanyInterface $company,
        array $data
    ): self {
        self::assertRequired($data);

        $branch = isset($data['branch_id'])
            ? CompaniesBranches::getByIdFromCompany((int) $data['branch_id'], $company)
            : $user->getCurrentBranch();

        try {
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
        } catch (CannotCreateData|TypeError|ValueError $exception) {
            throw CrmLeadSyncException::validationFailed(
                'The request is malformed: ' . $exception->getMessage(),
                'request'
            );
        }
    }

    /**
     * The company as a model, for what needs more than the interface (its users).
     */
    public function companyModel(): Companies
    {
        return $this->company instanceof Companies
            ? $this->company
            : Companies::getById($this->company->getId());
    }

    private static function assertRequired(array $data): void
    {
        $identity = $data['identity'] ?? null;

        if (! is_string($data['request_id'] ?? null) || trim($data['request_id']) === '') {
            throw CrmLeadSyncException::validationFailed('request_id is required', 'request_id');
        }

        if (! is_array($identity) || trim((string) ($identity['client_id'] ?? '')) === '') {
            throw CrmLeadSyncException::validationFailed('identity.client_id is required', 'identity.client_id');
        }

        if (CrmTypeEnum::tryFrom((string) ($identity['type'] ?? '')) === null) {
            throw CrmLeadSyncException::validationFailed('identity.type is not a known CRM', 'identity.type');
        }
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
