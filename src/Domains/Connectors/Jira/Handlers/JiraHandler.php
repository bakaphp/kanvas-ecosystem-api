<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Jira\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Jira\Client;
use Kanvas\Connectors\Jira\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

/**
 * Connects Jira through the generic `integrationCompany` mutation. Credentials are the tenant's
 * Jira Cloud instance URL, the account email, and an API token — all company-scoped, since two
 * companies in the same app are almost always different Jira tenants.
 */
class JiraHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $instanceUrl = rtrim(trim((string) ($this->data['instance_url'] ?? '')), '/');
        $email = trim((string) ($this->data['email'] ?? ''));
        $apiToken = trim((string) ($this->data['api_token'] ?? ''));
        $defaultProjectKey = trim((string) ($this->data['default_project_key'] ?? ''));
        $defaultIssueType = trim((string) ($this->data['default_issue_type'] ?? ''));

        if ($instanceUrl === '' || $email === '' || $apiToken === '') {
            throw new ValidationException('Jira instance URL, email and API token are required.');
        }

        if (! Client::validateCredentials($instanceUrl, $email, $apiToken)) {
            throw new ValidationException('Failed to validate the Jira connection.');
        }

        $this->company->set(ConfigurationEnum::INSTANCE_URL->value, $instanceUrl);
        $this->company->set(ConfigurationEnum::EMAIL->value, $email);
        $this->company->set(ConfigurationEnum::API_TOKEN->value, $apiToken);

        if ($defaultProjectKey !== '') {
            $this->company->set(ConfigurationEnum::DEFAULT_PROJECT_KEY->value, $defaultProjectKey);
        }

        if ($defaultIssueType !== '') {
            $this->company->set(ConfigurationEnum::DEFAULT_ISSUE_TYPE->value, $defaultIssueType);
        }

        return true;
    }
}
