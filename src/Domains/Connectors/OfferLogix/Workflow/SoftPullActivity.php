<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OfferLogix\Workflow;

use Kanvas\ActionEngine\Engagements\DataTransferObject\EngagementMessage;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OfferLogix\Actions\SoftPullAction;
use Kanvas\Connectors\OfferLogix\DataTransferObject\SoftPull;
use Kanvas\Connectors\OfferLogix\Enums\ConfigurationEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\KanvasActivity;

/**
 * @todo rename to specific activity is from a message
 */
class SoftPullActivity extends KanvasActivity
{
    public function execute(Message $entity, Apps $app, array $params): array
    {
        $message = EngagementMessage::from($entity->message);
        $lead = $entity->entity();

        if (! $lead instanceof Lead) {
            return [
                'message' => 'Lead not found',
                'entity' => $entity,
            ];
        }

        $people = $lead->people;
        $results = [];

        if ($message->verb !== ConfigurationEnum::ACTION_VERB->value && $message->status === ActionStatusEnum::SUBMITTED->value) {
            return [
                'message' => 'Not a Soft Pull and not submitted',
            ];
        }

        $engagement = Engagement::getByMessageId($entity->getId());
        $parentEngagement = $engagement->parent();
        $people = $parentEngagement->people ?? $people;

        $softPull = SoftPull::fromMessage($people, $message->toArray());

        if (empty($softPull->last_4_digits_of_ssn)) {
            return [
                'message' => 'Last 4 digits of SSN is required',
                'entity' => $entity,
            ];
        }

        $people->dob = $softPull->dob;
        $people->name = $softPull->getName();
        $people->firstname = $softPull->first_name;
        $people->lastname = $softPull->last_name;
        $people->middlename = $softPull->middle_name;
        $people->saveOrFail();

        if (! empty($softPull->mobile)) {
            $people->addPhone($softPull->mobile);
        }

        $softPullAction = new SoftPullAction($lead, $people);
        $results = $softPullAction->execute($softPull);

        //if result is a url
        if (filter_var($results, FILTER_VALIDATE_URL)) {
            $filesystem = new Filesystem();
            $filesystem->fill([
                'name' => 'soft_pull',
                'companies_id' => $entity->companies_id,
                'apps_id' => $entity->apps_id,
                'users_id' => $entity->users_id,
                'path' => pathinfo($results, PATHINFO_DIRNAME),
                'url' => $results,
                'file_type' => 'pdf',
                'size' => '0',
            ]);
            $filesystem->saveOrFail();

            $entity->addFile($filesystem, 'soft_pull');

            if ($entity->parent) {
                $parentMessage = $entity->parent;
                $parentMessage->setMessage('link', $results);
                $parentMessage->saveOrFail();
            }
        }

        return [
            'message' => 'Soft Pull executed from message',
            'entity' => $entity,
            'results' => $results,
        ];
    }
}
