<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Services\RecombeeIndexService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Throwable;

class FixPromptDataCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:promptmine-fix-prompt-data {app_id} {companies_id} {message_type_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Fix promptmine prompt data';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $messageTypeId = (int) $this->argument('message_type_id');
        $messageType = MessageType::find($messageTypeId);
        $companiesId = (int) $this->argument('companies_id');

        //Get all messages for the given message type and app
        $this->SyncPromptData($app, $messageType, $companiesId);
    }

    private function SyncPromptData($app, $messageType, $companiesId): void
    {
        Message::fromApp($app)
            ->where('message_types_id', $messageType->getId())
            ->where('companies_id', $companiesId)
            ->orderBy('id', 'asc')
            ->chunk(100, function ($messages) {
                foreach ($messages as $message) {
                    try {
                        $this->fixPromptData($message);
                        $this->info('-Message ID: ' . $message->getId() . ' updated');

                        if (count($message->children) == 0) {
                            continue;
                        }
                        foreach ($message->children as $childMessage) {
                            $this->fixNuggetData($childMessage);
                            $this->info('--Child Message ID: ' . $childMessage->getId() . ' updated');
                        }
                    } catch (Throwable $e) {
                        $this->error('Error updating message ID: ' . $message->getId() . ' - ' . $e->getMessage());
                    }
                }
            });
    }

    private function fixPromptData(Message $message): void
    {
        $messageData = is_array($message->message) ? $message->message : json_decode($message->message, true);

        if (! isset($messageData['ai_model'])) {
            $messageData['ai_model'] = [
                'name' => 'gpt-3.5-turbo',
                'value' => 'gpt-3.5-turbo',
                'icon' => 'https://cdn.openai.com/papers/gpt-3.5-turbo.png',
                'payment' => [
                    'price' => 0,
                    'is_locked' => false,
                    'free_regeneration' => false
                ]
            ];
        }

        if (! isset($messageData['type'])) {
            $messageData['type'] = 'text-format';
        }

        if (isset($messageData['preview'])) {
            unset($messageData['preview']);
        }

        if ($message->is_premium && ! isset($messageData['payment'])) {
            $messageData['payment'] = [
                'price' => 0,
                'is_locked' => false,
                'free_regeneration' => false
            ];
        }

        $message->message = $messageData;
        $message->save();
    }

    private function fixNuggetData(Message $message): void
    {
        $messageData = is_array($message->message) ? $message->message : json_decode($message->message, true);

        $message->message = $messageData;
        $message->save();
    }
}
