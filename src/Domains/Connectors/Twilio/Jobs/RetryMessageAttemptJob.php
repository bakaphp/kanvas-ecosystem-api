<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Connectors\Twilio\Models\MessageAttempt;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

final class RetryMessageAttemptJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly int $attemptId)
    {
    }

    public function handle(): void
    {
        $attempt = MessageAttempt::query()->find($this->attemptId);
        if (! $attempt instanceof MessageAttempt
            || $attempt->remediation_action !== 'retry_scheduled'
            || $attempt->retry_number !== 0
        ) {
            return;
        }

        $app = Apps::getById($attempt->apps_id);
        $this->overwriteAppService($app);

        $message = $attempt->message;
        $lead = $attempt->lead_id !== null ? Lead::getById($attempt->lead_id) : null;
        if (! $message instanceof Message || ! $lead instanceof Lead) {
            $this->cancel($attempt, 'retry_canceled_missing_context');

            return;
        }

        if ($this->hasNewerConversationActivity($attempt, $message)) {
            $this->cancel($attempt, 'retry_canceled_superseded');

            return;
        }

        $content = $message->message['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            $this->cancel($attempt, 'retry_canceled_missing_content');

            return;
        }

        try {
            $result = (new SendMessageToLeadAction($lead))
                ->withRetryContext($attempt->getId())
                ->execute(
                    channel: LeadCommunicationChannelEnum::SMS->value,
                    message: $content,
                    from: $attempt->from_number,
                    to: $attempt->to_number,
                );

            new StoreMessageSidAction($message)->execute($result);

            $attempt->remediation_action = 'retry_sent';
            $attempt->saveOrFail();
        } catch (Throwable $exception) {
            $attempt->remediation_action = 'retry_failed';
            $attempt->last_error_message = $exception->getMessage();
            $attempt->saveOrFail();
            report($exception);
        }
    }

    private function hasNewerConversationActivity(MessageAttempt $attempt, Message $message): bool
    {
        $newerAttemptExists = MessageAttempt::query()
            ->where('companies_id', $attempt->companies_id)
            ->where('lead_id', $attempt->lead_id)
            ->where('to_number', $attempt->to_number)
            ->where('id', '!=', $attempt->getId())
            ->where('created_at', '>', $attempt->created_at)
            ->exists();

        if ($newerAttemptExists) {
            return true;
        }

        $channelIds = $message->channels()->pluck('channels.id');
        if ($channelIds->isEmpty()) {
            return false;
        }

        return Message::query()
            ->whereHas('channels', fn ($query) => $query->whereIn('channels.id', $channelIds))
            ->where('messages.id', '!=', $message->getId())
            ->where('messages.created_at', '>', $message->created_at)
            ->where('messages.is_deleted', false)
            ->exists();
    }

    private function cancel(MessageAttempt $attempt, string $action): void
    {
        $attempt->remediation_action = $action;
        $attempt->saveOrFail();
    }
}
