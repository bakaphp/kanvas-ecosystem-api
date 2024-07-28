<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class GenerateMessageSlugActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    public $tries = 10;

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
