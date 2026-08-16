<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WordPress;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WordPress\Actions\PushMessageToWordPressAction;
use Kanvas\Connectors\WordPress\Enums\ConfigurationEnum;
use Kanvas\Connectors\WordPress\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

final class PushMessageToWordPressActionTest extends TestCase
{
    private const string SITE_URL = 'https://example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->company()->set(ConfigurationEnum::SITE_URL->value, self::SITE_URL);
        $this->company()->set(ConfigurationEnum::USERNAME->value, 'editor');
        $this->company()->set(ConfigurationEnum::APPLICATION_PASSWORD->value, 'abcd efgh ijkl mnop');
    }

    public function testPublishesAPostShapedMessage(): void
    {
        $this->fakeWordPress();

        $message = $this->makeMessage([
            'title' => 'Kanvas ships WordPress publishing',
            'content' => '<p>Agents can now post straight to the site.</p>',
            'excerpt' => 'Short summary',
            'status' => 'publish',
            'categories' => ['News'],
            'tags' => ['ai', 'kanvas'],
            'meta' => ['source' => 'kanvas'],
        ]);

        $result = new PushMessageToWordPressAction($message)->execute();

        $this->assertSame(101, $result['id']);
        $this->assertSame('created', $result['action']);
        $this->assertSame('publish', $result['status']);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/wp/v2/posts')) {
                return false;
            }

            $body = $request->data();

            return $body['title'] === 'Kanvas ships WordPress publishing'
                && $body['content'] === '<p>Agents can now post straight to the site.</p>'
                && $body['excerpt'] === 'Short summary'
                && $body['status'] === 'publish'
                && $body['categories'] === [7]
                && $body['tags'] === [21, 22]
                && $body['meta'] === ['source' => 'kanvas'];
        });

        $this->assertSame('101', (string) $message->get(CustomFieldEnum::POST_ID->value));
        $this->assertSame(self::SITE_URL . '/?p=101', $message->get(CustomFieldEnum::POST_URL->value));
        $this->assertSame(self::SITE_URL, $message->get(CustomFieldEnum::POST_SITE_URL->value));
    }

    public function testAuthenticatesWithTheApplicationPasswordStrippedOfSpaces(): void
    {
        $this->fakeWordPress();

        new PushMessageToWordPressAction($this->makeMessage(['content' => 'Hello world']))->execute();

        Http::assertSent(fn (Request $request): bool => $request->hasHeader(
            'Authorization',
            'Basic ' . base64_encode('editor:abcdefghijklmnop')
        ));
    }

    public function testSecondRunUpdatesTheSamePostInsteadOfCreatingAnother(): void
    {
        $this->fakeWordPress();

        $message = $this->makeMessage(['title' => 'First take', 'content' => 'Body']);

        new PushMessageToWordPressAction($message)->execute();

        $message->addMessage(['title' => 'Second take']);
        $result = new PushMessageToWordPressAction($message)->execute();

        $this->assertSame('updated', $result['action']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/wp/v2/posts/101')
                && $request->data()['title'] === 'Second take';
        });
    }

    public function testDerivesTheTitleAndTermsFromTheMessageWhenTheBodyOmitsThem(): void
    {
        $this->fakeWordPress();

        $message = $this->makeMessage(['content' => "Release notes for August\n\nEverything else."]);
        $message->addTag('kanvas');

        new PushMessageToWordPressAction($message)->execute();

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/wp/v2/posts')) {
                return false;
            }

            $body = $request->data();

            return $body['title'] === 'Release notes for August'
                && $body['status'] === 'draft'
                && $body['tags'] === [22];
        });
    }

    public function testWorkflowDefaultsLoseToTheMessageBody(): void
    {
        $this->fakeWordPress();

        $message = $this->makeMessage(['content' => 'Body', 'status' => 'publish']);

        new PushMessageToWordPressAction($message, defaults: ['status' => 'draft'])->execute();

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/wp/v2/posts')
                && $request->data()['status'] === 'publish';
        });
    }

    public function testRecordsAnUnreachableAttachmentWithoutLosingThePost(): void
    {
        $this->fakeWordPress();

        $message = $this->makeMessage([
            'content' => 'Body',
            'featured_image' => 'https://this-host-does-not-resolve.kanvas-test/hero.jpg',
        ]);

        $result = new PushMessageToWordPressAction($message)->execute();

        $this->assertSame(101, $result['id']);
        $this->assertNull($result['featured_media']);
        $this->assertArrayHasKey('https://this-host-does-not-resolve.kanvas-test/hero.jpg', $result['media_failures']);
    }

    public function testExplainsARedirectInsteadOfSurfacingABare401(): void
    {
        Http::fake(fn () => Http::response('', 301, ['Location' => 'https://www.example.com/wp-json/wp/v2/posts']));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/redirected to .*Site Address/s');

        new PushMessageToWordPressAction($this->makeMessage(['content' => 'Body']))->execute();
    }

    public function testSurfacesTheWordPressErrorMessageOnAFailure(): void
    {
        Http::fake(fn () => Http::response(
            ['code' => 'rest_cannot_create', 'message' => 'Sorry, you are not allowed to create posts.'],
            401
        ));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/not allowed to create posts/');

        new PushMessageToWordPressAction($this->makeMessage(['content' => 'Body']))->execute();
    }

    public function testRejectsAMessageWithNoContent(): void
    {
        $this->fakeWordPress();

        $message = $this->makeMessage(['title' => 'Nothing to say', 'content' => '   ']);

        $this->expectException(ValidationException::class);

        new PushMessageToWordPressAction($message)->execute();
    }

    /**
     * Routes on method + path because the fake cannot tell a term search from a term create by URL
     * alone. `News` already exists; the tags do not, so they take the create branch.
     */
    private function fakeWordPress(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $isRead = $request->method() === 'GET';

            return match (true) {
                str_ends_with($path, '/wp/v2/categories') && $isRead => Http::response(
                    str_contains($request->url(), 'News') ? [['id' => 7, 'name' => 'News']] : []
                ),
                str_ends_with($path, '/wp/v2/tags') && $isRead => Http::response([]),
                str_ends_with($path, '/wp/v2/tags') => Http::response(
                    ['id' => $request->data()['name'] === 'ai' ? 21 : 22, 'name' => $request->data()['name']],
                    201
                ),
                str_ends_with($path, '/wp/v2/posts/101') && $isRead => Http::response($this->postResponse('publish')),
                default => Http::response($this->postResponse($request->data()['status'] ?? 'draft'), 201),
            };
        });
    }

    private function postResponse(string $status): array
    {
        return [
            'id' => 101,
            'link' => self::SITE_URL . '/?p=101',
            'status' => $status,
            'categories' => [7],
            'tags' => [21, 22],
        ];
    }

    private function makeMessage(array $body): Message
    {
        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company()->getId())
            ->create(['message' => $body]);
    }

    private function company(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }
}
