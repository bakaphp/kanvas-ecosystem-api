<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Sessions\Models\Session;
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
