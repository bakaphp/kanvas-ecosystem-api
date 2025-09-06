<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Actions\CreateContentSessionAction;
use Kanvas\Intelligence\Sessions\Models\Session;

class AgentSessionQuery
{
    public function getSessionInfo(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = app(CompaniesBranches::class)->company;

        $session = Session::getByUuidFromCompanyApp($request['id'], $company, $app);

        if (true){//$session->agent->role !== ($session->content['background'] ?? null)) {
            $content = new CreateContentSessionAction($session)->execute();

            $session->content = $content;
            $session->update();
        }

        return [
            'id' => $request['id'],
            'name' => 'orchestrate',
            'company' => $session->company,
            'user' => $session->user,
            'company_config' => $session->agent->type->config,
            'content' => $session->content,
            'channel' => $session->channel?->get(ConfigurationEnum::AGENT_CHANNEL_TYPE->value),
        ];
    }
}
