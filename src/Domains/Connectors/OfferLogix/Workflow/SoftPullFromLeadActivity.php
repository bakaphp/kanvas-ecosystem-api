<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OfferLogix\Workflow;

use Baka\Support\Str;
use Kanvas\ActionEngine\Engagements\DataTransferObject\EngagementMessage;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OfferLogix\Actions\SoftPullAction;
use Kanvas\Connectors\OfferLogix\DataTransferObject\SoftPull;
use Kanvas\Connectors\OfferLogix\Enums\ConfigurationEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\KanvasActivity;

class SoftPullFromLeadActivity extends KanvasActivity
{
    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $people = $lead->people;
        $results = [];
        $receiver = $lead->receiver;

        if ($receiver->source_name !== ConfigurationEnum::ACTION_VERB->value) {
            return [
                'message' => 'This receiver is not a Soft Pull - ' . $receiver->source_name,
                'lead_id' => $lead->getId(),
                'receiver_id' => $receiver->getId(),
            ];
        }

        $address = $people->address->count() ? $people->address()->first() : null;

        if (! $address) {
            return [
                'message' => 'Address is required',
                'lead_id' => $lead->getId(),
                'receiver_id' => $receiver->getId(),
            ];
        }

        $softPull = SoftPull::from(
            $people,
            [
                'last_4_digits_of_ssn' => $params['last_4_digits_of_ssn'] ?? '',
                'city' => $address->city,
                'state' => $address->state,
            ]
        );

        if (empty($softPull->last_4_digits_of_ssn)) {
            return [
                'message' => 'Last 4 digits of SSN is required',
                'lead_id' => $lead->getId(),
            ];
        }

        $softPullAction = new SoftPullAction($lead, $people);
        $results = $softPullAction->execute($softPull);

        //if result is a url
        if (filter_var($results, FILTER_VALIDATE_URL)) {
            $filesystem = new Filesystem();
            $filesystem->fill([
                'name' => 'soft_pull',
                'companies_id' => $lead->companies_id,
                'apps_id' => $lead->apps_id,
                'users_id' => $lead->users_id,
                'path' => pathinfo($results, PATHINFO_DIRNAME),
                'url' => $results,
                'file_type' => 'pdf',
                'size' => '0',
            ]);
            $filesystem->saveOrFail();

            $message = $this->createMessage(
                array_merge(
                    $softPull->toArray(),
                    [
                        'soft_pull' => $results,
                    ]
                ),
                $lead,
                $app,
                $lead->user,
                $lead->company
            );

            $message->addFile($filesystem, 'soft_pull');
        }

        return [
            'message' => 'Soft Pull executed from lead',
            'lead_id' => $lead->getId(),
            'results' => $results,
        ];
    }

    /**
     * @todo we've use this in 2 places, should be move to a trait
     */
    private function createMessage(array $data, Lead $lead, Apps $app, $user, $company, ?int $parentId = null): object
    {
        $engagementMessage = new EngagementMessage(
            data: $data,
            text: ConfigurationEnum::ACTION_VERB->value,
            verb: ConfigurationEnum::ACTION_VERB->value,
            hashtagVisited: ConfigurationEnum::ACTION_VERB->value,
            actionLink: 'http://nolink.com',
            source: 'workflow',
            linkPreview: 'http://nolink.com',
            engagementStatus: $parentId ? ActionStatusEnum::SUBMITTED->value : ActionStatusEnum::SENT->value,
            visitorId: Str::uuid()->toString(),
            status: $parentId ? ActionStatusEnum::SUBMITTED->value : ActionStatusEnum::SENT->value,
        );

        $messageInput = [
            'message' => $engagementMessage->toArray(),
            'reactions_count' => 0,
            'comments_count' => 0,
            'total_liked' => 0,
            'total_disliked' => 0,
            'total_saved' => 0,
            'total_shared' => 0,
            'ip_address' => '127.0.0.1',
        ];

        if ($parentId) {
            $messageInput['parent_id'] = $parentId;
        }

        $createMessage = new CreateMessageAction(
            MessageInput::fromArray(
                $messageInput,
                $user,
                MessageType::fromApp($app)->where('verb', ConfigurationEnum::ACTION_VERB->value)->firstOrFail(),
                $company,
                $app
            ),
            SystemModulesRepository::getByModelName(Lead::class, $app),
            $lead->getId()
        );

        return $createMessage->execute();
    }
}
