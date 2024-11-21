<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivities;

class GenerateMessageTagsActivity extends KanvasActivities implements WorkflowActivityInterface
{
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! key_exists('tags', $params)) {
            return [
                'message' => 'No tags to add to the message',
            ];
        }

        if (! $entity instanceof Message) {
            return [
                 'message' => 'Entity is not a message',
             ];
        }

        $messageData = $entity->message;

        if (empty($messageData)) {
            return [
                'message' => 'No message data found',
            ];
        }

        $messagesTags = $this->findKeysInArray((array) $messageData, $params['tags']);

        if (empty($messagesTags)) {
            return [
                 'message' => 'No tags found in the message data',
                 'tags' => $params['tags'],
             ];
        }

        $entity->addTags(array_values($messagesTags));

        return [
            'message' => 'Tags added to the message',
            'tags' => $messagesTags,
        ];
    }

    /**
     * find array keys in a given array.
     * example how to define the list of keys
     *
     * $keysToFind = ['display_type.name','type.name', 'created_at'];
     */
    protected function findKeysInArray(array $data, array $keysToFind)
    {
        $result = [];

        foreach ($keysToFind as $key) {
            // Use Laravel's data_get helper to fetch the value
            $value = data_get($data, $key);

            // Add the value to the result if it exists
            if (! is_null($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
