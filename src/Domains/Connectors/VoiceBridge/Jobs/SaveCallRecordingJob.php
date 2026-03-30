<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Connectors\VoiceBridge\Actions\FetchCallRecordingAction;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Throwable;

class SaveCallRecordingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        protected Lead $lead,
        protected Message $message,
        protected string $callSid,
    ) {
    }

    public function handle(): void
    {
        $app = Apps::getById($this->lead->apps_id);
        $this->overwriteAppService($app);

        try {
            $recordingUrls = new FetchCallRecordingAction($this->lead, $this->callSid)->execute();

            if (empty($recordingUrls)) {
                return;
            }

            [$sid, $token] = TwilioClient::getKeysFromCompany($this->lead->company);

            $filesystemService = new FilesystemServices($app, $this->lead->company);

            $agentUserId = $this->lead->company->get('ai-agent-user-id');
            $user = $agentUserId !== null
                ? Users::getById((int) $agentUserId)
                : Users::getById($this->lead->users_id);

            $uploadedFiles = [];

            foreach ($recordingUrls as $url) {
                $response = Http::withBasicAuth($sid, $token)
                    ->timeout(60)
                    ->throw()
                    ->get($url);

                $filename = 'recording_' . $this->callSid . '_' . uniqid() . '.mp3';
                $tempPath = sys_get_temp_dir() . '/' . $filename;
                file_put_contents($tempPath, $response->body());

                try {
                    $uploadedFile = new UploadedFile(
                        $tempPath,
                        $filename,
                        'audio/mpeg',
                        null,
                        true
                    );

                    $filesystem = $filesystemService->upload($uploadedFile, $user);
                    $this->message->addFile($filesystem, 'voice-recording');
                    $uploadedFiles[] = $filesystem->url;
                } finally {
                    if (file_exists($tempPath)) {
                        @unlink($tempPath);
                    }
                }
            }

            if (! empty($uploadedFiles)) {
                $current = is_array($this->message->message)
                    ? $this->message->message
                    : json_decode((string) $this->message->message, true);

                $current['recordings'] = $uploadedFiles;
                $this->message->message = $current;
                $this->message->saveOrFail();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
