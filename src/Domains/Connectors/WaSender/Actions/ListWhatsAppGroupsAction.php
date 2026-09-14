<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Kanvas\Connectors\WaSender\Enums\ConnectionFieldEnum;
use Kanvas\Connectors\WaSender\Enums\GroupConfigEnum;
use Kanvas\Connectors\WaSender\Services\GroupService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * Every group the connected number belongs to, flagged with whether the agent currently listens.
 *
 * Live from WhatsApp rather than from our own channels, for two reasons: a group we have never
 * ingested has no Kanvas row to list, and inbound group payloads carry the JID alone — this is the
 * only place a human-readable group name exists.
 */
class ListWhatsAppGroupsAction
{
    public function __construct(
        protected readonly Agent $agent,
        /** Injectable so a test can list groups without reaching WhatsApp. */
        protected readonly ?GroupService $groups = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        $allowed = $this->allowedJids();
        $groups = [];

        foreach ($this->fetchGroups() as $group) {
            if (! is_array($group)) {
                continue;
            }

            $jid = $group['id'] ?? $group['jid'] ?? null;

            if (! is_string($jid) || $jid === '') {
                continue;
            }

            $groups[] = [
                'jid' => $jid,
                'name' => $this->name($group),
                'participants_count' => $this->participantsCount($group),
                'is_allowed' => in_array($jid, $allowed, true),
            ];
        }

        return $groups;
    }

    /**
     * @return array<int, mixed>
     */
    private function fetchGroups(): array
    {
        $groupService = $this->groups
            ?? new GroupService($this->agent->app, $this->agent->company);

        $response = $groupService->getAllGroups();

        // WaSender wraps collections in a `data` envelope on some endpoints and not others.
        $groups = $response['data'] ?? $response;

        return is_array($groups) ? array_values($groups) : [];
    }

    /**
     * @return list<string>
     */
    private function allowedJids(): array
    {
        $receiverId = (int) $this->agent->get(ConnectionFieldEnum::RECEIVER_ID->value);
        $receiver = $receiverId > 0 ? ReceiverWebhook::find($receiverId) : null;

        // An agent with no connection yet simply has nothing allow-listed.
        return $receiver instanceof ReceiverWebhook
            ? GroupConfigEnum::ALLOWED_GROUP_JIDS->getList($receiver)
            : [];
    }

    /**
     * @param array<string, mixed> $group
     */
    private function name(array $group): ?string
    {
        $name = $group['name'] ?? $group['subject'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param array<string, mixed> $group
     */
    private function participantsCount(array $group): ?int
    {
        $participants = $group['participants'] ?? null;

        if (is_array($participants)) {
            return count($participants);
        }

        $count = $group['participants_count'] ?? $group['size'] ?? null;

        return is_numeric($count) ? (int) $count : null;
    }
}
