<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WordPress;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WordPress\DataTransferObject\WordPressPost;
use Kanvas\Connectors\WordPress\Enums\ConfigurationEnum;
use Kanvas\Connectors\WordPress\Enums\CustomFieldEnum;
use Kanvas\Connectors\WordPress\Enums\DuplicateMatchEnum;
use Kanvas\Connectors\WordPress\Enums\PostStatusEnum;
use Kanvas\Connectors\WordPress\RestClient;
use Kanvas\Connectors\WordPress\Services\WordPressDuplicateService;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;
use Laravel\Ai\Embeddings;
use RuntimeException;
use Tests\TestCase;

/**
 * The semantic path is exercised by overriding only its I/O (embed + Typesense) — the enable switch is
 * overridden too rather than written to company settings, which live in Redis and would leak into
 * every parallel test that publishes.
 */
final class WordPressDuplicateServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social'];

    private const string SITE_URL = 'https://example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->company()->set(ConfigurationEnum::SITE_URL->value, self::SITE_URL);
        $this->company()->set(ConfigurationEnum::USERNAME->value, 'editor');
        $this->company()->set(ConfigurationEnum::APPLICATION_PASSWORD->value, 'abcd efgh ijkl mnop');
    }

    public function testTheSemanticMatchIsTheClosestRecentlyPublishedStory(): void
    {
        $message = $this->makeMessage();
        $service = $this->service($message, semanticEnabled: true);

        $closest = $this->publishedMessage('Closest story');
        $weaker = $this->publishedMessage('Weaker story');
        $outsideTheWindow = $this->publishedMessage('Old story', claimedAt: now()->subDays(10));
        $released = $this->makeMessage();

        $service->neighbours = [
            $message->getId() => 0.99,
            $released->getId() => 0.98,
            $outsideTheWindow->getId() => 0.97,
            $closest->getId() => 0.95,
            $weaker->getId() => 0.91,
        ];

        $service->claim($this->storyPost('Hurricane reaches the coast'));
        $match = $service->findPossibleDuplicate($this->storyPost('Hurricane reaches the coast'));

        $this->assertNotNull($match);
        $this->assertSame(DuplicateMatchEnum::SEMANTIC, $match->match);
        $this->assertSame($closest->getId(), $match->message->getId());
        $this->assertSame(0.95, $match->score);
    }

    /**
     * Takes the REAL embed + Typesense branch: whether Typesense is unreachable here or answers with
     * random fake vectors, nothing clears the similarity floor and the title check must still run.
     */
    public function testTheTitleCheckStillRunsWhenTheSemanticStackFindsNothing(): void
    {
        Embeddings::fake();

        $service = new class ($this->makeMessage(), $this->client()) extends WordPressDuplicateService {
            protected function semanticCheckEnabled(): bool
            {
                return true;
            }
        };

        $similar = $this->publishedMessage('Hurricane reaches the southern coast');
        $post = $this->storyPost('Hurricane reaches the southern coasts');

        $service->claim($post);
        $match = $service->findPossibleDuplicate($post);

        $this->assertNotNull($match);
        $this->assertSame(DuplicateMatchEnum::TITLE, $match->match);
        $this->assertSame($similar->getId(), $match->message->getId());
    }

    public function testADisabledSemanticCheckNeverTouchesTheVectorStack(): void
    {
        $service = $this->service($this->makeMessage(), semanticEnabled: false);
        $service->failOnNeighbours = true;

        $post = $this->storyPost('A story with no relatives whatsoever');
        $service->claim($post);

        $this->assertNull($service->findPossibleDuplicate($post));
    }

    public function testAnIdenticalStoryStillBeingPublishedIsRefused(): void
    {
        $post = $this->storyPost('A story being published right now');

        $this->service($this->makeMessage(), semanticEnabled: false)->claim($post);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already being published');

        $this->service($this->makeMessage(), semanticEnabled: false)->claim($post);
    }

    public function testAClaimLeftBehindByADeadWorkerDoesNotBlockTheStoryForever(): void
    {
        $post = $this->storyPost('A story whose publisher crashed');
        $crashed = $this->makeMessage();
        $retry = $this->makeMessage();

        $this->service($crashed, semanticEnabled: false)->claim($post);
        $this->backdateCustomField($crashed, CustomFieldEnum::POST_FINGERPRINT, now()->subHours(2));

        $this->service($retry, semanticEnabled: false)->claim($post);

        $this->assertNull($crashed->getCustomField(CustomFieldEnum::POST_FINGERPRINT->value));
        $this->assertNotNull($retry->getCustomField(CustomFieldEnum::POST_FINGERPRINT->value));
    }

    private function service(Message $message, bool $semanticEnabled): WordPressDuplicateService
    {
        $service = new class ($message, $this->client()) extends WordPressDuplicateService {
            public bool $enabled = false;
            public bool $failOnNeighbours = false;
            public array $neighbours = [];

            protected function semanticCheckEnabled(): bool
            {
                return $this->enabled;
            }

            protected function semanticNeighbours(WordPressPost $post): array
            {
                if ($this->failOnNeighbours) {
                    throw new RuntimeException('The vector stack must not be reached');
                }

                return $this->neighbours;
            }
        };

        $service->enabled = $semanticEnabled;

        return $service;
    }

    private function publishedMessage(string $title, ?Carbon $claimedAt = null): Message
    {
        $message = $this->makeMessage();
        $message->set(CustomFieldEnum::POST_ID->value, 500);
        $message->set(CustomFieldEnum::POST_TITLE->value, $title);

        if ($claimedAt !== null) {
            $this->backdateCustomField($message, CustomFieldEnum::POST_TITLE, $claimedAt);
        }

        return $message;
    }

    private function backdateCustomField(Message $message, CustomFieldEnum $field, Carbon $at): void
    {
        AppsCustomFields::query()
            ->whereKey($message->getCustomField($field->value)?->getKey())
            ->update(['updated_at' => $at]);
    }

    private function storyPost(string $title): WordPressPost
    {
        return new WordPressPost(title: $title, content: '<p>' . $title . ' body.</p>', status: PostStatusEnum::PUBLISH);
    }

    private function client(): RestClient
    {
        return new RestClient(app(Apps::class), $this->company());
    }

    private function makeMessage(): Message
    {
        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company()->getId())
            ->create(['message' => ['content' => 'Body']]);
    }

    private function company(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }
}
