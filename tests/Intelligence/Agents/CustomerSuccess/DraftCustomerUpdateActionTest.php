<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\CustomerSuccess;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Github\Enums\ConfigurationEnum;
use Kanvas\Guild\Organizations\Actions\RecordOrganizationNoteAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Actions\CustomerSuccess\DraftCustomerUpdateAction;
use Kanvas\Intelligence\Agents\Enums\CustomerUpdateSkipEnum;
use Kanvas\Intelligence\Agents\Enums\KanvasReleaseFeedEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\CustomerSuccess\CustomerUpdateAgent;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class DraftCustomerUpdateActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social', 'ecosystem'];

    /**
     * @param array<int, array<string, mixed>> $releases
     */
    private function fakeReleases(array $releases): void
    {
        Http::fake(['api.github.com/*' => Http::sequence()->push($releases)->push([])]);
    }

    /**
     * @return array<string, mixed>
     */
    private function release(string $tag, string $publishedAt): array
    {
        return [
            'tag_name' => $tag,
            'name' => $tag,
            'body' => "## Product Search\n\nOperators on attribute filters.",
            'published_at' => $publishedAt,
            'draft' => false,
            'prerelease' => false,
            'html_url' => 'https://github.com/acme/api/releases/tag/' . $tag,
        ];
    }

    /** A chat handler double: canned text, or throws, without reaching a provider. */
    private function handler(string|Throwable $reply): object
    {
        return new class ($reply) {
            public array $seen = [];

            /** RunNeuronChatAction hands over a UserMessage object, not a string. */
            private static function describe(mixed $value): string
            {
                if (is_array($value)) {
                    return implode(' ', array_map(static fn ($item): string => self::describe($item), $value));
                }

                if (is_object($value) && method_exists($value, 'getContent')) {
                    return (string) json_encode($value->getContent());
                }

                return is_scalar($value) ? (string) $value : (string) json_encode($value);
            }

            public function __construct(private readonly string|Throwable $reply)
            {
            }

            public function chat(mixed $messages = []): mixed
            {
                $this->seen[] = self::describe($messages);

                if ($this->reply instanceof Throwable) {
                    throw $this->reply;
                }

                return new class ($this->reply) {
                    public function __construct(private readonly string $text)
                    {
                    }

                    public function getContent(): string
                    {
                        return $this->text;
                    }

                    public function __toString(): string
                    {
                        return $this->text;
                    }
                };
            }
        };
    }

    public function testNoReleasesInTheWindowSkipsTheTurnEntirely(): void
    {
        $this->fakeReleases([]);

        $result = new DraftCustomerUpdateAction(
            $this->seedOrganization(),
            $this->seedAgent(),
            $this->handler(new RuntimeException('the provider must never be reached')),
        )->execute();

        $this->assertFalse($result->hasDraft(), 'nothing shipped, so no LLM turn is paid for');
        $this->assertSame(CustomerUpdateSkipEnum::NO_RELEASES, $result->skipped);
    }

    public function testNothingToSendSentinelYieldsNoDraft(): void
    {
        $this->fakeReleases([$this->release('v1.0.0', now()->subDays(2)->toIso8601String())]);

        $result = new DraftCustomerUpdateAction(
            $this->seedOrganization(),
            $this->seedAgent(),
            $this->handler(CustomerUpdateAgent::NOTHING_TO_SEND),
        )->execute();

        $this->assertFalse($result->hasDraft());
        $this->assertSame(
            CustomerUpdateSkipEnum::AGENT_DECLINED,
            $result->skipped,
            'declining after reading releases is not the same as nothing having shipped'
        );
        $this->assertSame(1, $result->releasesConsidered);
    }

    /**
     * The whole reason this action passes fallbackOnFailure: false. With the default, a provider hiccup
     * returns a friendly apology string — and this caller's output is emailed to a paying customer.
     */
    public function testAFailedTurnThrowsRatherThanReturningFallbackProse(): void
    {
        $this->fakeReleases([$this->release('v1.0.0', now()->subDays(2)->toIso8601String())]);

        $this->expectException(RuntimeException::class);

        new DraftCustomerUpdateAction(
            $this->seedOrganization(),
            $this->seedAgent(),
            $this->handler(new RuntimeException('provider exploded')),
        )->execute();
    }

    public function testASuccessfulDraftCarriesTheReleasesItCovered(): void
    {
        $this->fakeReleases([
            $this->release('v1.0.1', now()->subDays(2)->toIso8601String()),
            $this->release('v1.0.0', now()->subDays(9)->toIso8601String()),
        ]);

        $draft = new DraftCustomerUpdateAction(
            $this->seedOrganization(),
            $this->seedAgent(),
            $this->handler('Product search now supports operators on attribute filters.'),
        )->execute()->draft;

        $this->assertNotNull($draft);
        $this->assertStringContainsString('operators', $draft->body);
        $this->assertEqualsCanonicalizing(['v1.0.0', 'v1.0.1'], $draft->releaseTags);
        $this->assertNotNull($draft->coveredThrough, 'the watermark advances to what was actually read');
    }

    /**
     * The whole feature is personalisation, so the notes must reach the model deterministically rather
     * than depending on it choosing to call read_channel_window.
     */
    public function testTheAccountNotesAreHandedToTheModelNotLeftToAToolCall(): void
    {
        $this->fakeReleases([$this->release('v1.0.0', now()->subDays(2)->toIso8601String())]);
        $organization = $this->seedOrganization();

        new RecordOrganizationNoteAction($organization)->execute(
            body: 'They bought the AR module and are piloting it for a Q3 launch.',
            tag: 'note',
            actingUser: auth()->user(),
            fromIa: false,
        );

        $handler = $this->handler('Something about AR.');

        new DraftCustomerUpdateAction($organization->refresh(), $this->seedAgent(), $handler)->execute();

        $prompt = implode("\n", $handler->seen);
        $this->assertStringContainsString('piloting it for a Q3 launch', $prompt);
        $this->assertStringContainsString('[note', $prompt, 'each note is labelled by kind');
    }

    public function testSaysSoPlainlyWhenTheAccountHasNoNotes(): void
    {
        $this->fakeReleases([$this->release('v1.0.0', now()->subDays(2)->toIso8601String())]);
        $organization = $this->seedOrganization();
        $organization->notes?->forceDelete();

        $handler = $this->handler('Short and generic.');

        new DraftCustomerUpdateAction($organization->refresh(), $this->seedAgent(), $handler)->execute();

        $this->assertStringContainsString('no notes on this account', implode("\n", $handler->seen));
    }

    /**
     * A monthly newsletter covers the month. An account written to yesterday must still see the whole
     * 30-day window — otherwise a second send in the same month shows almost nothing, and a skipped
     * month disappears entirely. Not repeating is the agent's job, using the previous update in the
     * thread; it is not the feed's job.
     */
    public function testTheWindowIsTheMonthNotTheGapSinceTheLastSend(): void
    {
        $this->fakeReleases([
            $this->release('v1.0.2', now()->subDays(1)->toIso8601String()),
            $this->release('v1.0.1', now()->subDays(20)->toIso8601String()),
        ]);

        $organization = $this->seedOrganization();
        $organization->set(DraftCustomerUpdateAction::WATERMARK_FIELD, now()->subDays(2)->toIso8601String());

        $result = new DraftCustomerUpdateAction(
            $organization->refresh(),
            $this->seedAgent(),
            $this->handler('Covered both.'),
        )->execute();

        $this->assertEqualsCanonicalizing(
            ['v1.0.1', 'v1.0.2'],
            $result->draft->releaseTags,
            'the release from 20 days ago is in the month, even though we wrote 2 days ago'
        );
    }

    public function testTheAgentIsToldWhenItLastWroteSoItDoesNotRepeatItself(): void
    {
        $this->fakeReleases([$this->release('v1.0.0', now()->subDays(3)->toIso8601String())]);

        $organization = $this->seedOrganization();
        $organization->set(DraftCustomerUpdateAction::WATERMARK_FIELD, now()->subDays(10)->toIso8601String());

        $handler = $this->handler('An update.');
        new DraftCustomerUpdateAction($organization->refresh(), $this->seedAgent(), $handler)->execute();

        $prompt = implode("\n", $handler->seen);
        $this->assertStringContainsString('We last wrote to them on', $prompt);
        $this->assertStringContainsString('do not repeat it', $prompt);
    }

    public function testTheSubjectLineIsLiftedOutOfTheCompletion(): void
    {
        $this->fakeReleases([$this->release('v1.0.0', now()->subDays(2)->toIso8601String())]);

        $draft = new DraftCustomerUpdateAction(
            $this->seedOrganization(),
            $this->seedAgent(),
            $this->handler("Subject: Filtering by custom attributes\n\nYou can now filter by attribute."),
        )->execute()->draft;

        $this->assertSame('Filtering by custom attributes', $draft->subject);
        $this->assertStringNotContainsString('Subject:', $draft->body, 'the label must never reach the reader');
        $this->assertStringStartsWith('You can now', $draft->body);
    }

    /**
     * A model that forgets the line should still produce something sendable rather than shipping the
     * word "Subject:" into an inbox.
     */
    public function testAMissingSubjectFallsBackInsteadOfCorruptingTheBody(): void
    {
        $this->fakeReleases([$this->release('v1.0.0', now()->subDays(2)->toIso8601String())]);

        $draft = new DraftCustomerUpdateAction(
            $this->seedOrganization(),
            $this->seedAgent(),
            $this->handler('You can now filter by attribute.'),
        )->execute()->draft;

        $this->assertNotSame('', $draft->subject);
        $this->assertSame('You can now filter by attribute.', $draft->body);
    }

    private function seedOrganization(): Organization
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::TOKEN->value, 'test-token');
        $app->set(KanvasReleaseFeedEnum::REPOSITORIES->value, 'acme/api');

        return Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => 'Draft Corp ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    private function seedAgent(): Agent
    {
        $user = auth()->user();
        $type = AgentType::query()->where('handler', CustomerUpdateAgent::class)->firstOrFail();

        return Agent::factory()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'user_id' => $user->getId(),
            'agent_type_id' => $type->getId(),
        ]);
    }
}
