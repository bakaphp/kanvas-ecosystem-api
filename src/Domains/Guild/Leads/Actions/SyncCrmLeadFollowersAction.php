<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Guild\Leads\DataTransferObject\CrmLeadSync;
use Kanvas\Guild\Leads\Exceptions\CrmLeadSyncException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;

/**
 * Followers are named by email and looked up among the company's own users, never globally.
 * `prepare` fails on an unknown email before anything is written.
 */
class SyncCrmLeadFollowersAction
{
    /** @var list<Users> */
    private array $attach = [];

    /** @var list<Users> */
    private array $detach = [];

    public function __construct(
        protected readonly CrmLeadSync $data
    ) {
    }

    /**
     * @throws CrmLeadSyncException
     */
    public function prepare(): void
    {
        $followers = $this->data->followers;

        $this->attach = $this->resolve($followers?->attach ?? [], 'followers.attach');
        $this->detach = $this->resolve($followers?->detach ?? [], 'followers.detach');
    }

    public function execute(Lead $lead): void
    {
        foreach ($this->attach as $user) {
            $user->follow($lead);
        }

        foreach ($this->detach as $user) {
            $user->unFollow($lead, $this->data->app);
        }
    }

    /**
     * @param list<string> $emails
     *
     * @return list<Users>
     */
    private function resolve(array $emails, string $field): array
    {
        $company = $this->data->companyModel();
        $users = [];

        foreach ($emails as $email) {
            $user = $company->users()
                ->whereRaw('LOWER(users.email) = ?', [mb_strtolower(trim($email))])
                ->first();

            if (! $user instanceof Users) {
                throw CrmLeadSyncException::followerNotFound($email, $field);
            }

            $users[] = $user;
        }

        return $users;
    }
}
