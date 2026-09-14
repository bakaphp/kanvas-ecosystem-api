<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Activities;

use Baka\Enums\StateEnums;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Enums\AppEnums;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Attach ID Verification Files To Message',
    description: 'Puts the documents produced by an ID verification back onto the message that triggered it, '
        . 'so the result is visible in the conversation.',
)]
class AttachIdVerificationFilesToMessageActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Message $message, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function (Message $message, Apps $app): array {
                $files = $this->collectFiles((array) ($message->message ?? []));

                if (empty($files)) {
                    return [
                        'result' => false,
                        'message' => 'No files found on the message payload',
                        'entity_id' => $message->getId(),
                        'files' => [],
                    ];
                }

                $attached = [];
                foreach ($files as $file) {
                    $filesystem = $this->resolveFilesystem($file['file'], $message, $app);

                    if ($filesystem !== null) {
                        $message->addFile($filesystem, $file['field']);
                    } else {
                        $message->addFileFromUrl($file['file']['url'], $file['field'], $app);
                        $filesystem = $message->getFileByName($file['field'])?->filesystem;
                    }

                    $attached[] = [
                        'field_name' => $file['field'],
                        'file_id' => $filesystem?->getId(),
                        'url' => $file['file']['url'],
                    ];
                }

                return [
                    'result' => true,
                    'message' => count($attached) . ' file(s) attached to the message',
                    'entity_id' => $message->getId(),
                    'files' => $attached,
                ];
            },
            company: $message->company,
        );
    }

    /**
     * @return array<int, array{file: array, field: string}>
     */
    private function collectFiles(array $content): array
    {
        if (! is_array($content['data'] ?? null)) {
            return [];
        }

        $collected = [];

        foreach ($content['data'] as $group) {
            if (! is_array($group) || ! is_array($group['files'] ?? null)) {
                continue;
            }

            $files = array_values(array_filter(
                $group['files'],
                fn ($file) => is_array($file) && ! empty($file['url'])
            ));

            foreach ($files as $index => $file) {
                $collected[] = [
                    'file' => $file,
                    'field' => $this->resolveFieldName($group, $file, $index, count($files)),
                ];
            }
        }

        return $collected;
    }

    private function resolveFieldName(array $group, array $file, int $index, int $total): string
    {
        $typeName = (string) ($group['type']['name'] ?? '');

        if ($total === 2 && str_contains(strtolower($typeName), 'license')) {
            return $index === 0 ? 'drivers_license_front' : 'drivers_license_back';
        }

        $base = $typeName !== ''
            ? Str::simpleSlug($typeName)
            : (string) ($file['attributes']['verb'] ?? ConfigurationEnum::ID_VERIFICATION->value);

        return $base . '-' . ($index + 1);
    }

    private function resolveFilesystem(array $file, Message $message, Apps $app): ?Filesystem
    {
        $fileId = (int) ($file['id'] ?? 0);

        if ($fileId > 0) {
            $byId = $this->filesystemQuery($message, $app)->where('id', $fileId)->first();

            if ($byId !== null) {
                return $byId;
            }
        }

        return $this->filesystemQuery($message, $app)->where('url', $file['url'])->first();
    }

    private function filesystemQuery(Message $message, Apps $app): Builder
    {
        return Filesystem::query()
            ->where('apps_id', $app->getId())
            ->whereIn('companies_id', [
                $message->companies_id,
                AppEnums::GLOBAL_COMPANY_ID->getValue(),
            ])
            ->where('is_deleted', StateEnums::NO->getValue());
    }
}
