<?php

declare(strict_types=1);

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
        /**
         * IMPORTANT: Kanvas Architecture Rule
         * All IDs in Kanvas must be unique per company and apps combination.
         * Everything in Kanvas should be individual per:
         * - company_id + apps_id (always)
         * - user_id (in some cases)
         * This ensures proper data isolation and multi-tenancy.
         */
        $content = $this->session->content ? [] : new CreateContentSessionAction($this->session)->execute();

        // Legacy UUID format (without company_id): {channel-slug}-{app-id}
        $legacyUuid = $this->session->channel->slug . '-' . $this->session->app->getId();

        // New UUID format (always includes company_id): {channel-slug}-{app-id}-{company-id}
        $newUuid = $legacyUuid . '-' . $this->session->company->getId();

        // Search for existing session with legacy UUID format
        $existingSession = SessionModel::where('uuid', $legacyUuid)
            ->where('apps_id', $this->session->app->getId())
            ->where('agents_id', $this->session->agent->getId())
            ->where('channel_id', $this->session->channel->getId())
            ->where('companies_id', $this->session->company->getId())
            ->where('entity_namespace', $this->session->entity_namespace)
            ->where('entity_id', $this->session->entity_id)
            ->first();

        if ($existingSession) {
            // Update existing session with new UUID format
            $existingSession->update([
                'uuid' => $newUuid,
                'canal_id' => $this->session->canal_id,
                'entity_namespace' => $this->session->entity_namespace,
                'entity_id' => $this->session->entity_id,
                'user' => $this->session->user,
                'content' => $content,
            ]);

            return $existingSession;
        }

        // If no legacy session exists, use updateOrCreate with new UUID format
        return SessionModel::updateOrCreate([
                'uuid' => $newUuid,
                'apps_id' => $this->session->app->getId(),
                'agents_id' => $this->session->agent->getId(),
                'channel_id' => $this->session->channel->getId(),
                'companies_id' => $this->session->company->getId(),
                'entity_namespace' => $this->session->entity_namespace,
                'entity_id' => $this->session->entity_id,
            ], [
            'canal_id' => $this->session->canal_id,
            'entity_namespace' => $this->session->entity_namespace,
            'entity_id' => $this->session->entity_id,
            'user' => $this->session->user,
            'content' => $content,
        ]);
    }
}
