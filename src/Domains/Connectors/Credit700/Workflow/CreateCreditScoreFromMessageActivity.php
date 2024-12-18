<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Workflow;

use Baka\Support\Str;
use Kanvas\ActionEngine\Engagements\DataTransferObject\EngagementMessage;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplicant;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Connectors\Credit700\Services\CreditScoreService;
use Kanvas\Connectors\Credit700\Support\Setup;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Channels\Services\DistributionMessageService;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\KanvasActivity;

class CreateCreditScoreFromMessageActivity extends KanvasActivity
{
    /**
     * Generate a credit score for the lead.
     */
    public function execute(Message $message, Apps $app, array $params): array
    {
        $setup = new Setup($app);
        $setup->run();

        $engagement = Engagement::fromApp($app)->where('message_id', $message->getId())->firstOrFail();
        $lead = $engagement->lead;
        $people = $lead->people;
        $messageData = $engagement->message?->message['data']['form'];

        $creditScoreService = new CreditScoreService($app);
        $creditApplicant = $creditScoreService->getCreditScore(
            CreditApplicant::from($lead->people, $messageData['personal']['ssn']),
            $lead->user
        );

        $engagementMessage = new EngagementMessage(
            data: $creditApplicant,
            text: ConfigurationEnum::ACTION_VERB->value,
            verb: ConfigurationEnum::ACTION_VERB->value,
            hashtagVisited: ConfigurationEnum::ACTION_VERB->value,
            actionLink: 'http://nolink.com',
            source: 'workflow',
            linkPreview: 'http://nolink.com',
            engagementStatus: 'submitted',
            visitorId: Str::uuid()->toString(),
            status: 'submitted'
        );

        $createMessage = new CreateMessageAction(
            MessageInput::fromArray(
                [
                    'message' => $engagementMessage->toArray(),
                    'reactions_count' => 0,
                    'comments_count' => 0,
                    'total_liked' => 0,
                    'total_disliked' => 0,
                    'total_saved' => 0,
                    'total_shared' => 0,
                    'ip_address' => '127.0.0.1',
                ],
                $message->user,
                MessageType::fromApp($app)->where('verb', ConfigurationEnum::ACTION_VERB->value)->firstOrFail(),
                $message->company,
                $app
            ),
            SystemModulesRepository::getByModelName(Lead::class, $app),
            $lead->getId()
        );

        $message = $createMessage->execute();

        $leadChannel = Channel::fromApp($app)
            ->where('entity_id', $lead->getId())
            ->whereIn('entity_namespace', [Lead::class, SystemModules::getLegacyNamespace(Lead::class)])
            ->firstOrFail();
        DistributionMessageService::sentToChannelFeed($leadChannel, $message);

        ///$lead->user->no

        return $creditApplicant;
    }
}
