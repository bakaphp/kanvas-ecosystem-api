<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Social\Messages\Events\MessageMentionsStoredEvent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\UsersAssociatedApps;

class ParseMessageMentionsAction
{
    public function __construct(
        private readonly Message $message,
    ) {
    }

    /**
     * @return list<int> the mentioned user ids
     */
    public function execute(): array
    {
        $content = $this->message->contentText();

        $handles = $this->extractHandles($content);
        if ($handles === []) {
            return [];
        }

        $mentionedUserIds = $this->resolveMentions($handles);
        if ($mentionedUserIds === []) {
            return [];
        }

        // Record out-of-band — rewriting the body would clobber non-object formats.
        $this->message->set('mentions', $mentionedUserIds);
        foreach ($mentionedUserIds as $userId) {
            $this->message->addTag('mention:' . $userId);
        }

        MessageMentionsStoredEvent::dispatch($this->message, $mentionedUserIds);

        return $mentionedUserIds;
    }

    /**
     * `@handle` tokens from the text (may be HTML). Space-containing display names can't be
     * matched from free text.
     *
     * @return list<string>
     */
    private function extractHandles(string $content): array
    {
        if (! str_contains($content, '@')) {
            return [];
        }

        if (preg_match_all('/@([\p{L}\p{N}._+\-]+)/u', $content, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * By app only — `displayname` is unique per app and often lives at the app-global
     * `companies_id = 0`. The listener re-scopes the user to the company to confirm it's an agent.
     *
     * @param list<string> $handles
     *
     * @return list<int>
     */
    private function resolveMentions(array $handles): array
    {
        $appUsers = UsersAssociatedApps::query()
            ->where('apps_id', $this->message->apps_id)
            ->whereIn('displayname', $handles)
            ->get(['users_id', 'displayname']);

        $mentioned = [];
        foreach ($appUsers as $appUser) {
            $mentioned[] = $appUser->users_id;
        }

        return array_values(array_unique($mentioned));
    }
}
