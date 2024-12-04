<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;

/**
 * @todo move to the social domain
 */
class GenerateMessageSlugActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public function execute(Model $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $slugField = $params['field'] ?? null;

        if ($slugField === null) {
            return ['No field configured to generate slug'];
        }

        $messageData = is_array($message->message) ? $message->message : (Str::isJson($message->message) ? json_decode($message->message, true) : []);
        $fieldToSlug = $messageData[$slugField] ?? null;

        if ($fieldToSlug === null) {
            return ['No slug field {' . $slugField . ' found in message ' . $message->id];
        }

        $message->slug = Str::simpleSlug($fieldToSlug);
        if (method_exists($message, 'disableWorkflows')) {
            $message->disableWorkflows();
        }
        $message->saveOrFail();

        return [
            'message' => $message->id,
            'slug' => $message->slug,
        ];
    }
}
