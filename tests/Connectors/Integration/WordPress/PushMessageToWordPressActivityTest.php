<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WordPress;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WordPress\Activities\PushMessageToWordPressActivity;
use Kanvas\Connectors\WordPress\Enums\ConfigurationEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class PushMessageToWordPressActivityTest extends TestCase
{
    use HasIntegrationCompany;

    private const string SITE_URL = 'https://example.com';

    public function testPublishesThroughTheIntegration(): void
    {
        $this->configureSite();
        $this->registerIntegration();
        $this->fakeWordPress();

        $message = $this->makeMessage(['title' => 'From a workflow', 'content' => 'Body']);

        $result = $this->activity()->execute($message, app(Apps::class), []);

        $this->assertSame(101, $result['id']);
        $this->assertSame('created', $result['action']);
    }

    public function testFailsTheWorkflowWhenCredentialsAreMissing(): void
    {
        $this->clearSiteConfiguration();
        $this->registerIntegration();

        $result = $this->activity()->execute($this->makeMessage(['content' => 'Body']), app(Apps::class), []);

        $this->assertStringContainsString('not configured', $result['message']);
    }

    public function testSkipsAMessageOfAnotherType(): void
    {
        $this->configureSite();
        $this->registerIntegration();
        $this->fakeWordPress();

        $message = $this->makeMessage(['content' => 'Body']);

        $result = $this->activity()->execute(
            $message,
            app(Apps::class),
            ['message_type_id' => $message->message_types_id + 1]
        );

        $this->assertStringContainsString('does not match', $result['message']);
        Http::assertNothingSent();
    }

    public function testFailsTheWorkflowOnALockedMessage(): void
    {
        $this->configureSite();
        $this->registerIntegration();
        $this->fakeWordPress();

        $message = $this->makeMessage(['content' => 'Body']);
        $message->setLock();

        $result = $this->activity()->execute($message, app(Apps::class), []);

        $this->assertStringContainsString('locked', $result['message']);
        Http::assertNothingSent();
    }

    public function testPublishesWhenTheMessageTypeIsOneOfSeveralConfigured(): void
    {
        $this->configureSite();
        $this->registerIntegration();
        $this->fakeWordPress();

        $message = $this->makeMessage(['content' => 'Body']);

        $result = $this->activity()->execute(
            $message,
            app(Apps::class),
            ['message_type_id' => [$message->message_types_id + 1, $message->message_types_id]]
        );

        $this->assertSame(101, $result['id']);
    }

    /**
     * A rule param typed into a plain text field arrives as a string, not an array.
     */
    public function testAcceptsACommaSeparatedListOfMessageTypes(): void
    {
        $this->configureSite();
        $this->registerIntegration();
        $this->fakeWordPress();

        $message = $this->makeMessage(['content' => 'Body']);

        $result = $this->activity()->execute(
            $message,
            app(Apps::class),
            ['message_type_id' => ($message->message_types_id + 1) . ', ' . $message->message_types_id]
        );

        $this->assertSame(101, $result['id']);
    }

    public function testSkipsWhenTheMessageTypeIsInNoneOfTheConfigured(): void
    {
        $this->configureSite();
        $this->registerIntegration();
        $this->fakeWordPress();

        $message = $this->makeMessage(['content' => 'Body']);

        $result = $this->activity()->execute(
            $message,
            app(Apps::class),
            ['message_type_id' => [$message->message_types_id + 1, $message->message_types_id + 2]]
        );

        $this->assertStringContainsString('does not match', $result['message']);
        Http::assertNothingSent();
    }

    /**
     * Both sides of a channel conversation carry the same message type, so without this guard the
     * customer's own email publishes as a post — and succeeds, since `content` is a valid post key.
     */
    public function testSkipsAnInboundMessage(): void
    {
        $this->configureSite();
        $this->registerIntegration();
        $this->fakeWordPress();

        $message = $this->makeMessage([
            'content' => 'Construcción de nuevas aulas para el nuevo año escolar',
            'from_me' => false,
            'from_ia' => false,
        ]);

        $result = $this->activity()->execute($message, app(Apps::class), []);

        $this->assertStringContainsString('Inbound message', $result['message']);
        Http::assertNothingSent();
    }

    public function testPublishesAMessageThatCarriesNoDirectionFlag(): void
    {
        $this->configureSite();
        $this->registerIntegration();
        $this->fakeWordPress();

        $result = $this->activity()->execute(
            $this->makeMessage(['title' => 'Written by hand', 'content' => 'Body']),
            app(Apps::class),
            []
        );

        $this->assertSame(101, $result['id']);
    }

    public function testFailsTheWorkflowOnAnEmptyMessageInsteadOfThrowing(): void
    {
        $this->configureSite();
        $this->registerIntegration();
        $this->fakeWordPress();

        $result = $this->activity()->execute($this->makeMessage(['content' => '']), app(Apps::class), []);

        $this->assertStringContainsString('no content', $result['message']);
    }

    private function activity(): PushMessageToWordPressActivity
    {
        return new PushMessageToWordPressActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
    }

    private function registerIntegration(): void
    {
        $user = auth()->user();

        $this->setIntegration(
            app(Apps::class),
            IntegrationsEnum::WORDPRESS,
            'Kanvas\\Connectors\\WordPress\\Handlers\\WordPressHandler',
            $user->getCurrentCompany(),
            $user
        );
    }

    private function configureSite(): void
    {
        $this->company()->set(ConfigurationEnum::SITE_URL->value, self::SITE_URL);
        $this->company()->set(ConfigurationEnum::USERNAME->value, 'editor');
        $this->company()->set(ConfigurationEnum::APPLICATION_PASSWORD->value, 'abcd efgh ijkl mnop');
    }

    /**
     * Test classes share the cached user and its company, and custom fields outlive a test — clear
     * both levels so the "missing credentials" branch is actually exercised.
     */
    private function clearSiteConfiguration(): void
    {
        foreach ([ConfigurationEnum::SITE_URL, ConfigurationEnum::USERNAME, ConfigurationEnum::APPLICATION_PASSWORD] as $key) {
            $this->company()->del($key->value);
            app(Apps::class)->del($key->value);
        }
    }

    private function fakeWordPress(): void
    {
        Http::fake(fn (Request $request) => Http::response([
            'id' => 101,
            'link' => self::SITE_URL . '/?p=101',
            'status' => 'draft',
            'categories' => [],
            'tags' => [],
        ], 201));
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
