<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use EchoLabs\Prism\Enums\Provider;
use EchoLabs\Prism\Prism;
use Illuminate\Support\Facades\DB;
use Kanvas\Social\Messages\Validations\MessageSchemaValidator;
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

    /**
     * @todo how to avoid changing legit prompts and nugget data? Use the json validator?
     */
    private function SyncPromptData($app, $messageType, $companiesId): void
    {
        Message::fromApp($app)
            ->where('message_types_id', $messageType->getId())
            ->where('companies_id', $companiesId)
            ->where('is_deleted', 0)
            ->orderBy('id', 'asc')
            ->chunk(20, function ($messages) {
                foreach ($messages as $message) {

                    try {
                        $this->fixPromptData($message);

                        if (count($message->children) == 0) {
                            //Generate child messages if it doesn't exist
                            $this->createNuggetMessage($message);
                            $this->info('--Child Nugget Message ID: ' . $message->getId() . ' created');
                            continue;
                        }

                        foreach ($message->children as $childMessage) {

                            $validateMessageSchema = new MessageSchemaValidator($childMessage, MessageType::find(576), true);
                            
                            $this->info('--Checking Child Nugget Message Schema of ID: ' . $childMessage->getId());
                            if ($validateMessageSchema->validate()) {
                                $this->info('--Message Schema is OK');
                                continue;
                            }

                            $this->info('--Fixing Child Nugget Message Schema');
                            $this->fixNuggetData($childMessage);
                            $this->info('--Child Nugget Message ID: ' . $childMessage->getId() . ' updated');
                        }
                    } catch (Throwable $e) {
                        $this->error('Error updating message ID: ' . $message->getId() . ' - ' . $e->getMessage());
                    }
                }
                die();
            });
    }

    private function fixPromptData(Message $message): void
    {
        $messageData = is_array($message->message) ? $message->message : json_decode($message->message, true);
        $validateMessageSchema = new MessageSchemaValidator($message, MessageType::find($message->message_types_id), true);

        $this->info('--Checking Prompt Message Schema of ID: ' . $message->getId());
        if ($validateMessageSchema->validate()) {
            $this->info('-- Prompt Message Schema is OK');
            return;
        }
        //Anything that is not a prompt, set as deleted
        if (! isset($messageData['prompt'])) {
            $message->is_deleted = 1;
            $message->is_public = 0;
            $message->save();
            $this->info('Message is not a prompt, setting as deleted and not public');
            return;
        }

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
            $this->info('Added AI model to message data');
        }

        if (! isset($messageData['type'])) {
            $messageData['type'] = 'text-format';
            //Check if child message has image field, then set type to image
            // if (isset($message->children) && count($message->children) > 0) {
            //     foreach ($message->children as $childMessage) {
            //         $childMessageData = is_array($childMessage->message) ? $childMessage->message : json_decode($childMessage->message, true);
            //         if (isset($childMessageData['image']) && ! empty($childMessageData['image'])) {
            //             $messageData['type'] = 'image-format';
            //             break;
            //         }
            //     }
            // }
            $this->info('Added message type to message data');
        }

        if (isset($messageData['preview'])) {
            unset($messageData['preview']);
            $this->info('Removed preview from message data');
        }

        if ($message->is_premium && ! isset($messageData['payment'])) {
            $messageData['payment'] = [
                'price' => 0,
                'is_locked' => false,
                'free_regeneration' => false
            ];
            $this->info('Added payment to message data');
        }

        $message->message = $messageData;
        $message->save();
        $this->info('-Prompt Message ID: ' . $message->getId() . ' updated');
    }

    private function fixNuggetData(Message $message): void
    {
        $parentMessage = $message->parent;
        $parentMessageData = is_array($parentMessage->message) ? $parentMessage->message : json_decode($parentMessage->message, true);
        $messageData = is_array($message->message) ? $message->message : json_decode($message->message, true);

        if ($parentMessage->is_deleted) {
            $message->is_deleted = 1;
            $message->is_public = 0;
            $message->save();
            $this->info('Parent message is deleted, setting child message as deleted and not public');
            return;
        }

        if (! isset($messageData['id'])) {
            $messageData['id'] = $message->getId();
            $this->info('Added message id to message data' . $messageData['id']);
        }

        if (! isset($messageData['title']) && isset($parentMessageData['title'])) {
            $messageData['title'] = $parentMessageData['title'];
            $this->info('Added message title to message data' . $messageData['title']);
        }

        if (! isset($messageData['type']) && isset($parentMessageData['type'])) {
            $messageData['type'] = $parentMessageData['type'];

            if (! isset($messageData['nugget']) || ! isset($messageData['image'])) {
                $response = Prism::text()
                ->using(Provider::Gemini, 'gemini-2.0-flash')
                ->withPrompt($parentMessageData['prompt'])
                ->generate();

                $responseText = str_replace(['```', 'json'], '', $response->text);

                if ($parentMessageData['type'] == 'image-format') {
                    $messageData['image'] = ''; //Use nugget if not possible to generate image.
                } else {
                    $messageData['nugget'] = $responseText;
                }
            }

            $this->info('Added message type to message data' . $messageData['type']);
            $this->info('Added message nugget to message data');
        }

        $message->message = $messageData;
        $message->save();
    }

    private function createNuggetMessage(Message $parentMessage): void
    {
        $messageData = is_array($parentMessage->message) ? $parentMessage->message : json_decode($parentMessage->message, true);
        $response = Prism::text()
                ->using(Provider::Gemini, 'gemini-2.0-flash')
                ->withPrompt($messageData['prompt'])
                ->generate();

        $responseText = str_replace(['```', 'json'], '', $response->text);
        $nuggetId = DB::connection('social')->table('messages')->insertGetId([
            'parent_id' => $parentMessage->getId(),
            'apps_id' => $parentMessage->apps_id,
            'uuid' => DB::raw('uuid()'),
            'companies_id' => $parentMessage->companies_id,
            'users_id' => $parentMessage->users_id,
            'message_types_id' => 576,
            'message' => [
                'title' => $messageData['title'],
                "type" => "text-format",
                "nugget" => $responseText,
            ],
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::connection('social')->table('messages')
            ->where('id', $nuggetId)
            ->update(['path' => $parentMessage->getId() . "." . $nuggetId]);

        //Call fixNuggetData just in case something is missing
        $this->fixNuggetData(Message::find($nuggetId));

        //Update total children on parent message
        $parentMessage->total_children++;
        $parentMessage->save();

        $this->info('Created nugget message with ID: ' . $nuggetId);
    }
}
