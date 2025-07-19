<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Baka\Support\Str;
use Exception;
use Kanvas\Guild\Customers\Models\People;
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
        // Get the last submitted message or the latest child
        $lastMessage = $this->getLastSubmittedMessage($message) ?? $message;

        $files = $this->getFilesFromMessage($lastMessage);
        $notificationName = null;

        try {
            // Get engagement information if available
            $companyAction = null;
            $stages = null;
            $verb = null;
            $action = 'Message Files';
            $status = 'submitted';

            // Try to get engagement data

            $engagement = $lastMessage->getEngagement();
            if ($engagement->companyAction) {
                $companyAction = $engagement->companyAction;
                $verb = $companyAction->actions->slug ?? null;
                $action = $companyAction->actions->name ?? 'Message Files';
            }
            if ($engagement->stage) {
                $stages = $engagement->stage;
                $status = $stages->slug ?? 'submitted';
            }

            // Get participant name if different from lead people
            try {
                if ($message->get('people_id') && $message->get('people_id') != $this->lead->people_id) {
                    $coBuyerPeople = People::getByIdOrFail($message->get('people_id'));
                    $notificationName = ' (' . $coBuyerPeople->name . ')';
                }
            } catch (Exception $e) {
                $notificationName = null;
            }

            // Get message data
            $messageForm = $lastMessage->message;
            $metadata = [];
            if (is_array($messageForm) && key_exists('data', $messageForm)) {
                $metadata = $messageForm['data'];
            }
        } catch (Exception $e) {
            $verb = 'message';
            $action = 'Message Files';
            $status = 'submitted';
            $metadata = [];
        }

        return [
            'id' => (string) $message->getId(),
            'uuid' => $message->uuid,
            'verb' => $verb ?? 'message',
            'action' => $action . ($notificationName ?? ''),
            'status' => $status,
            'participant_name' => $notificationName ? trim($notificationName, ' ()') : null,
            'created_at' => $message->created_at->format('Y-m-d H:i:s'),
            'last_message_at' => $lastMessage->created_at->format('Y-m-d H:i:s'),
            'files' => $this->removeDuplicateFiles($files, $notificationName),
            'metadata' => $metadata,
        ];
    }

    protected function getLeadFileGroup(): array
    {
        // Get lead files using the standard files relationship
        $leadFiles = $this->lead->files()->get();

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
            // Get co-buyer participants
            $coBuyerParticipants = $this->lead->participants()
                ->whereHas('type', function ($query) {
                    $query->where('name', 'Co-buyer');
                })
                ->get();

            foreach ($coBuyerParticipants as $participant) {
                $people = $participant->people;

                // Get participant files using the standard files relationship
                $participantFiles = $people->files()->get();

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
        } catch (Throwable $e) {
            // Ignore errors in participant processing
        }

        return $groups;
    }

    protected function getLastSubmittedMessage(Message $message): ?Message
    {
        // Look for messages with status 'submitted'
        $lastMessage = Message::where('parent_id', $message->getId())
            ->whereJsonContains('message->status', 'submitted')
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
                'id' => $file->filesystem_id,
                'name' => $file->name,
                'url' => $file->url,
                'file_type' => $file->file_type ?? '',
                'size' => $file->size ?? 0,
                'field_name' => $file->pivot->field_name ?? '',
                'attributes' => $file->attributes ?? [],
            ];
        })->toArray();
    }

    protected function formatFiles(array $files): array
    {
        return array_map(function ($file) {
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
        }, $files);
    }

    protected function removeDuplicateFiles(array $files, ?string $name = null): array
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

        // Convert to format expected by GraphQL
        return array_map(function ($file) {
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
        }, array_values($uniqueFiles));
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
