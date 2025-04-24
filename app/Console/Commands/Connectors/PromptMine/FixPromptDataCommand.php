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
    protected $signature = 'kanvas:promptmine-fix-prompt-data {app_id} {message_type_id} {child_message_type_id}';

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
        $childMessageTypeId = (int) $this->argument('child_message_type_id');
        $childMessageType = MessageType::find($childMessageTypeId);
        // $companiesId = (int) $this->argument('companies_id');

        //Get all messages for the given message type and app
        $this->SyncPromptData($app, $messageType, $childMessageType);
    }

    /**
     * @todo how to avoid changing legit prompts and nugget data? Use the json validator?
     */
    private function SyncPromptData(Apps $app, MessageType $messageType, MessageType $childMessageType): void
    {
        Message::fromApp($app)
            ->where('message_types_id', $messageType->getId())
            // ->where('companies_id', $companiesId)
            ->where('is_deleted', 0)
            ->orderBy('id', 'asc')
            ->chunk(100, function ($messages) use ($childMessageType) {
                foreach ($messages as $message) {
                    try {
                        $this->fixPromptData($message);

                        if (count($message->children) == 0) {
                            //Generate child messages if it doesn't exist
                            $this->createNuggetMessage($message, $childMessageType);
                            $this->info('--Child Nugget Message ID: '.$message->getId().' created');
                            continue;
                        }

                        //Need to get just the first child message
                        foreach ($message->children as $childMessage) {
                            // $validateMessageSchema = new MessageSchemaValidator($childMessage, $childMessageType, true);
                            // $this->info('--Checking Child Nugget Message Schema of ID: ' . $childMessage->getId());
                            // if ($validateMessageSchema->validate()) {
                            //     $this->info('--Message Schema is OK');
                            //     continue;
                            // }

                            $this->info('--Fixing Child Nugget Message Schema');
                            $this->fixNuggetData($childMessage);
                            $this->info('--Child Nugget Message ID: '.$childMessage->getId().' updated');
                        }
                    } catch (Throwable $e) {
                        $this->error('Error updating message ID: '.$message->getId().' - '.$e->getMessage());
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
            $message->save();
            $this->info('Message is not a prompt, setting as deleted and not public');

            return;
        }

        if (! isset($messageData['ai_model'])) {
            $messageData['ai_model'] = [
                'name'    => 'GPT-4o',
                'key'     => 'openai',
                'value'   => 'gpt-4o',
                'icon'    => 'https://cdn.promptmine.ai/OpenAILogo.png',
                'payment' => [
                    'price'             => 0,
                    'is_locked'         => false,
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
                'price'             => 0,
                'is_locked'         => false,
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
        $message->save();
        $this->info('-Prompt Message ID: '.$message->getId().' updated');
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
            $this->info('Added message id to message data'.$messageData['id']);
        }

        if (! isset($messageData['title']) && isset($parentMessageData['title'])) {
            $messageData['title'] = $parentMessageData['title'];
            $this->info('Added message title to message data '.$messageData['title']);
        }

        //This comes from old memo data.
        if (isset($messageData['type']['name'])) {
            unset($messageData['type']);
            $this->info('Removed type from message data');
        }

        if (! isset($messageData['type']) && isset($parentMessageData['type'])) {
            $messageData['type'] = $parentMessageData['type'];

            $this->info('Added message type to message data: '.$messageData['type']);
            if (! isset($messageData['nugget']) && $messageData['type'] === 'text-format') {
                $this->info('Generating nugget for message ID: '.$message->getId());
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
        $message->save();
    }

    private function createNuggetMessage(Message $parentMessage, MessageType $childMessageType): void
    {
        $messageData = is_array($parentMessage->message) ? $parentMessage->message : json_decode($parentMessage->message, true);
        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withPrompt($messageData['prompt'])
            ->generate();

        $responseText = str_replace(['```', 'json'], '', $response->text);
        $nuggetId = DB::connection('social')->table('messages')->insertGetId([
            'parent_id'        => $parentMessage->getId(),
            'apps_id'          => $parentMessage->apps_id,
            'uuid'             => DB::raw('uuid()'),
            'companies_id'     => $parentMessage->companies_id,
            'users_id'         => $parentMessage->users_id,
            'message_types_id' => $childMessageType->getId(),
            'message'          => [
                'title'  => $messageData['title'],
                'type'   => 'text-format',
                'nugget' => $responseText,
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('social')->table('messages')
            ->where('id', $nuggetId)
            ->update(['path' => $parentMessage->getId().'.'.$nuggetId]);

        foreach ($parentMessage->tags() as $tag) {
            DB::connection('social')->table('tags_entities')->insert([
                'entity_id'     => $nuggetId,
                'tags_id'       => $tag->getId(),
                'users_id'      => $parentMessage->users_id,
                'taggable_type' => "Kanvas\Social\Messages\Models\Message",
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        }

        //Call fixNuggetData just in case something is missing
        $this->fixNuggetData(Message::find($nuggetId));

        //Update total children on parent message
        $parentMessage->total_children++;
        $parentMessage->save();

        $this->info('Created nugget message with ID: '.$nuggetId);
    }
}
