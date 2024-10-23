<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class GenerateMessageTagsActivity extends Activity implements WorkflowActivityInterface
{
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        if (! key_exists('tags', $params)) {
            throw new Exception('No tags fields provided on the workflow params');
        }

        if (! $entity instanceof Message) {
            throw new Exception('Entity is not a message');
        }

        $messageData = $entity->message;

        if (empty($messageData)) {
            throw new Exception('Message data is empty');
        }

        $messagesTags = $this->findKeysInArray((array) $messageData, $params['tags']);
        $entity->addTags($messagesTags);

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
