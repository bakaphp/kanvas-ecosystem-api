<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\TypeSafe;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\TypeSafe\Client;
use Kanvas\Connectors\TypeSafe\DataTransferObject\Choice;
use Kanvas\Connectors\TypeSafe\DataTransferObject\Noul;
use Kanvas\Connectors\TypeSafe\DataTransferObject\Score;
use Kanvas\Connectors\TypeSafe\Enums\ConfigurationEnum;
use Kanvas\Connectors\TypeSafe\Enums\DecisionModeEnum;
use Kanvas\Connectors\TypeSafe\Exceptions\TypeSafeException;
use Kanvas\Connectors\TypeSafe\Services\TypeSafeConfigService;
use Tests\TestCase;

class TypeSafeClientTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The settings store persists outside the ambient test transaction, so save and restore anything
     * these tests write — otherwise a real configured key would not survive the run.
     *
     * @var array<string, mixed>
     */
    private array $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('TypeSafe integration tests are skipped in CI');
        }

        foreach (ConfigurationEnum::cases() as $setting) {
            $this->originalSettings[$setting->value] = app(Apps::class)->get($setting->value);
        }

        // Start from a known state rather than whatever the app carries: these settings live in Redis
        // and on `ecosystem`, so neither the ambient test transaction nor a previous run clears them.
        app(Apps::class)->set(ConfigurationEnum::TYPESAFE_MODEL->value, '');
        app(Apps::class)->set(ConfigurationEnum::TYPESAFE_DECISIONS->value, '');
        app(Apps::class)->set(ConfigurationEnum::TYPESAFE_API_KEY->value, 'typesafe-test-key');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalSettings as $key => $value) {
            app(Apps::class)->set($key, $value ?? '');
        }

        parent::tearDown();
    }

    public function test_it_sends_every_question_type_in_one_request(): void
    {
        Http::fake([
            'api.typesafe.ai/v1/systemone' => Http::response([
                'model' => 'jev-1.13.0',
                'answers' => [
                    'wants_human' => ['type' => 'noul', 'noul' => 0.99],
                    'sentiment' => [
                        'type' => 'choice',
                        'choice' => 'angry',
                        'confidence' => 0.82,
                        'probabilities' => ['angry' => 0.8, 'calm' => 0.1, 'excited' => 0.1],
                    ],
                    'severity' => [
                        'type' => 'score',
                        'score' => 1.43,
                        'confidence' => 0.35,
                        'legend' => ['0' => 'Cosmetic', '1' => 'Degraded', '2' => 'Blocking'],
                        'probabilities' => ['0' => 0.0, '1' => 0.57, '2' => 0.43],
                    ],
                ],
                'usage' => ['input_tokens' => 360, 'output_tokens' => 39],
            ], 200),
        ]);

        $result = new Client(app(Apps::class))->ask(
            'I have asked three times now. Can I please just talk to a real person?',
            [
                'wants_human' => new Noul(
                    'Is the customer asking for a human agent?',
                    whenTrue: 'Explicitly asks for a person',
                    whenFalse: 'No sign of wanting a human',
                ),
                'sentiment' => new Choice('How does the customer sound?', [
                    'angry' => 'Frustrated or hostile',
                    'calm' => null,
                    'excited' => null,
                ]),
                'severity' => new Score('How severe is the issue?', ['Cosmetic', 'Degraded', 'Blocking']),
            ],
        );

        $this->assertSame('jev-1.13.0', $result->model);
        $this->assertSame(0.99, $result->noul('wants_human')->noul);
        $this->assertSame('angry', $result->choice('sentiment')->choice);
        $this->assertSame(0.82, $result->choice('sentiment')->confidence);
        $this->assertSame(0.8, $result->choice('sentiment')->probabilities['angry']);
        $this->assertSame(1.43, $result->score('severity')->score);
        $this->assertSame(0.57, $result->score('severity')->probabilities[1]);
        $this->assertSame(360, $result->inputTokens);
        $this->assertSame(39, $result->outputTokens);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            $this->assertSame('Bearer typesafe-test-key', $request->header('Authorization')[0]);
            $this->assertSame(TypeSafeConfigService::DEFAULT_MODEL, $body['model']);
            $this->assertSame([
                'type' => 'noul',
                'instructions' => 'Is the customer asking for a human agent?',
                'criteria' => ['true' => 'Explicitly asks for a person', 'false' => 'No sign of wanting a human'],
            ], $body['questions']['wants_human']);
            $this->assertSame('choice', $body['questions']['sentiment']['type']);
            $this->assertSame(['Cosmetic', 'Degraded', 'Blocking'], $body['questions']['severity']['criteria']);

            return true;
        });
    }

    public function test_a_noul_without_criteria_sends_no_criteria_key(): void
    {
        Http::fake([
            'api.typesafe.ai/v1/systemone' => Http::response([
                'model' => 'jev-1.13.0',
                'answers' => ['spam' => ['type' => 'noul', 'noul' => 0.02]],
            ], 200),
        ]);

        new Client(app(Apps::class))->ask('hello', ['spam' => new Noul('Is this spam?')]);

        Http::assertSent(function (Request $request): bool {
            $this->assertArrayNotHasKey('criteria', $request->data()['questions']['spam']);

            return true;
        });
    }

    public function test_it_retries_a_429_and_answers_from_the_second_attempt(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'slow down'], 429, ['retry-after-ms' => '10'])
            ->push([
                'model' => 'jev-1.13.0',
                'answers' => ['spam' => ['type' => 'noul', 'noul' => 0.02]],
            ], 200);

        $result = new Client(app(Apps::class))->ask('hello', ['spam' => new Noul('Is this spam?')]);

        $this->assertSame(0.02, $result->noul('spam')->noul);
        Http::assertSentCount(2);
    }

    public function test_a_422_is_not_retried_and_surfaces_the_server_message(): void
    {
        Http::fake([
            'api.typesafe.ai/v1/systemone' => Http::response(['error' => ['message' => 'criteria must have at least 2 options']], 422),
        ]);

        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('criteria must have at least 2 options');

        try {
            new Client(app(Apps::class))->ask('hello', ['spam' => new Noul('Is this spam?')]);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_an_answer_missing_its_probability_is_an_error_not_a_zero(): void
    {
        Http::fake([
            'api.typesafe.ai/v1/systemone' => Http::response([
                'model' => 'jev-1.13.0',
                'answers' => ['spam' => ['type' => 'noul']],
            ], 200),
        ]);

        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('missing a numeric "noul"');

        new Client(app(Apps::class))->ask('hello', ['spam' => new Noul('Is this spam?')]);
    }

    public function test_asking_for_the_wrong_answer_type_throws(): void
    {
        Http::fake([
            'api.typesafe.ai/v1/systemone' => Http::response([
                'model' => 'jev-1.13.0',
                'answers' => ['spam' => ['type' => 'noul', 'noul' => 0.02]],
            ], 200),
        ]);

        $result = new Client(app(Apps::class))->ask('hello', ['spam' => new Noul('Is this spam?')]);

        $this->expectException(TypeSafeException::class);

        $result->choice('spam');
    }

    public function test_an_app_without_a_key_cannot_build_a_client(): void
    {
        app(Apps::class)->set(ConfigurationEnum::TYPESAFE_API_KEY->value, '');

        $this->expectException(TypeSafeException::class);

        new Client(app(Apps::class));
    }

    public function test_the_model_is_pinned_unless_the_app_overrides_it(): void
    {
        $this->assertSame('jev-1.13.0', new TypeSafeConfigService(app(Apps::class))->model());

        app(Apps::class)->set(ConfigurationEnum::TYPESAFE_MODEL->value, 'jev-preview');

        $this->assertSame('jev-preview', new TypeSafeConfigService(app(Apps::class))->model());
    }

    public function test_decision_mode_defaults_to_off_and_never_reads_a_typo_as_live(): void
    {
        app(Apps::class)->set(ConfigurationEnum::TYPESAFE_DECISIONS->value, [
            'signal_routing' => 'shadow',
            'contact_checker' => 'LIVE',
            'product_tagging' => 'yes please',
        ]);

        $config = new TypeSafeConfigService(app(Apps::class));

        $this->assertSame(DecisionModeEnum::SHADOW, $config->decisionMode('signal_routing'));
        $this->assertSame(DecisionModeEnum::LIVE, $config->decisionMode('contact_checker'));
        $this->assertSame(DecisionModeEnum::OFF, $config->decisionMode('product_tagging'));
        $this->assertSame(DecisionModeEnum::OFF, $config->decisionMode('never_configured'));
    }

    public function test_every_decision_is_off_when_the_app_has_no_key(): void
    {
        app(Apps::class)->set(ConfigurationEnum::TYPESAFE_API_KEY->value, '');
        app(Apps::class)->set(ConfigurationEnum::TYPESAFE_DECISIONS->value, ['signal_routing' => 'live']);

        $this->assertSame(
            DecisionModeEnum::OFF,
            new TypeSafeConfigService(app(Apps::class))->decisionMode('signal_routing'),
        );
    }

    public function test_choice_builds_its_options_from_a_backed_enum(): void
    {
        $choice = Choice::fromEnum(
            DecisionModeEnum::class,
            'How far along is this decision?',
            [DecisionModeEnum::OFF->value => 'Not rolled out'],
        );

        $this->assertSame(
            ['off' => 'Not rolled out', 'shadow' => null, 'live' => null],
            $choice->options,
        );
        $this->assertSame(
            DecisionModeEnum::SHADOW,
            DecisionModeEnum::from(array_keys($choice->options)[1]),
        );
    }

    public function test_a_question_that_cannot_be_answered_well_is_rejected_before_it_is_sent(): void
    {
        $this->assertThrowsTypeSafeException(fn () => new Choice('Pick one', ['only' => null]));
        $this->assertThrowsTypeSafeException(fn () => new Choice('Pick one', array_fill_keys(range(1, 256), null)));
        $this->assertThrowsTypeSafeException(fn () => new Score('How bad?', ['Only one level']));
        $this->assertThrowsTypeSafeException(fn () => new Score('How bad?', [3 => 'Cosmetic', 7 => 'Blocking']));
        $this->assertThrowsTypeSafeException(fn () => new Noul('   '));
    }

    public function test_models_returns_the_catalogue(): void
    {
        Http::fake([
            'api.typesafe.ai/v1/models' => Http::response([
                'models' => [
                    ['name' => 'jev-latest', 'description' => 'Stable', 'release_date' => '2026-08-01'],
                    ['name' => 'jev-1.13.0', 'description' => 'Pinned', 'release_date' => '2026-08-01'],
                ],
            ], 200),
        ]);

        $models = new Client(app(Apps::class))->models();

        $this->assertCount(2, $models);
        $this->assertSame('jev-1.13.0', $models[1]['name']);
    }

    public function test_validate_credentials_accepts_a_key_the_models_endpoint_answers(): void
    {
        Http::fake(['api.typesafe.ai/v1/models' => Http::response(['models' => [['name' => 'jev-latest']]], 200)]);

        $this->assertTrue(Client::validateCredentials('typesafe-test-key'));
    }

    public function test_validate_credentials_rejects_a_key_the_models_endpoint_refuses(): void
    {
        Http::fake(['api.typesafe.ai/v1/models' => Http::response(['message' => 'invalid key'], 401)]);

        $this->assertFalse(Client::validateCredentials('nope'));
    }

    private function assertThrowsTypeSafeException(callable $callback): void
    {
        try {
            $callback();
        } catch (TypeSafeException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected a TypeSafeException.');
    }
}
