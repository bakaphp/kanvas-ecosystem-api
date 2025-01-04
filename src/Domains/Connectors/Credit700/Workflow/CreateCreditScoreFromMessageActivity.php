<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Workflow;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException as EloquentModelNotFoundException;
use InvalidArgumentException;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Credit700\Support\Setup;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\Models\Message;
use Throwable;
use TypeError;
use ValueError;

class CreateCreditScoreFromMessageActivity extends CreateCreditScoreFromLeadActivity
{
    /**
     * @param Model<Message> $message
     * @throws MissingAttributeException
     * @throws ValueError
     * @throws TypeError
     * @throws ValidationException
     * @throws GuzzleException
     * @throws Exception
     * @throws ModelNotFoundException
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     * @throws EloquentModelNotFoundException
     * @throws Throwable
     */
    public function execute(Model $message, Apps $app, array $params): array
    {
        $this->overWriteAppPermissionService($app);

        $setup = new Setup($app);
        $setup->run();

        $engagement = Engagement::fromApp($app)->where('message_id', $message->getId())->firstOrFail();
        $lead = $engagement->lead;
        $messageData = $this->extractMessageData($engagement->message);

        if (! $messageData) {
            return $this->errorResponse('Message data not found', $lead);
        }

        $creditApplicant = $this->processCreditScore($messageData, $lead, $app, $params);

        if (empty($creditApplicant['iframe_url'])) {
            return $this->errorResponse('Credit score not found', $lead, $creditApplicant);
        }

        $parentMessage = $this->createParentMessage($creditApplicant, $lead, $app, $message);
        $childMessage = $this->createChildMessage($creditApplicant, $lead, $app, $message, $parentMessage);

        $this->distributeMessages($lead, $app, $parentMessage, $childMessage);
        $this->createEngagements($lead, $app, $parentMessage, $childMessage, $message);

        return [
            'scores' => $creditApplicant['scores'],
            'iframe_url' => $creditApplicant['iframe_url'],
            'iframe_url_signed' => $creditApplicant['iframe_url_signed'],
            'pdf' => ! empty($creditApplicant['pdf']) && $creditApplicant['pdf'] instanceof Filesystem ? $creditApplicant['pdf']->url : null,
            'message_id' => $parentMessage->getId(),
            'message' => 'Credit score created successfully',
            'lead_id' => $lead->getId(),
        ];
    }
}
