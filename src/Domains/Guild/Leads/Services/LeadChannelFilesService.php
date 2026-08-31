<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Baka\Support\Str;
use Exception;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Exceptions\MissingParticipantPeopleIdException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

class LeadChannelFilesService
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function getChannelFiles(array $options = []): array
    {
        $includeParticipants = $options['includeParticipants'] ?? true;
        $groupByAction = $options['groupByAction'] ?? true;

        $groups = [];

        // Get files from messages in lead channels
        $messageGroups = $this->getMessageFileGroups();
        $groups = array_merge($groups, $messageGroups);

        // Add lead files (mainbuyer uploads)
        $leadFileGroup = $this->getLeadFileGroup();
        if (! empty($leadFileGroup['files'])) {
            $groups[] = $leadFileGroup;
        }

        // Add participant files (co-buyer uploads)
        if ($includeParticipants) {
            $participantGroups = $this->getParticipantFileGroups();
            $groups = array_merge($groups, $participantGroups);
        }

        // Sort by last_message_at desc
        if ($groupByAction) {
            usort($groups, function ($a, $b) {
                return strtotime($b['last_message_at']) - strtotime($a['last_message_at']);
            });
        }

        return [
            'total_groups' => count($groups),
            'groups' => $groups,
        ];
    }

    protected function getMessageFileGroups(): array
    {
        $groups = [];

        // Get lead's first social channel only
        $channel = $this->lead->socialChannels()->first();

        if (! $channel) {
            return $groups; // No channel found, return empty
        }

        // Use the proper relationship - get messages through the channel's messages relationship
        $messages = $channel->messages()
            ->where(function ($query) {
                $query->where('messages.parent_id', 0)->orWhereNull('messages.parent_id');
            })
            ->orderBy('messages.id', 'desc')
            ->get();

        foreach ($messages as $message) {
            $group = $this->formatMessageFileGroup($message);
            if (! empty($group['files'])) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    protected function formatMessageFileGroup(Message $message): array
    {
        $lastMessage = $this->getLastSubmittedMessage($message) ?? $message;

        $files = $this->getFilesFromMessage($lastMessage);
        $verb = 'message';
        $action = 'Message Files';
        $status = 'submitted';
        $metadata = [];

        //a message with no engagement is a plain conversation message, it keeps the generic labels
        try {
            $engagement = $lastMessage->getEngagement();
        } catch (Exception) {
            $engagement = null;
        }

        if ($engagement?->companyAction) {
            $companyAction = $engagement->companyAction;
            $verb = $companyAction->action->slug ?? $verb;
            $action = $companyAction->name ?: ($companyAction->action->name ?? $action);
        }

        if ($engagement?->stage) {
            $status = $engagement->stage->slug ?? $status;
        }

        $messageForm = $lastMessage->message;
        if (is_array($messageForm) && key_exists('data', $messageForm)) {
            $metadata = $messageForm['data'];
        }

        $participant = $this->resolveParticipant($engagement, $lastMessage, $message);

        return [
            'id' => (string) $message->getId(),
            'uuid' => $message->uuid,
            'verb' => $verb,
            'action' => $participant ? $action . ' (' . $participant->name . ')' : $action,
            'status' => $status,
            'participant_name' => $participant?->name,
            'created_at' => $message->created_at->format('Y-m-d H:i:s'),
            'last_message_at' => $lastMessage->created_at->format('Y-m-d H:i:s'),
            'files' => $this->removeDuplicateFiles($files),
            'metadata' => $metadata,
        ];
    }

    /**
     * The engagement's people_id — or the message's own people_id custom field, which is what the
     * showroom ID verification flow writes — points at the participant that submitted the action.
     * A co-buyer's ID verification belongs to them, not to the lead's main buyer.
     */
    protected function resolveParticipant(?Engagement $engagement, Message $lastMessage, Message $message): ?People
    {
        //`?:` and not `??`: engagement.people_id is 0 on legacy rows and 0 would never fall through `??`
        $peopleId = (int) ($engagement?->people_id ?: 0)
            ?: (int) ($lastMessage->get('people_id') ?: 0);

        //every get() is a custom field lookup, so only reach for the thread parent when it is another row
        if (! $peopleId && ! $lastMessage->is($message)) {
            $peopleId = (int) ($message->get('people_id') ?: 0);
        }

        if (! $peopleId || $peopleId === (int) $this->lead->people_id) {
            return null;
        }

        try {
            return People::getByIdFromCompanyApp($peopleId, $this->lead->company, $this->lead->app);
        } catch (Throwable) {
            if ($this->lead->participants()->where('peoples_id', $peopleId)->exists()) {
                throw new MissingParticipantPeopleIdException(
                    'Lead ' . $this->lead->getId() . ' participant people_id ' . $peopleId . ' does not resolve'
                );
            }

            return null;
        }
    }

    protected function getLeadFileGroup(): array
    {
        // Get lead files using the standard files relationship
        $leadFiles = $this->lead->getFiles();

        return [
            'id' => (string) $this->lead->getId(),
            'uuid' => $this->lead->uuid,
            'verb' => 'upload',
            'action' => 'Uploads Mainbuyer',
            'status' => 'submitted',
            'participant_name' => null,
            'created_at' => $this->lead->created_at->format('Y-m-d H:i:s'),
            'last_message_at' => $this->lead->created_at->format('Y-m-d H:i:s'),
            'files' => $this->formatFiles($leadFiles->toArray()),
            'metadata' => [],
        ];
    }

    protected function getParticipantFileGroups(): array
    {
        $groups = [];

        try {
            $coBuyerParticipants = $this->lead->participants()
                ->whereHas('type', function ($query) {
                    $query->where('name', 'Co-buyer');
                })
                ->get();

            foreach ($coBuyerParticipants as $participant) {
                $people = $participant->people;

                if ($people === null) {
                    throw new MissingParticipantPeopleIdException(
                        'Lead ' . $this->lead->getId() . ' participant ' . $participant->getId()
                        . ' points at peoples_id ' . $participant->peoples_id . ' which does not resolve'
                    );
                }

                $participantFiles = $people->getFiles();

                if ($participantFiles->isNotEmpty()) {
                    $groups[] = [
                        'id' => (string) $this->lead->getId(),
                        'uuid' => $this->lead->uuid,
                        'verb' => 'co-buyer-upload',
                        'action' => 'Uploads Cobuyer (' . $people->name . ')',
                        'status' => 'submitted',
                        'participant_name' => $people->name,
                        'created_at' => $this->lead->created_at->format('Y-m-d H:i:s'),
                        'last_message_at' => $this->lead->created_at->format('Y-m-d H:i:s'),
                        'files' => $this->formatFiles($participantFiles->toArray()),
                        'metadata' => [],
                    ];
                }
            }
        } catch (MissingParticipantPeopleIdException $e) {
            //the generic catch below is a Throwable, so the integrity signal has to be let through first
            throw $e;
        } catch (Throwable) {
            // Ignore errors in participant processing
        }

        return $groups;
    }

    protected function getLastSubmittedMessage(Message $message): ?Message
    {
        // Look for messages with status 'submitted'
        // Use JSON_VALID check to avoid errors on non-JSON message columns
        $lastMessage = Message::where('parent_id', $message->getId())
            ->whereRaw('IF(JSON_VALID(`message`), JSON_CONTAINS(`message`, \'"submitted"\', \'$."status"\'), 0)')
            ->orderBy('id', 'desc')
            ->first();

        if (! $lastMessage) {
            // Fallback to latest child message
            $lastMessage = Message::where('parent_id', $message->getId())
                ->orderBy('id', 'desc')
                ->first();
        }

        return $lastMessage;
    }

    protected function getFilesFromMessage(Message $message): array
    {
        if (! method_exists($message, 'getFiles')) {
            return [];
        }

        $files = $message->getFiles();
        if (! $files) {
            return [];
        }

        return $files->map(function ($file) {
            return [
                'id' => $file->id,
                'name' => $file->name,
                'url' => $file->url,
                'file_type' => $file->file_type ?? '',
                'size' => $file->size ?? 0,
                //getFiles() returns FilesystemEntities rows with the filesystem columns joined in, not
                //a belongsToMany, so field_name is a column on the row itself and there is no pivot
                'field_name' => $file->field_name ?? '',
                'attributes' => $file->attributes ?? [],
            ];
        })->toArray();
    }

    protected function formatFiles(array $files): array
    {
        return array_map(fn (array $file): array => $this->presentFile($file), $files);
    }

    protected function presentFile(array $file): array
    {
        return [
            'id' => (string) ($file['id'] ?? ''),
            'name' => $file['name'] ?? '',
            'url' => $file['url'] ?? '',
            'file_type' => $file['file_type'] ?? '',
            'size' => $file['size'] ?? 0,
            'field_name' => $file['field_name'] ?? '',
            'verification_status' => $file['attributes']['id_verification_status'] ?? null,
            'verification_message' => $file['attributes']['id_verification_msg'] ?? null,
            'attributes' => $file['attributes'] ?? [],
        ];
    }

    protected function removeDuplicateFiles(array $files): array
    {
        $uniqueFiles = [];
        $hasIdVerificationMsg = false;
        $idVerificationFileMessage = [];

        foreach ($files as $file) {
            // Skip files from mycreditdrive.com (legacy filter)
            if (isset($file['url']) && Str::contains($file['url'], 'mycreditdrive.com')) {
                continue;
            }

            $fileId = $file['id'] ?? $file['filesystem_id'] ?? uniqid();
            if (! isset($uniqueFiles[$fileId])) {
                $uniqueFiles[$fileId] = $file;
            }

            // Handle ID verification messages
            if (isset($file['attributes']['id_verification_msg'])) {
                $hasIdVerificationMsg = true;

                if (isset($file['attributes']['id_verification_status']) &&
                    $file['attributes']['id_verification_status'] == 'passed') {
                    $file['attributes']['id_verification_status'] = 'green';
                }

                $idVerificationFileMessage = [
                    'id_verification_msg' => $file['attributes']['id_verification_msg'],
                    'id_verification_status' => $file['attributes']['id_verification_status'] ?? null,
                ];
            }
        }

        // Apply ID verification message to all files if one has it
        if ($hasIdVerificationMsg) {
            foreach ($uniqueFiles as &$file) {
                if (! isset($file['attributes']['id_verification_msg'])) {
                    $file['attributes']['id_verification_msg'] = $idVerificationFileMessage['id_verification_msg'];
                    $file['attributes']['id_verification_status'] = $idVerificationFileMessage['id_verification_status'];
                }
            }
        }

        return array_map(fn (array $file): array => $this->presentFile($file), array_values($uniqueFiles));
    }

    /**
     * Check if lead has channel files.
     */
    public function hasChannelFiles(): bool
    {
        $result = $this->getChannelFiles(['includeParticipants' => false, 'groupByAction' => false]);

        return $result['total_groups'] > 0;
    }

    /**
     * Get total file count across all groups.
     */
    public function getTotalFileCount(): int
    {
        $result = $this->getChannelFiles();
        $totalFiles = 0;

        foreach ($result['groups'] as $group) {
            $totalFiles += count($group['files']);
        }

        return $totalFiles;
    }
}
