<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\WaSender\Client;
use Kanvas\Connectors\WaSender\Traits\FormatsPhoneNumberTrait;

/**
 * Group directory and membership. Sending into a group goes through MessageService — WaSender's
 * `/api/send-message` takes a group JID in `to` exactly like a phone number, so a second send path
 * here would only be a copy that can drift.
 */
class GroupService
{
    use FormatsPhoneNumberTrait;

    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Get all WhatsApp groups the connected account is a member of.
     */
    public function getAllGroups(): array
    {
        return $this->client->get('/api/groups');
    }

    /**
     * Get metadata for a specific group.
     *
     * @param string $groupJid Group ID (e.g., '123456789-987654321@g.us')
     */
    public function getGroupMetadata(string $groupJid): array
    {
        return $this->client->get("/api/groups/{$groupJid}/metadata");
    }

    /**
     * Get participants for a specific group.
     *
     * @param string $groupJid Group ID (e.g., '123456789-987654321@g.us')
     */
    public function getGroupParticipants(string $groupJid): array
    {
        return $this->client->get("/api/groups/{$groupJid}/participants");
    }

    /**
     * Add participants to a specific group. Requires admin privileges.
     *
     * @param string $groupJid Group ID (e.g., '123456789-987654321@g.us')
     * @param array $participants Array of participant phone numbers in E.164 format
     */
    public function addGroupParticipants(string $groupJid, array $participants): array
    {
        // Format phone numbers to ensure they're in the correct format
        $formattedParticipants = array_map(
            fn ($number) => $this->formatPhoneNumber($number),
            $participants
        );

        return $this->client->post("/api/groups/{$groupJid}/participants/add", [
            'participants' => $formattedParticipants,
        ]);
    }

    /**
     * Remove participants from a specific group. Requires admin privileges.
     *
     * @param string $groupJid Group ID (e.g., '123456789-987654321@g.us')
     * @param array $participants Array of participant phone numbers in E.164 format
     */
    public function removeGroupParticipants(string $groupJid, array $participants): array
    {
        // Format phone numbers to ensure they're in the correct format
        $formattedParticipants = array_map(
            fn ($number) => $this->formatPhoneNumber($number),
            $participants
        );

        return $this->client->post("/api/groups/{$groupJid}/participants/remove", [
            'participants' => $formattedParticipants,
        ]);
    }

    /**
     * Update settings for a specific group. Requires admin privileges.
     *
     * @param string $groupJid Group ID (e.g., '123456789-987654321@g.us')
     * @param string|null $subject New group subject
     * @param string|null $description New group description
     * @param bool|null $announce Set to true for admin-only messages
     * @param bool|null $restrict Set to true to restrict editing group info to admins
     */
    public function updateGroupSettings(
        string $groupJid,
        ?string $subject = null,
        ?string $description = null,
        ?bool $announce = null,
        ?bool $restrict = null
    ): array {
        $data = [];

        if ($subject !== null) {
            $data['subject'] = $subject;
        }

        if ($description !== null) {
            $data['description'] = $description;
        }

        if ($announce !== null) {
            $data['announce'] = $announce;
        }

        if ($restrict !== null) {
            $data['restrict'] = $restrict;
        }

        return $this->client->put("/api/groups/{$groupJid}/settings", $data);
    }

    /**
     * Check if the connected account is an admin in a specific group.
     *
     * @param string $groupJid Group ID (e.g., '123456789-987654321@g.us')
     * @return bool True if the connected account is an admin, false otherwise
     */
    public function isGroupAdmin(string $groupJid): bool
    {
        try {
            $participants = $this->getGroupParticipants($groupJid);

            if (! isset($participants['data']) || ! is_array($participants['data'])) {
                return false;
            }

            // Get own phone number (this is just a placeholder, actual implementation depends on API)
            $ownInfo = $this->client->get('/api/contacts/me');
            $ownJid = $ownInfo['data']['jid'] ?? null;

            if (! $ownJid) {
                return false;
            }

            // Check if own number is in admin list
            foreach ($participants['data'] as $participant) {
                if (($participant['jid'] === $ownJid) && ($participant['isAdmin'] || $participant['isSuperAdmin'])) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Find a group by name.
     *
     * @param string $groupName Name of the group to find
     * @return array|null Group data or null if not found
     */
    public function findGroupByName(string $groupName): ?array
    {
        $groups = $this->getAllGroups();

        if (! isset($groups['data']) || ! is_array($groups['data'])) {
            return null;
        }

        foreach ($groups['data'] as $group) {
            if (isset($group['name']) && strtolower($group['name']) === strtolower($groupName)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Create a group link invite.
     * Note: This endpoint may not be available in the API, but would be useful
     *
     * @param string $groupJid Group ID (e.g., '123456789-987654321@g.us')
     */
    public function createGroupInviteLink(string $groupJid): array
    {
        // This endpoint might not be documented but is common in WhatsApp APIs
        // Adjust the endpoint as needed based on actual API documentation
        return $this->client->post("/api/groups/{$groupJid}/invite-link", []);
    }
}
