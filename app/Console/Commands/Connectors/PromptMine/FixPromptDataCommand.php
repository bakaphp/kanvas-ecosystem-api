<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Validations\MessageSchemaValidator;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Throwable;

class FixPromptDataCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:promptmine-fix-prompt-data 
                        {--app_id= : The app ID (default: 78)} 
                        {--message_type_id= : The message type ID (default: 588)} 
                        {--child_message_type_id= : The child message type ID (default: 576)}';

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
        $app = Apps::getById((int) $this->option('app_id'));
        $this->overwriteAppService($app);
        $messageTypeId = (int) $this->option('message_type_id');
        $messageType = MessageType::find($messageTypeId);
        $childMessageTypeId = (int) $this->option('child_message_type_id');
        $childMessageType = MessageType::find($childMessageTypeId);
        $imageGenerationMessageTypeId = (int) $this->option('child_message_type_id');
        $imageGenerationMessageType = MessageType::find($imageGenerationMessageTypeId);
        // $companiesId = (int) $this->argument('companies_id');

        //Get all messages for the given message type and app
        $this->SyncPromptData($app, $messageType, $childMessageType, $imageGenerationMessageType);
    }

    /**
     * @todo how to avoid changing legit prompts and nugget data? Use the json validator?
     */
    private function SyncPromptData(Apps $app, MessageType $messageType, MessageType $childMessageType): void
    {
        Message::fromApp($app)
            ->where('message_types_id', $messageType->getId())
            ->where('is_deleted', 0)
            ->orderBy('id', 'asc')
            ->chunk(100, function ($messages) use ($app, $childMessageType) {
                foreach ($messages as $message) {
                    try {
                        $this->info('--Checking Parent Prompt Message Schema of ID: ' . $message->getId());
                        $this->fixPromptData($message);
                        // Need to check children manually
                        $children = Message::fromApp($app)
                            ->where('parent_id', $message->getId())
                            ->withTrashed()
                            ->get();

                        if (count($children) == 0) {
                            try {
                                //Generate child messages if it doesn't exist
                                $this->info('--Creating Child Nugget Message');
                                $this->createNuggetMessage($message, $childMessageType);
                                $this->info('--Child Nugget Message ID: ' . $message->getId() . ' created');

                                continue;
                            } catch (\Throwable $e) {
                                $this->error('Error creating nugget message ID: ' . $message->getId() . ' - ' . $e->getMessage());
                            }
                        }

                        //Need to get just the first child message
                        foreach ($children as $childMessage) {
                            // $validateMessageSchema = new MessageSchemaValidator($childMessage, $childMessageType, true);
                            // $this->info('--Checking Child Nugget Message Schema of ID: ' . $childMessage->getId());
                            // if ($validateMessageSchema->validate()) {
                            //     $this->info('--Message Schema is OK');
                            //     continue;
                            // }

                            try {
                                $this->info('--Fixing Child Nugget Message Schema ' . $childMessage->getId());
                                $this->fixNuggetData($childMessage);
                                $this->info('--Child Nugget Message ID: ' . $childMessage->getId() . ' updated');
                            } catch (\Throwable $e) {
                                $this->error('Error updating nugget message ID: ' . $message->getId() . ' - ' . $e->getMessage());
                            }
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
        // $validateMessageSchema = new MessageSchemaValidator($message, MessageType::find($message->message_types_id), true);

        // $this->info('--Checking Prompt Message Schema of ID: ' . $message->getId());
        // if ($validateMessageSchema->validate()) {
        //     $this->info('-Prompt Message Schema is OK');
        //     return;
        // }
        //Anything that is not a prompt, set as deleted
        if (! isset($messageData['prompt'])) {
            $message->is_deleted = 1;
            $message->is_public = 0;
            $message->saveOrFail();
            $this->info('Message is not a prompt, setting as deleted and not public');

            return;
        }

        if (! isset($messageData['ai_model'])) {
            $messageData['ai_model'] = [
                'name' => 'GPT-4o',
                'key' => 'openai',
                'value' => 'gpt-4o',
                'icon' => 'https://cdn.promptmine.ai/OpenAILogo.png',
                'payment' => [
                    'price' => 0,
                    'is_locked' => false,
                    'free_regeneration' => false,
                ],
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

        if (! isset($messageData['payment'])) {
            $messageData['payment'] = [
                'price' => 0,
                'is_locked' => false,
                'free_regeneration' => false,
            ];
            $this->info('Added payment to message data');
        }

        if (isset($messageData['ai_nugged'])) {
            unset($messageData['ai_nugged']);
            $this->info('Removed ai_nugged from message data');
        }

        if (isset($messageData['nugget'])) {
            unset($messageData['nugget']);
            $this->info('Removed nugget from message data');
        }

        if (isset($messageData['is_assistant'])) {
            unset($messageData['is_assistant']);
            $this->info('Removed is_assistant from message data');
        }

        $message->message = $messageData;
        $message->saveOrFail();

        $this->info('-Prompt Message ID: ' . $message->getId() . ' updated');
    }

    private function fixNuggetData(Message $message): void
    {
        $parentMessage = $message->parent;
        $parentMessageData = is_array($parentMessage->message) ? $parentMessage->message : json_decode($parentMessage->message, true);
        $messageData = is_array($message->message) ? $message->message : json_decode($message->message, true);

        if ($parentMessage->is_deleted && ! isset($parentMessageData['prompt'])) {
            $message->is_deleted = 1;
            $message->is_public = 0;
            $message->saveOrFail();
            $this->info('Parent message is deleted, setting child message as deleted and not public');

            return;
        } else {
            $message->is_deleted = 0;
            $message->is_public = 1;
            $message->saveOrFail();
            $this->info('Parent message is not deleted, restoring child message as not deleted and public');

            return;
        }

        if (! isset($messageData['id'])) {
            $messageData['id'] = $message->getId();
            $this->info('Added message id to message data' . $messageData['id']);
        }

        if (! isset($messageData['title']) && isset($parentMessageData['title'])) {
            $messageData['title'] = $parentMessageData['title'];
            $this->info('Added message title to message data ' . $messageData['title']);
        }

        //This comes from old memo data.
        if (isset($messageData['type']['name'])) {
            unset($messageData['type']);
            $this->info('Removed type from message data');
        }

        if (! isset($messageData['type']) && isset($parentMessageData['type'])) {
            $messageData['type'] = $parentMessageData['type'];

            $this->info('Added message type to message data: ' . $messageData['type']);
            if (! isset($messageData['nugget']) && $messageData['type'] === 'text-format') {
                $this->info('Generating nugget for message ID: ' . $message->getId());
                $response = Prism::text()
                    ->using(Provider::Gemini, 'gemini-2.0-flash')
                    ->withPrompt($parentMessageData['prompt'])
                    ->generate();
                $responseText = str_replace(['```', 'json'], '', $response->text);
                $messageData['nugget'] = $responseText;
                $this->info('Added message nugget to message data');
            }
        }

        if (isset($messageData['nugget']) && $messageData['type'] === 'image-format') {
            unset($messageData['nugget']);
            $this->info('Removed nugget from message data on image format');
        }

        if (isset($messageData['display_type'])) {
            unset($messageData['display_type']);
            $this->info('Removed display_type from message data');
        }

        if (isset($messageData['parts'])) {
            unset($messageData['parts']);
            $this->info('Removed parts from message data');
        }

        if (isset($messageData['description'])) {
            unset($messageData['description']);
            $this->info('Removed description from message data');
        }

        if (isset($messageData['created_at'])) {
            unset($messageData['created_at']);
            $this->info('Removed created_at from message data');
        }

        if (isset($messageData['updated_at'])) {
            unset($messageData['updated_at']);
            $this->info('Removed updated_at from message data');
        }

        if (isset($messageData['ai_model'])) {
            unset($messageData['ai_model']);
            $this->info('Removed ai_model from message data');
        }

        if (isset($messageData['ai_nugged'])) {
            unset($messageData['ai_nugged']);
            $this->info('Removed ai_nugged from message data');
        }

        if (isset($messageData['description'])) {
            unset($messageData['description']);
            $this->info('Removed description from message data');
        }

        $message->message = $messageData;
        $message->saveOrFail();
    }

    private function createNuggetMessage(Message $parentMessage, MessageType $childMessageType): void
    {
        $messageData = is_array($parentMessage->message) ? $parentMessage->message : json_decode($parentMessage->message, true);
        if (! isset($messageData['prompt'])) {
            $parentMessage->is_deleted = 1;
            $parentMessage->is_public = 0;
            $parentMessage->save();
            $this->info('Parent Message has no a prompt, setting as deleted and not public, no child created');

            return;
        }

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
            'message_types_id' => $childMessageType->getId(),
            'message' => json_encode([
                'title' => $messageData['title'],
                'type' => 'text-format',
                'nugget' => $responseText,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('social')->table('messages')
            ->where('id', $nuggetId)
            ->update(['path' => $parentMessage->getId() . '.' . $nuggetId]);

        foreach ($parentMessage->tags as $tag) {
            DB::connection('social')->table('tags_entities')->insert([
                'entity_id' => $nuggetId,
                'tags_id' => $tag->getId(),
                'users_id' => $parentMessage->users_id,
                'taggable_type' => "Kanvas\Social\Messages\Models\Message",
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        //Call fixNuggetData just in case something is missing
        $this->fixNuggetData(Message::find($nuggetId));

        //Update total children on parent message
        $parentMessage->total_children++;
        $parentMessage->saveOrFail();

        $this->info('Created nugget message with ID: ' . $nuggetId);
    }
}
