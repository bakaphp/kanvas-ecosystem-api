<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(true)]
class GetMessageTool extends Tool
{
    protected string $name = 'get_message';

    protected string $description = <<<'MARKDOWN'
        Get a single message from Kanvas. Returns structured message data including
        user information, message type, tags, categories, channels, and attached files.
    MARKDOWN;

    public function handle(Request $request): ResponseFactory
    {
        $app = Apps::find($request->get('apps_id'));
        if (! $app) {
            return Response::structured([
                'error' => 'App not found',
                'code' => 404,
            ]);
        }

        $messageType = MessagesTypesRepository::getByVerb($request->get('message_type_verb'), $app);
        if (! $messageType) {
            return Response::structured([
                'error' => 'Message type not found',
                'code' => 404,
            ]);
        }

        $message = Message::fromApp($app)
            ->where('users_id', $request->get('users_id'))
            ->where('messages.message_types_id', $messageType->getId())
            ->where('messages.uuid', $request->get('message_uuid'))
            ->with([
                'children',
                'user',
                'messageType',
                'tags',
                'categories',
                'channels',
                'files',
            ])->first();

        return Response::structured([
            'data' => $message ? $this->transformMessage($message) : null,
            'meta' => [
                'count' => $message ? 1 : 0,
                'apps_id' => $app->getId(),
                'users_id' => $request->get('users_id'),
            ],
        ]);
    }

