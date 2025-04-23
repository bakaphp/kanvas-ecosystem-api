<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Google\Actions\GenerateMessageTagAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;

class GenerateMessageTagsWithAiActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $textLookupKey = $params['text_lookup_key'] ?? null;
        $totalTags = $params['total_tags'] ?? 3;

        $generateMessageTagAction = new GenerateMessageTagAction($entity);
        $messageTags = $generateMessageTagAction->execute(
            textLookupKey: $textLookupKey,
            totalTags: $totalTags
        );

        return [
            'message' => 'Tags added to the message',
            'tags'    => $messageTags->tags->toArray(),
        ];
    }
}
