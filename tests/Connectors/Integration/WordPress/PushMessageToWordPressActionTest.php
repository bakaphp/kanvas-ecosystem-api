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
use Kanvas\Connectors\WordPress\RestClient;
use Kanvas\Connectors\WordPress\Services\WordPressMediaService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;
use ReflectionMethod;
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

    /**
     * True for every param except `status`, which the rule owns outright — see
     * testTheRuleStatusOverridesTheAgentsOwn().
     */
    public function testWorkflowDefaultsLoseToTheMessageBody(): void
    {
        $this->fakeWordPress();

        $message = $this->makeMessage([
            'content' => 'Body',
            'title' => 'Written by the agent',
            'excerpt' => 'From the message',
        ]);

        new PushMessageToWordPressAction(
            $message,
            defaults: ['title' => 'From the rule', 'excerpt' => 'From the rule']
        )->execute();

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/wp/v2/posts')
                && $request->data()['title'] === 'Written by the agent'
                && $request->data()['excerpt'] === 'From the message';
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

    /**
     * The shape a channel responder writes: `content` holds the reply text ChatHelper picked out of
     * the agent's JSON, `response_json` holds everything that pick threw away.
     */
    public function testPublishesTheStructuredEnvelopeAChannelReplyStoredAlongsideItsText(): void
    {
        $this->fakeWordPress();

        $message = $this->makeMessage([
            'content' => '<p>Agents can now post straight to the site.</p>',
            'from_ia' => true,
            'response_json' => [
                'title' => 'Education accelerates classroom construction',
                'content' => '<p>Agents can now post straight to the site.</p>',
                'excerpt' => 'Short summary',
                'status' => 'publish',
                'categories' => ['News'],
                'tags' => ['ai', 'kanvas'],
                'featured_image' => '',
                'meta' => ['source' => 'kanvas'],
                'correcciones' => [['original' => 'x', 'corregida' => 'y']],
            ],
        ]);

        $result = new PushMessageToWordPressAction($message)->execute();

        $this->assertSame(101, $result['id']);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/wp/v2/posts')) {
                return false;
            }

            $body = $request->data();

            return $body['title'] === 'Education accelerates classroom construction'
                && $body['excerpt'] === 'Short summary'
                && $body['status'] === 'publish'
                && $body['categories'] === [7]
                && $body['tags'] === [21, 22]
                && $body['meta'] === ['source' => 'kanvas']
                && ! array_key_exists('correcciones', $body);
        });
    }

    /**
     * Messages written before the responders decoded the envelope carry the reply verbatim, fence
     * and all — they must still publish rather than shipping the raw JSON as the post body.
     */
    public function testDecodesAFencedEnvelopeStoredAsText(): void
    {
        $this->fakeWordPress();

        $envelope = json_encode([
            'title' => 'Ultiman a tiros a un hombre en La Romana',
            'content' => '<p>El Nuevo Diario, LA ROMANA.- Un hombre falleció este jueves.</p>',
            'categories' => ['News'],
            'status' => 'draft',
        ], JSON_UNESCAPED_UNICODE);

        $message = $this->makeMessage([
            'content' => 'El Nuevo Diario, LA ROMANA.- Un hombre falleció este jueves.',
            'response_text' => "```json\n" . $envelope . "\n```",
        ]);

        new PushMessageToWordPressAction($message)->execute();

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/wp/v2/posts')) {
                return false;
            }

            $body = $request->data();

            return $body['title'] === 'Ultiman a tiros a un hombre en La Romana'
                && $body['content'] === '<p>El Nuevo Diario, LA ROMANA.- Un hombre falleció este jueves.</p>'
                && $body['categories'] === [7];
        });
    }

    /**
     * Status is the one field the rule owns outright: an editor who configured "hold for review" must
     * not be overruled by an agent that wrote `publish` into its envelope.
     */
    public function testTheRuleStatusOverridesTheAgentsOwn(): void
    {
        $this->fakeWordPress();

        $message = $this->makeMessage([
            'content' => '<p>Body.</p>',
            'response_json' => ['title' => 'Agent post', 'content' => '<p>Body.</p>', 'status' => 'publish'],
        ]);

        new PushMessageToWordPressAction($message, ['status' => 'pending'])->execute();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/wp/v2/posts')
            && $request->data()['status'] === 'pending');
    }

    /**
     * The promotion is scoped to status — the article's own terms still come from whoever wrote it.
     */
    public function testTheRuleDoesNotOverrideTheAgentsTerms(): void
    {
        $this->fakeWordPress();

        $message = $this->makeMessage([
            'content' => '<p>Body.</p>',
            'response_json' => ['title' => 'Agent post', 'content' => '<p>Body.</p>', 'categories' => ['News']],
        ]);

        new PushMessageToWordPressAction($message, ['status' => 'pending', 'categories' => ['Ignored']])->execute();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/wp/v2/posts')
            && $request->data()['categories'] === [7]);
    }

    /**
     * The site-wide default is NOT policy — a message that names its own status still wins over it.
     */
    public function testTheConfiguredDefaultStatusDoesNotOverrideTheMessage(): void
    {
        $this->fakeWordPress();
        $this->company()->set(ConfigurationEnum::DEFAULT_POST_STATUS->value, 'pending');

        $message = $this->makeMessage([
            'content' => '<p>Body.</p>',
            'response_json' => ['title' => 'Agent post', 'content' => '<p>Body.</p>', 'status' => 'publish'],
        ]);

        new PushMessageToWordPressAction($message)->execute();

        $this->company()->del(ConfigurationEnum::DEFAULT_POST_STATUS->value);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/wp/v2/posts')
            && $request->data()['status'] === 'publish');
    }

    public function testTheRuleParamStatusAppliesWhenTheAgentOmitsIt(): void
    {
        $this->fakeWordPress();

        $message = $this->makeMessage([
            'content' => '<p>Body.</p>',
            'response_json' => ['title' => 'Agent post', 'content' => '<p>Body.</p>'],
        ]);

        new PushMessageToWordPressAction($message, ['status' => 'pending'])->execute();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/wp/v2/posts')
            && $request->data()['status'] === 'pending');
    }

    /**
     * A burst carrying two press releases comes back as a LIST of records. The list reached
     * onlyPostKeys() as numeric keys, matched nothing, and the post fell through to the message's own
     * `content` — publishing the model's raw JSON as the article body under a title that was its
     * first 117 characters (prod, El Nuevo Diario).
     */
    public function testAListOfArticlesPublishesTheFirstOneNotItsRawJson(): void
    {
        $this->fakeWordPress();

        $articles = [
            ['title' => 'First article', 'content' => '<p>First article body.</p>', 'categories' => ['News']],
            ['title' => 'Second article', 'content' => '<p>Second article body.</p>'],
        ];

        $message = $this->makeMessage([
            'content' => json_encode($articles, JSON_UNESCAPED_UNICODE),
            'response_json' => $articles,
        ]);

        new PushMessageToWordPressAction($message)->execute();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/wp/v2/posts')
            && $request->data()['title'] === 'First article'
            && $request->data()['content'] === '<p>First article body.</p>');
    }

    /**
     * The same shape as it reaches us when the envelope was never decoded — the reply text IS the
     * fenced JSON, which is exactly what the message that surfaced this carried.
     */
    public function testAFencedListInTheContentIsReadAsAnEnvelope(): void
    {
        $this->fakeWordPress();

        $articles = json_encode(
            [
                ['title' => 'First article', 'content' => '<p>First article body.</p>'],
                ['title' => 'Second article', 'content' => '<p>Second article body.</p>'],
            ],
            JSON_UNESCAPED_UNICODE
        );

        $message = $this->makeMessage(['content' => "```json\n" . $articles . "\n```"]);

        new PushMessageToWordPressAction($message)->execute();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/wp/v2/posts')
            && $request->data()['title'] === 'First article');
    }

    public function testRejectsAMessageWithNoContent(): void
    {
        $this->fakeWordPress();

        $message = $this->makeMessage(['title' => 'Nothing to say', 'content' => '   ']);

        $this->expectException(ValidationException::class);

        new PushMessageToWordPressAction($message)->execute();
    }

    /**
     * REST uploads land with `post_parent = 0`, so a post's own photos show as "Unattached" in the
     * media library and never appear under the post in the editor.
     */
    public function testAttachingMediaPointsItAtThePost(): void
    {
        Http::fake(fn () => Http::response(['id' => 55, 'post' => 101]));

        new RestClient(app(Apps::class), $this->company())->attachMediaToPost(55, 101);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/wp/v2/media/55')
            && $request->data()['post'] === 101);
    }

    public function testNoAttachCallIsMadeWhenNothingWasUploaded(): void
    {
        Http::fake();

        $media = new WordPressMediaService(new RestClient(app(Apps::class), $this->company()));

        $this->assertSame([], $media->attachTo(101));
        Http::assertNothingSent();
    }

    /**
     * WordPress decides what it will accept by **filename**, not by content. WhatsApp media was
     * stored as `*.bin` for a while and WP refused it with "no tienes permisos para subir este tipo
     * de archivo" while the bytes were a perfectly good JPEG. The sniffed content type wins over a
     * stored name that contradicts it, which also rescues files already named `.bin`.
     *
     * Exercised through reflection: `upload()` fetches bytes via SafeUrlFetcher, which builds its
     * own Guzzle client and so cannot be fed by `Http::fake()`.
     */
    public function testTheSniffedTypeOverridesAContradictoryStoredFilename(): void
    {
        $service = new WordPressMediaService(
            new RestClient(app(Apps::class), $this->company()),
            [
                'https://cdn.example.test/a' => 'whatsapp-media.bin',
                'https://cdn.example.test/b' => 'already-right.png',
                'https://cdn.example.test/c' => 'no-extension-at-all',
            ],
        );

        $filename = new ReflectionMethod($service, 'filename');

        $this->assertSame(
            'whatsapp-media.jpg',
            $filename->invoke($service, 'https://cdn.example.test/a', 'image/jpeg'),
            'a .bin name must be corrected to the real type'
        );
        $this->assertSame(
            'already-right.png',
            $filename->invoke($service, 'https://cdn.example.test/b', 'image/png'),
            'a correct name is left alone'
        );
        $this->assertSame(
            'no-extension-at-all.jpg',
            $filename->invoke($service, 'https://cdn.example.test/c', 'image/jpeg; charset=binary'),
            'mimetype parameters must not defeat the lookup'
        );
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
