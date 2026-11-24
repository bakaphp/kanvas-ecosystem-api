<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Contracts\ProvidesToolDependencies;
use Kanvas\Intelligence\Agents\Enums\ToolConfirmationResultEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\HasTurnContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Services\ToolConfirmationTokens;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Override;
use ReflectionMethod;
use Tests\TestCase;

final class ToolConfirmationTokensTest extends TestCase
{
    private ToolConfirmationTokens $tokens;
    private string $thread;
    private string $previewTurn;
    private string $laterTurn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = new ToolConfirmationTokens();
        $this->thread = Str::uuid()->toString();
        $this->previewTurn = Str::uuid()->toString();
        $this->laterTurn = Str::uuid()->toString();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testTheSameTurnCannotConfirm(): void
    {
        $token = $this->issue();

        $result = $this->tokens->consume($token, $this->thread, $this->previewTurn, 7, 'tool:act', ['a' => 1]);

        $this->assertSame(ToolConfirmationResultEnum::SAME_TURN, $result);
    }

    public function testALaterTurnConfirmsOnceAndTheTokenIsConsumed(): void
    {
        $token = $this->issue();

        $first = $this->tokens->consume($token, $this->thread, $this->laterTurn, 7, 'tool:act', ['a' => 1]);
        $second = $this->tokens->consume($token, $this->thread, $this->laterTurn, 7, 'tool:act', ['a' => 1]);

        $this->assertSame(ToolConfirmationResultEnum::CONFIRMED, $first);
        $this->assertSame(ToolConfirmationResultEnum::NO_PENDING_PREVIEW, $second);
    }

    public function testASameTurnAttemptDoesNotBurnTheToken(): void
    {
        $token = $this->issue();

        $this->tokens->consume($token, $this->thread, $this->previewTurn, 7, 'tool:act', ['a' => 1]);
        $later = $this->tokens->consume($token, $this->thread, $this->laterTurn, 7, 'tool:act', ['a' => 1]);

        $this->assertSame(ToolConfirmationResultEnum::CONFIRMED, $later);
    }

    public function testAnotherThreadUserToolOrArgumentsFindNothingPending(): void
    {
        $token = $this->issue();

        $otherThread = $this->tokens->consume($token, Str::uuid()->toString(), $this->laterTurn, 7, 'tool:act', ['a' => 1]);
        $otherUser = $this->tokens->consume($token, $this->thread, $this->laterTurn, 8, 'tool:act', ['a' => 1]);
        $otherTool = $this->tokens->consume($token, $this->thread, $this->laterTurn, 7, 'tool:other', ['a' => 1]);
        $otherArguments = $this->tokens->consume($token, $this->thread, $this->laterTurn, 7, 'tool:act', ['a' => 2]);

        foreach ([$otherThread, $otherUser, $otherTool, $otherArguments] as $result) {
            $this->assertSame(ToolConfirmationResultEnum::NO_PENDING_PREVIEW, $result);
        }

        $stillValid = $this->tokens->consume($token, $this->thread, $this->laterTurn, 7, 'tool:act', ['a' => 1]);
        $this->assertSame(ToolConfirmationResultEnum::CONFIRMED, $stillValid);
    }

    public function testAWrongTokenIsRejected(): void
    {
        $this->issue();

        $result = $this->tokens->consume('not-the-token', $this->thread, $this->laterTurn, 7, 'tool:act', ['a' => 1]);

        $this->assertSame(ToolConfirmationResultEnum::TOKEN_MISMATCH, $result);
    }

    public function testArgumentOrderDoesNotMatter(): void
    {
        $token = $this->tokens->issue($this->thread, $this->previewTurn, 7, 'tool:act', ['a' => 1, 'b' => 2]);

        $result = $this->tokens->consume($token, $this->thread, $this->laterTurn, 7, 'tool:act', ['b' => 2, 'a' => 1]);

        $this->assertSame(ToolConfirmationResultEnum::CONFIRMED, $result);
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $token = $this->issue();

        Carbon::setTestNow(now()->addMinutes(ToolConfirmationTokens::TTL_MINUTES + 1));
        $result = $this->tokens->consume($token, $this->thread, $this->laterTurn, 7, 'tool:act', ['a' => 1]);

        $this->assertSame(ToolConfirmationResultEnum::NO_PENDING_PREVIEW, $result);
    }

    public function testOnlyToolsUsingTheConcernReceiveTheTurn(): void
    {
        $withTurn = new class () {
            use HasKanvasContext;
            use HasTurnContext;

            public function turn(): array
            {
                return [$this->turnThreadId, $this->turnId, $this->app->getId()];
            }
        };
        $withoutTurn = new class () {
            use HasKanvasContext;

            public function appId(): int
            {
                return $this->app->getId();
            }
        };

        $host = $this->host();
        $fill = new ReflectionMethod($host, 'fillKanvasContext');

        $fill->invoke($host, $withTurn);
        $fill->invoke($host, $withoutTurn);

        $app = app(Apps::class);
        $this->assertSame(['thread-1', 'turn-1', $app->getId()], $withTurn->turn());
        $this->assertSame($app->getId(), $withoutTurn->appId());
    }

    private function issue(): string
    {
        return $this->tokens->issue($this->thread, $this->previewTurn, 7, 'tool:act', ['a' => 1]);
    }

    private function host(): object
    {
        $user = auth()->user();

        return new class ($user) implements ProvidesToolDependencies {
            use MergesRegisteredTools;

            public function __construct(private readonly object $user)
            {
            }

            public function getThreadId(): string
            {
                return 'thread-1';
            }

            public function currentTurnId(): string
            {
                return 'turn-1';
            }

            #[Override]
            public function toolDependencyCandidates(): array
            {
                return [app(Apps::class), $this->user->getCurrentCompany(), $this->user];
            }
        };
    }
}
