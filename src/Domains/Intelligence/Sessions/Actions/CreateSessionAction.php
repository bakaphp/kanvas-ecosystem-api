<?php

namespace Kanvas\Intelligence\Sessions\Actions;

use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Intelligence\Sessions\Models\Session as SessionModel;

class CreateSessionAction
{
    public function __construct(
        protected Session $session
    ) {
    }

    public function execute(): SessionModel
    {
        $content = $this->session->content ? [] : new CreateContentSessionAction(
            $this->session->entity_namespace,
            $this->session->entity_id,
            $this->session->agent,
            $this->session->company->defaultBranch,
        )->execute();

        return SessionModel::updateOrCreate([
                'uuid' => $this->session->channel->slug,
            ], [
            'apps_id' => $this->session->app->getId(),
            'companies_id' => $this->session->company->getId(),
            'channel_id' => $this->session->channel->getId(),
            'agents_id' => $this->session->agent?->getId(),
            'canal_id' => $this->session->canal_id,
            'entity_namespace' => $this->session->entity_namespace,
            'entity_id' => $this->session->entity_id,
            'user' => $this->session->user,
            'content' => $content,
        ]);
    }
}
