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
                "name" => "GPT-4o",
                "key" => "openai",
                "value" => "gpt-4o",
                'icon' => "https://cdn.promptmine.ai/OpenAILogo.png",
                'payment' => [
                    'price' => 0,
                    'is_locked' => false,
                    'free_regeneration' => false
                ]
            ];
        }

        if (! isset($messageData['type'])) {

            //Check if child message has image field, then set type to image
            if (isset($message->children) && count($message->children) > 0) {
                foreach ($message->children as $childMessage) {
                    $childMessageData = is_array($childMessage->message) ? $childMessage->message : json_decode($childMessage->message, true);
                    if (isset($childMessageData['image']) && ! empty($childMessageData['image'])) {
                        $messageData['type'] = 'image-format';
                        break;
                    }
                }
            } else {
                $messageData['type'] = 'text-format';
            }
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
        $parentMessageData = is_array($message->parent->message) ? $message->parent->message : json_decode($message->parent->message, true);
        $messageData = is_array($message->message) ? $message->message : json_decode($message->message, true);

        if (! isset($messageData['id'])) {
            $messageData['id'] = $message->getId();
        }

        if (! isset($messageData['title']) && isset($parentMessageData['title'])) {
            $messageData['title'] = $parentMessageData['title'];
        }

        if(! isset($messageData['type']) && isset($parentMessageData['type'])) {
            $messageData['type'] = $parentMessageData['type'];
            if ($parentMessageData['type'] == 'image-format') {
                $messageData['image'] = '';
            } else {
                $messageData['nugget'] = ""; // Check how we are going to generate the missing nugget from prompts?
            }
        }

        $message->message = $messageData;
        $message->save();
    }
}
