<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Messages\Models\Message;
use Tests\Stubs\Intelligence\FakeAgentHandler;
use Tests\TestCase;

/**
 * End-to-end coverage for `aiAgentUserChat` multipart uploads — pushes a real
 * `UploadedFile` through the GraphQL layer and asserts the uploaded files land
 * in the `filesystem` table classified by media type. The unit-level routing
 * is covered by {@see Tests\Intelligence\Agents\AgentChatMutationUploadsTest}.
 */
class UserAgentChatUploadsTest extends TestCase
{
    use DatabaseTransactions;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('TEST_TOKEN') !== false) {
            $this->markTestSkipped('multipartGraphQL uploads hang under paratest workers');
        }

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->id)
            ->create(['handler' => FakeAgentHandler::class]);

        $this->agent = Agent::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create(['agent_type_id' => $agentType->id]);
    }

    public function testUserChatWithImageUploadAttachesFilesystemToSession(): void
    {
        $beforeId = (int) (Filesystem::max('id') ?? 0);

        $operations = [
            'query' => /** @lang GraphQL */ '
                mutation($input: UserChatInput!) {
                    aiAgentUserChat(input: $input) {
                        response
                        session_id
                    }
                }
            ',
            'variables' => [
                'input' => [
                    'agent_id' => (string) $this->agent->getId(),
                    'message' => 'look at this photo',
                    'uploads' => [null],
                ],
            ],
        ];

        $map = ['0' => ['variables.input.uploads.0']];
        $files = ['0' => UploadedFile::fake()->image('snapshot.png')];

        $response = $this->multipartGraphQL($operations, $map, $files);
        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $response->json(), 'GraphQL errors: ' . $response->getContent());

        $sessionId = $response->json('data.aiAgentUserChat.session_id');
        $this->assertNotEmpty($sessionId);

        $uploaded = Filesystem::where('id', '>', $beforeId)->orderBy('id')->get();
        $this->assertCount(1, $uploaded, 'expected exactly one filesystem row created by the chat upload');
        $this->assertTrue(
            $uploaded[0]->mediaType()->isImage(),
            'uploaded PNG should classify as image (file_type=' . $uploaded[0]->file_type . ')',
        );

        $session = Session::where('uuid', $sessionId)->first();
        $this->assertNotNull($session, 'session should exist after chat');
    }

    public function testUploadsAttachToThePersistedUserMessageAsBackup(): void
    {
        $beforeId = (int) (Filesystem::max('id') ?? 0);

        $operations = [
            'query' => /** @lang GraphQL */ '
                mutation($input: UserChatInput!) {
                    aiAgentUserChat(input: $input) {
                        message { id }
                        channel { id }
                    }
                }
            ',
            'variables' => [
                'input' => [
                    'agent_id' => (string) $this->agent->getId(),
                    'message' => 'here is the brief and a photo',
                    'uploads' => [null, null],
                ],
            ],
        ];

        $map = [
            '0' => ['variables.input.uploads.0'],
            '1' => ['variables.input.uploads.1'],
        ];
        $files = [
            '0' => UploadedFile::fake()->image('snapshot.png'),
            '1' => UploadedFile::fake()->create('brief.pdf', 50, 'application/pdf'),
        ];

        $response = $this->multipartGraphQL($operations, $map, $files);
        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $response->json(), 'GraphQL errors: ' . $response->getContent());

        $uploaded = Filesystem::where('id', '>', $beforeId)->orderBy('id')->get();
        $this->assertCount(2, $uploaded, 'one image + one document should land in the filesystem table');

        // The assistant reply is what the mutation returns; the human prompt is the message we
        // want the backup attachments on — find it by walking the channel.
        $assistantMessageId = $response->json('data.aiAgentUserChat.message.id');
        $channelId = $response->json('data.aiAgentUserChat.channel.id');
        $this->assertNotNull($assistantMessageId);
        $this->assertNotNull($channelId);

        $channelMessages = Message::join('channel_messages', 'channel_messages.messages_id', '=', 'messages.id')
            ->where('channel_messages.channel_id', $channelId)
            ->orderBy('messages.id')
            ->select('messages.*')
            ->get();

        $userMessage = $channelMessages->first(fn (Message $m): bool => ! ($m->getMessage()['from_ia'] ?? false));
        $this->assertNotNull($userMessage, 'user prompt message must exist on the channel');

        $attachedFiles = $userMessage->getFiles();
        $this->assertCount(
            2,
            $attachedFiles,
            'Both uploads must be attached to the user prompt Message so it stands as the canonical record',
        );

        $imageCount = $attachedFiles
            ->filter(fn (FilesystemEntities $f): bool => $f->filesystem->mediaType()->isImage())
            ->count();
        $docCount = $attachedFiles
            ->filter(fn (FilesystemEntities $f): bool => ! $f->filesystem->mediaType()->isImage())
            ->count();
        $this->assertSame(1, $imageCount, 'one image attachment should be on the user message');
        $this->assertSame(1, $docCount, 'one document attachment should be on the user message');
    }

    public function testClientSuppliedUrlFilesAttachToTheUserMessage(): void
    {
        // No uploads this turn — the client passes already-hosted CDN URLs. These must still land
        // on the persisted user message (regression: previously only multipart uploads attached).
        $response = $this->graphQL(
            /** @lang GraphQL */
            '
                mutation($input: UserChatInput!) {
                    aiAgentUserChat(input: $input) {
                        message { id }
                        channel { id }
                    }
                }
            ',
            [
                'input' => [
                    'agent_id' => (string) $this->agent->getId(),
                    'message' => 'can you understand these 2 files?',
                    'images' => ['https://cdn.test/photo.png'],
                    'files' => ['https://cdn.test/brief.pdf'],
                ],
            ],
        );

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $response->json(), 'GraphQL errors: ' . $response->getContent());

        $channelId = $response->json('data.aiAgentUserChat.channel.id');
        $this->assertNotNull($channelId);

        $channelMessages = Message::join('channel_messages', 'channel_messages.messages_id', '=', 'messages.id')
            ->where('channel_messages.channel_id', $channelId)
            ->orderBy('messages.id')
            ->select('messages.*')
            ->get();

        $userMessage = $channelMessages->first(fn (Message $m): bool => ! ($m->getMessage()['from_ia'] ?? false));
        $this->assertNotNull($userMessage, 'user prompt message must exist on the channel');

        $urls = $userMessage->getFiles()->map(fn (FilesystemEntities $f): string => (string) $f->filesystem->url)->all();
        $this->assertContains('https://cdn.test/photo.png', $urls, 'client image URL must attach to the user message');
        $this->assertContains('https://cdn.test/brief.pdf', $urls, 'client file URL must attach to the user message');
    }

    public function testUserChatWithDocumentUploadClassifiesAsFileNotImage(): void
    {
        $beforeId = (int) (Filesystem::max('id') ?? 0);

        $operations = [
            'query' => /** @lang GraphQL */ '
                mutation($input: UserChatInput!) {
                    aiAgentUserChat(input: $input) {
                        response
                        session_id
                    }
                }
            ',
            'variables' => [
                'input' => [
                    'agent_id' => (string) $this->agent->getId(),
                    'message' => 'read this',
                    'uploads' => [null],
                ],
            ],
        ];

        $map = ['0' => ['variables.input.uploads.0']];
        $files = ['0' => UploadedFile::fake()->create('brief.pdf', 50, 'application/pdf')];

        $response = $this->multipartGraphQL($operations, $map, $files);
        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $response->json(), 'GraphQL errors: ' . $response->getContent());

        $uploaded = Filesystem::where('id', '>', $beforeId)->orderBy('id')->get();
        $this->assertCount(1, $uploaded);
        $this->assertFalse(
            $uploaded[0]->mediaType()->isImage(),
            'PDF upload should not classify as image',
        );
    }
}
