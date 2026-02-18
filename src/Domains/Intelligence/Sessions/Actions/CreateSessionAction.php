<?php

declare(strict_types=1);

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
        $content = $this->session->content ?: new CreateContentSessionAction($this->session)->execute();

        $existingSession = SessionModel::query()
            ->where('apps_id', $this->session->app->getId())
            ->where('agents_id', $this->session->agent->getId())
            ->where('channel_id', $this->session->channel->getId())
            ->where('companies_id', $this->session->company->getId())
            ->where('entity_namespace', $this->session->entity_namespace)
            ->where('entity_id', $this->session->entity_id)
            ->first();

        $payload = [
            'canal_id' => $this->session->canal_id,
            'entity_namespace' => $this->session->entity_namespace,
            'entity_id' => $this->session->entity_id,
            'user' => $this->session->user,
            'content' => $content,
        ];

        if ($existingSession) {
            // Keep legacy UUIDs untouched to avoid breaking historical sessions.
            $existingSession->update($payload);

            return $existingSession;
        }

        return SessionModel::create([
            ...$payload,
            'uuid' => $this->buildSessionUuid(),
            'apps_id' => $this->session->app->getId(),
            'agents_id' => $this->session->agent->getId(),
            'channel_id' => $this->session->channel->getId(),
            'companies_id' => $this->session->company->getId(),
        ]);
    }

    protected function buildSessionUuid(): string
    {
        return $this->session->channel->slug
            . '-' . $this->session->app->getId();
        // . '-' . $this->session->company->getId();
    }
}
