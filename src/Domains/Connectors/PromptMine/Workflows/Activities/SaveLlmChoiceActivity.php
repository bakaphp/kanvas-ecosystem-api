<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;

class SaveLlmChoiceActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $messageData = ! is_array($entity->message) ? json_decode($entity->message, true) : $entity->message;

        if (! isset($messageData['ai_model'])) {
            return [
                'result' => false,
                'message' => 'Message does not have an AI model',
            ];
        }
        $entity->user->set('llm_last_choice', $messageData['ai_model']);

        return [
            'message' => 'LLM choice saved',
            'result' => true,
            'user_id' => $entity->user->getId(),
            'model' => $messageData['ai_model'],
            'message_data' => $entity->message,
            'message_id' => $entity->getId(),
        ];
    }
}
