<?php

declare(strict_types=1);

namespace Kanvas\Communication\Setup\Enums;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Messages\Enums\ConversationAgentEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\AgentEnum;

/**
 * The agents every company needs to converse, by the name the code looks them up with. A company
 * missing one gets a copy of the app's global agent of that name (companies_id 0).
 */
enum DefaultAgentEnum: string
{
    case SALLY = ConversationAgentEnum::DEFAULT_NAME->value;
    case POLLY = 'Polly';
    case FOLLOW_UP_ENGAGER = AgentEnum::FOLLOW_UP_ENGAGER->value;
    case FIRST_MESSAGE_ENGAGER = AgentEnum::FIRST_MESSAGE_ENGAGER->value;

    public function of(Apps $app, Companies $company): ?Agent
    {
        return self::named($app, $company, $this->value);
    }

    /**
     * The company's own agent of that name, never the app's global one.
     */
    public static function named(Apps $app, Companies $company, string $name): ?Agent
    {
        return Agent::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->where('name', $name)
            ->notDeleted()
            ->orderBy('id')
            ->first();
    }
}
