<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OfferLogix\Workflow;

use DateTime;
use Illuminate\Support\Arr;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OfferLogix\Actions\SoftPullAction;
use Kanvas\Connectors\OfferLogix\Enums\ConfigurationEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\KanvasActivity;

class SoftPullActivity extends KanvasActivity
{
    public function execute(Message $entity, Apps $app, array $params): array
    {
        $message = $entity->getMessage();
        $lead = $entity->entity();

        if (! $lead instanceof Lead) {
            return [
                'message' => 'Lead not found',
                'entity' => $entity,
            ];
        }

        $people = $lead->people;
        $results = [];

        if ($message['verb'] !== ConfigurationEnum::ACTION_VERB->value && $message['status'] === ActionStatusEnum::SUBMITTED->value) {
            return [
                'message' => 'Not a Soft Pull and not submitted',
            ];
        }

        $engagement = Engagement::getByMessageId($entity->getId());
        $parentEngagement = $engagement->parent();
        $people = $parentEngagement->people ?? $people;

        $dataArray = [];
        foreach ($message['data'] as $item) {
            // Convert label to variable name (replace space with underscore)
            $key = str_replace(' ', '_', strtolower($item['label']));
            // Assign value to the variable
            $dataArray[$key] = $item['value'];
        }

        if (! empty($dataArray)) {
            $people->dob = DateTime::createFromFormat('m/d/Y', $dataArray['birthday'])->format('Y-m-d') ?? $people->dob;
            $people->name = $dataArray['first_name'] . ' ' . $dataArray['last_name'] ?? $people->name;
            $people->firstname = $dataArray['first_name'] ?? $people->firstname;
            $people->lastname = $dataArray['last_name'] ?? $people->lastname;
            $people->middlename = $dataArray['middle_name'] ?? $people->middle_name;
            $people->saveOrFail();

            if (! empty($dataArray['mobile'])) {
                $people->addPhone($dataArray['mobile']);
            }
        }

        $softPull = new SoftPullAction($lead, $people);
        $results = $softPull->execute(
            $dataArray,
            Arr::get($dataArray, 'last_4_digits_of_ssn')
        );

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
            'message' => 'Soft Pull executed',
            'entity' => $entity,
            'results' => $results,
        ];
    }
}
