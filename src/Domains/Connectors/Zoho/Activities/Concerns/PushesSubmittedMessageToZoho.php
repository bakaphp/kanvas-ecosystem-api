<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Activities\Concerns;

use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Engagements\Actions\AttachGetDocsFilesToMessageAction;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Tasks\Actions\ProcessMessageTaskUpdatesAction;
use Kanvas\Connectors\Zoho\Actions\PushFilesToZohoRecordAction;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Throwable;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

trait PushesSubmittedMessageToZoho
{
    /**
     * Targets resolve lazily so the Zoho client is only built when there is something to push.
     *
     * @param callable(): array<string, ZohoRecordsModule> $resolveTargets keyed by Zoho record id
     */
    protected function pushSubmittedMessage(Message $message, callable $resolveTargets): ?array
    {
        $messageData = $message->getMessage();

        if (($messageData['status'] ?? '') !== ActionStatusEnum::SUBMITTED->value) {
            return null;
        }

        return match ($messageData['verb'] ?? '') {
            ActionEnum::GET_DOCS->value => $this->pushGetDocs($message, $resolveTargets),
            default => null,
        };
    }

    /**
     * @param callable(): array<string, ZohoRecordsModule> $resolveTargets
     */
    protected function pushGetDocs(Message $message, callable $resolveTargets): array
    {
        $attachGetDocs = new AttachGetDocsFilesToMessageAction($message);
        $files = $attachGetDocs->execute();

        if ($files->isEmpty()) {
            return ['files' => 0];
        }

        // The funding team sorts Zoho attachments by what was asked for, and two document types can
        // carry the same customer filename.
        $documentTypes = $attachGetDocs->documentTypes();
        $zohoFileNames = $files->mapWithKeys(
            fn (Filesystem $file) => [$file->getId() => $documentTypes[$file->getId()] . ' - ' . $file->name]
        )->all();

        $synced = $message->get(CustomFieldEnum::ZOHO_MESSAGE_SYNC_FILES->value) ?? [];
        $results = [];

        foreach ($resolveTargets() as $zohoRecordId => $module) {
            $pushed = new PushFilesToZohoRecordAction($module, (string) $zohoRecordId)->execute(
                $files,
                $synced[$zohoRecordId] ?? [],
                $zohoFileNames
            );

            $synced[$zohoRecordId] = array_merge($synced[$zohoRecordId] ?? [], $pushed['uploaded']);
            $results[$zohoRecordId] = $pushed;
        }

        // Retries must not re-upload what already reached Zoho.
        $message->set(CustomFieldEnum::ZOHO_MESSAGE_SYNC_FILES->value, $synced);

        return [
            'files' => $files->count(),
            'zoho' => $results,
        ];
    }

    /**
     * @return array<string, ZohoRecordsModule>
     */
    protected function zohoLeadTarget(ZohoService $zohoService, ?Lead $lead): array
    {
        $zohoLeadId = $lead?->get(CustomFieldEnum::ZOHO_LEAD_ID->value);

        return $zohoLeadId ? [(string) $zohoLeadId => $zohoService->getLeadModule()] : [];
    }

    /**
     * @return array<string, ZohoRecordsModule>
     */
    protected function zohoDealTarget(ZohoService $zohoService, Deal $deal): array
    {
        $zohoDealId = $deal->get(CustomFieldEnum::ZOHO_DEAL_ID->value);

        return $zohoDealId ? [(string) $zohoDealId => $zohoService->getDealModule()] : [];
    }

    protected function processTaskUpdates(Message $message, Lead $lead): mixed
    {
        try {
            return new ProcessMessageTaskUpdatesAction(
                message: $message,
                lead: $lead,
                user: $message->user,
            )->execute();
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
