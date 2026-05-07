<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Kanvas\ActionEngine\Engagements\Actions\MessageNotificationTextAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Notifications\EngagementStatusChangedNotification;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\NotifyLeadStakeholdersService;
use Kanvas\Social\Messages\Models\Message;
use Override;

class EngagementStatusChangedEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    protected Lead $lead;
    protected Message $message;
    protected string $action;
    protected MessageNotificationTextAction $notificationTextAction;

    public function __construct(
        public readonly Engagement $engagement
    ) {
        $this->lead = $this->engagement->lead;
        $this->message = $this->engagement->message;
        $this->action = $this->engagement->companyAction->name;

        $overwriteMessage = null;
        if ($this->engagement->companyAction->action->slug === 'view-vehicle') {
            $overwriteMessage = $this->message;
        }

        $this->notificationTextAction = new MessageNotificationTextAction($this->engagement, $overwriteMessage);
        $this->handleStatus();
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        //return new Channel('engagement-lead-' . $this->engagement->lead->getId());
        return new Channel('engagement-lead-status-' . $this->engagement->apps_id . '-' . $this->engagement->lead->leads_owner_id . '-' . $this->engagement->company->getId());
    }

    public function broadcastAs(): string
    {
        return 'engagement.status';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'leadId' => $this->lead->getId(),
            'leadUuid' => $this->lead->uuid,
            'engagementId' => $this->engagement->getId(),
            'notificationText' => $this->notificationTextAction->notificationText(),
        ];
    }

    protected function handleStatus(): void
    {
        if (! $this->lead || ! $this->lead->isOpen()) {
            return;
        }

        $messageData = $this->message->message ?? [];
        $status = $messageData['status'] ?? null;

        match ($status) {
            'sent' => $this->handleSent(),
            'opened' => $this->handleOpened(),
            'submitted' => $this->handleSubmitted(),
            default => null
        };
    }

    protected function handleSent(): void
    {
        /* $messageText = $this->notificationTextAction->notificationText();

        if ($this->lead->company->get('disable_all_notifications')) {
            return;
        } */

        /*    $notification = new ActionNotification($this->message, $this->engagement);
           $notification->setTitle('Sent - ' . ucfirst($this->action));
           $notification->setMessage($messageText); */

        // (new Notify($this->lead, $notification))->all();
    }

    protected function handleOpened(): void
    {
        $messageText = $this->notificationTextAction->notificationText();

        if ($this->lead->company->get('disable_all_notifications')) {
            return;
        }

        $notification = new EngagementStatusChangedNotification($this->engagement, [
            'messageText' => $messageText,
            'action' => $this->action,
            'subject' => 'Opened - ' . ucfirst($this->action),
        ]);

        new NotifyLeadStakeholdersService($this->lead, $notification)->all();
        //(new Notify($this->lead, $notification))->all();
    }

    protected function handleSubmitted(): void
    {
        $messageText = $this->notificationTextAction->notificationText();

        if ($this->lead->company->get('disable_all_notifications')) {
            return;
        }

        $notification = new EngagementStatusChangedNotification($this->engagement, [
         'messageText' => $messageText,
         'action' => $this->action,
         'subject' => 'Submitted - ' . ucfirst($this->action),
        ]);

        new NotifyLeadStakeholdersService($this->lead, $notification)->all();
        $notificationImport = $this->lead->app->get('notification-import-types') ?? [];
        if (is_array($this->message->message) && ! empty($this->message->message)) {
            $messageArray = $this->message->message;
            if (in_array($messageArray['verb'] ?? '', $notificationImport, true)) {
                $importText = $this->lead->getFullName() . ' ' . ($messageArray['text'] ?? '') . ' is ready to be imported into eLead.';

                $notification = new EngagementStatusChangedNotification($this->engagement, [
                    'messageText' => $importText,
                    'action' => $this->action,
                    'subject' => 'Reader to Import - ' . ucfirst($this->action),
                ]);

                new NotifyLeadStakeholdersService($this->lead, $notification)->all();
            }
        }
    }
}
