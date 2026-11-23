<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Handlers;

use Baka\Contracts\BaseIntegration;
use Baka\Contracts\DefaultRule;
use Kanvas\Connectors\Elead\Activities\StopResponseClockActivity;
use Kanvas\Connectors\Elead\Client;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Concerns\DeclaresSalesAssistPullRules;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Override;

class ELeadHandler extends BaseIntegration
{
    use DeclaresSalesAssistPullRules {
        defaultRules as salesAssistPullRules;
    }

    #[Override]
    public function defaultRules(): array
    {
        return [
            ...$this->salesAssistPullRules(),
            new DefaultRule(
                trigger: WorkflowEnum::AGENT_REACHED_OUT,
                model: Lead::class,
                activity: StopResponseClockActivity::class,
                name: 'Elead stop the response clock',
                isAsync: true,
            ),
        ];
    }

    #[Override]
    public function setup(): bool
    {
        $this->company->set(CustomFieldEnum::COMPANY->value, $this->data['subscription_id']);
        $this->app->set(ConfigurationEnum::ELEAD_API_KEY->value, $this->data['api_key']);
        $this->app->set(ConfigurationEnum::ELEAD_API_SECRET->value, $this->data['api_secret']);
        $this->app->set(ConfigurationEnum::ELEAD_DEV_MODE->value, $this->data['dev_mode']);

        $client = new Client(
            $this->app,
            $this->company
        );

        $response = $client->auth();

        return ! empty($response['access_token']);
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return [CustomFieldEnum::COMPANY->value];
    }
}
