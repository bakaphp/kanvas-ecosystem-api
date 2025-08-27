<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Sessions\Actions\CreateContentSessionAction;
use Kanvas\Intelligence\Sessions\Models\Session;

class AgentSessionQuery
{
    public function getSessionInfo(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $session = Session::getByUuid($request['id'], $app);

        if ($session->agent->role !== $session->content['background']) {
            $content = new CreateContentSessionAction(
                $session->entity_namespace,
                $session->entity_id,
                $session->agent,
                $session->company->defaultBranch,
            )->execute();

            //$content = $session->content;
            $content['background'] = $session->agent->role;
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
        ];
    }
}