    /**
     * Transform a message model into structured content.
     *
     * @return array<string, mixed>
     */
    protected function transformMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'uuid' => $message->uuid,
            'slug' => $message->slug,
            'message' => $message->getMessage(),
            'reactions_count' => $message->reactions_count,
            'comments_count' => $message->comments_count,
            'total_liked' => $message->total_liked,
            'total_disliked' => $message->total_disliked,
            'total_view' => $message->total_view,
            'total_saved' => $message->total_saved,
            'total_shared' => $message->total_shared,
            'total_children' => $message->total_children,
            'is_public' => (bool) $message->is_public,
            'is_locked' => (bool) $message->is_locked,
            'is_premium' => (bool) $message->is_premium,
            'created_at' => $message->created_at?->toIso8601String(),
            'updated_at' => $message->updated_at?->toIso8601String(),
            'children' => $message->children->first() ? [$this->transformMessage($message->children->first())] : [],
            'user' => $message->user ? [
                'id' => $message->user->id,
                'displayname' => $message->user->displayname,
                'firstname' => $message->user->firstname,
                'lastname' => $message->user->lastname,
            ] : null,
            'message_type' => $message->messageType ? [
                'id' => $message->messageType->id,
                'name' => $message->messageType->name,
                'verb' => $message->messageType->verb,
            ] : null,
            'tags' => $message->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])->toArray(),
            'categories' => $message->categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ])->toArray(),
            'channels' => $message->channels->map(fn ($channel) => [
                'id' => $channel->id,
                'name' => $channel->name,
                'slug' => $channel->slug,
            ])->toArray(),
            'files' => $message->files->map(fn ($file) => [
                'id' => $file->id,
                'name' => $file->name,
                'url' => $file->url,
                'type' => $file->file_type,
                'size' => $file->size,
            ])->toArray(),
        ];
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'apps_id' => $schema->integer()->description('The application ID.')->required(),
            'users_id' => $schema->integer()->description('The user ID to get messages for.')->required(),
            'message_type_verb' => $schema->string()->description('The verb of the message type to filter messages (e.g., "post", "comment").')->required(),
            'message_uuid' => $schema->string()->description('The UUID of the message to retrieve.')->required(),
        ];
    }

    /**
     * Define the output schema so the AI knows what to expect.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $simpleObjectSchema = $schema->object([
            'id' => $schema->integer()->description('Unique identifier'),
            'name' => $schema->string()->description('Name'),
            'slug' => $schema->string()->description('URL-friendly slug'),
        ]);

        $fileSchema = $schema->object([
            'id' => $schema->integer()->description('File ID'),
            'name' => $schema->string()->description('File name'),
            'url' => $schema->string()->description('File URL'),
            'type' => $schema->string()->description('File MIME type'),
            'size' => $schema->integer()->description('File size in bytes'),
        ]);

        $userSchema = $schema->object([
            'id' => $schema->integer()->description('User ID'),
            'displayname' => $schema->string()->nullable()->description('Display name'),
            'firstname' => $schema->string()->nullable()->description('First name'),
            'lastname' => $schema->string()->nullable()->description('Last name'),
        ]);

        $messageTypeSchema = $schema->object([
            'id' => $schema->integer()->description('Message type ID'),
            'name' => $schema->string()->description('Message type name'),
            'verb' => $schema->string()->description('Message type verb (e.g., post, comment)'),
        ]);

        // Base message schema builder (reusable for parent and children)
        $buildMessageSchema = fn (bool $includeChildren = true) => $schema->object(array_filter([
            'id' => $schema->integer()->description('Unique message identifier'),
            'uuid' => $schema->string()->description('UUID of the message'),
            'slug' => $schema->string()->description('URL-friendly slug'),
            'message' => $schema->object([])->description('The message content as a JSON object'),
            'reactions_count' => $schema->integer()->description('Total number of reactions'),
            'comments_count' => $schema->integer()->description('Total number of comments'),
            'total_liked' => $schema->integer()->description('Total likes'),
            'total_disliked' => $schema->integer()->description('Total dislikes'),
            'total_view' => $schema->integer()->description('Total views'),
            'total_saved' => $schema->integer()->description('Total saves'),
            'total_shared' => $schema->integer()->description('Total shares'),
            'total_children' => $schema->integer()->description('Total child messages'),
            'is_public' => $schema->boolean()->description('Whether the message is public'),
            'is_locked' => $schema->boolean()->description('Whether the message is locked'),
            'is_premium' => $schema->boolean()->description('Whether the message is premium content'),
            'created_at' => $schema->string()->description('ISO8601 creation timestamp'),
            'updated_at' => $schema->string()->description('ISO8601 last update timestamp'),
            'user' => $userSchema->description('The user who created the message'),
            'message_type' => $messageTypeSchema->description('The type of message'),
            'tags' => $schema->array($simpleObjectSchema)->description('Tags associated with the message'),
            'categories' => $schema->array($simpleObjectSchema)->description('Categories the message belongs to'),
            'channels' => $schema->array($simpleObjectSchema)->description('Channels the message is posted to'),
            'files' => $schema->array($fileSchema)->description('Files attached to the message'),
        ]));

        // Child message schema (same structure, no nested children to avoid infinite recursion)
        $childMessageSchema = $buildMessageSchema(false);

        // Parent message schema with children array
        $messageSchema = $schema->object([
            'id' => $schema->integer()->description('Unique message identifier'),
            'uuid' => $schema->string()->description('UUID of the message'),
            'slug' => $schema->string()->description('URL-friendly slug'),
            'message' => $schema->object([])->description('The message content as a JSON object'),
            'reactions_count' => $schema->integer()->description('Total number of reactions'),
            'comments_count' => $schema->integer()->description('Total number of comments'),
            'total_liked' => $schema->integer()->description('Total likes'),
            'total_disliked' => $schema->integer()->description('Total dislikes'),
            'total_view' => $schema->integer()->description('Total views'),
            'total_saved' => $schema->integer()->description('Total saves'),
            'total_shared' => $schema->integer()->description('Total shares'),
            'total_children' => $schema->integer()->description('Total child messages'),
            'is_public' => $schema->boolean()->description('Whether the message is public'),
            'is_locked' => $schema->boolean()->description('Whether the message is locked'),
            'is_premium' => $schema->boolean()->description('Whether the message is premium content'),
            'created_at' => $schema->string()->description('ISO8601 creation timestamp'),
            'updated_at' => $schema->string()->description('ISO8601 last update timestamp'),
            'children' => $schema->array($childMessageSchema)->description('Child messages (first child only, same structure as parent)'),
            'user' => $userSchema->description('The user who created the message'),
            'message_type' => $messageTypeSchema->description('The type of message'),
            'tags' => $schema->array($simpleObjectSchema)->description('Tags associated with the message'),
            'categories' => $schema->array($simpleObjectSchema)->description('Categories the message belongs to'),
            'channels' => $schema->array($simpleObjectSchema)->description('Channels the message is posted to'),
            'files' => $schema->array($fileSchema)->description('Files attached to the message'),
        ]);

        return [
            'data' => $messageSchema->description('The message object (or null if not found)'),
            'meta' => $schema->object([
                'count' => $schema->integer()->description('Number of messages returned (0 or 1)'),
                'apps_id' => $schema->integer()->description('The application ID'),
                'users_id' => $schema->integer()->description('The user ID filter applied'),
            ])->description('Metadata about the response'),
        ];
    }
}
