<?php

namespace Kanvas\Intelligence\Sessions\Actions;

use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum;
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
        $content = $this->session->content ? [] : new CreateContentSessionAction($this->session)->execute();

        $sessionUuid = $this->session->channel->slug . '-' . $this->session->app->getId();

        if (! empty($this->session->company->get(ConfigurationEnum::API_KEY->value))) {
            $sessionUuid .= '-' . $this->session->company->getId();
        }

        return SessionModel::updateOrCreate([
                'uuid' => $sessionUuid,
                'apps_id' => $this->session->app->getId(),
                'agents_id' => $this->session->agent->getId(),
                'channel_id' => $this->session->channel->getId(),
                'companies_id' => $this->session->company->getId(),
            ], [
            'canal_id' => $this->session->canal_id,
            'entity_namespace' => $this->session->entity_namespace,
            'entity_id' => $this->session->entity_id,
            'user' => $this->session->user,
            'content' => $content,
        ]);
    }
}
