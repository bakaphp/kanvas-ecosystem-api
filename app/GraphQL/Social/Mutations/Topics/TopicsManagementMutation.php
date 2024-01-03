<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Topics;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Actions\FollowAction;
use Kanvas\Social\Follows\Actions\UnFollowAction;
use Kanvas\Social\Topics\Actions\AttachEntityToTopic;
use Kanvas\Social\Topics\Actions\CreateTopicAction;
use Kanvas\Social\Topics\Actions\DetachEntityFromTopic;
use Kanvas\Social\Topics\DataTransferObject\TopicInput;
use Kanvas\Social\Topics\Models\Topic;

class TopicsManagementMutation
{
    public function create($rootValue, array $req): Topic
    {
        $topic = new TopicInput(
            app(Apps::class),
            auth()->user()->getCurrentCompany(),
            auth()->user(),
            $req['input']['name'],
            $req['input']['is_feature'],
            $req['input']['status'],
            $req['input']['weight'] ?? 0
        );

        $createTopic = new CreateTopicAction($topic);

        return $createTopic->execute();
    }

    public function update(mixed $rootValue, array $req): Topic
    {
        $topic = Topic::getById($req['id']);
        if ($topic->users_id !== auth()->user()->getId()) {
            throw new Exception('You are not allowed to update this topic');
        }
        $topic->update($req['input']);

        return $topic;
    }

    public function attachTopicToEntity(mixed $rootValue, array $req): Topic
    {
        $topic = Topic::getById($req['id']);
        $attachEntityToTopic = new AttachEntityToTopic(
            $topic,
            $req['entityId'],
            $req['entityNamespace']
        );

        return  $attachEntityToTopic->execute();
    }

    public function detachTopicFromEntity(mixed $rootValue, array $req): Topic
    {
        $topic = Topic::getById($req['id']);
        $detachEntityFromTopic = new DetachEntityFromTopic(
            $topic,
            $req['entityId'],
            $req['entityNamespace']
        );

        return  $detachEntityFromTopic->execute();
    }

    public function followTopic(mixed $rootValue, array $req): Topic
    {
        $topic = Topic::getById($req['id']);
        $followAction = new FollowAction(
            auth()->user(),
            $topic
        );

        return $followAction->execute();
    }

    public function unFollowTopic(mixed $rootValue, array $req): bool
    {
        $topic = Topic::getById($req['id']);
        $followAction = new UnFollowAction(
            auth()->user(),
            $topic
        );
        $followAction->execute();

        return true;
    }
}
