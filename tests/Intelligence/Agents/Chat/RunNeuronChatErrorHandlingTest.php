<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Chat;

use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Chat\RunNeuronChatAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use PDOException;
use RuntimeException;
use Tests\Stubs\Intelligence\ThrowingNeuronHandlerStub;
use Tests\TestCase;
use Throwable;

/**
 * A tool/provider failure must never leak internal detail (exception class, raw message, SQL,
 * DB host) into the channel-facing reply — only a humanized fallback, with the real detail going
 * to Sentry/KanvasConversationStore's usage field instead. See PR discussion: a duplicate-bill
 * insert once surfaced a raw `Illuminate\Database\UniqueConstraintViolationException` with the
 * production RDS hostname directly in a Slack message.
 */
class RunNeuronChatErrorHandlingTest extends TestCase
{
    use DatabaseTransactions;

    public function testDuplicateEntryErrorGetsAHumanizedExplanationInsteadOfRawSql(): void
    {
        $response = $this->runChatWithThrowingHandler($this->uniqueConstraintViolation());

        $this->assertStringContainsString('It looks like that already exists', $response);
        $this->assertStringNotContainsString('UniqueConstraintViolationException', $response);
        $this->assertStringNotContainsString('rds.amazonaws.com', $response);
        $this->assertStringNotContainsString('bills_vendor_number_uq', $response);
    }

    public function testGenericErrorGetsAHumanizedFallbackNotTheRawException(): void
    {
        $response = $this->runChatWithThrowingHandler(new RuntimeException('Gemini returned STOP with no parts'));

        $this->assertStringContainsString('I ran into a hiccup processing that', $response);
        $this->assertStringNotContainsString('RuntimeException', $response);
        $this->assertStringNotContainsString('Gemini returned STOP', $response);
    }

    /**
     * The prose only helps when a human reads it. A caller that files the reply for a pipeline —
     * the newsroom publishes what the agent writes — would otherwise publish "I ran into a hiccup
     * processing that" as an article (KANVAS-ECOSYSTEM-691, Gemini answering with no `parts`).
     */
    public function testAFailureIsRaisedInsteadOfHumanizedWhenTheCallerOptsOut(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini returned STOP with no parts');

        $this->runChatWithThrowingHandler(
            new RuntimeException('Gemini returned STOP with no parts'),
            fallbackOnFailure: false
        );
    }

    private function runChatWithThrowingHandler(Throwable $exception, bool $fallbackOnFailure = true): string
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => ThrowingNeuronHandlerStub::class]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);

        return new RunNeuronChatAction(
            agent: $agent,
            session: null,
            message: 'process this invoice',
            app: $app,
            user: $user,
            handler: new ThrowingNeuronHandlerStub($exception),
            fallbackOnFailure: $fallbackOnFailure,
        )->execute();
    }

    /**
     * A 503 from the model provider is not a fault in the request, so the generic copy sends the
     * person to rephrase something that was never wrong — and offers a hand-off to a human for what
     * a retry fixes by itself.
     */
    public function testAnOverloadedModelProviderTellsThePersonToTryAgain(): void
    {
        $response = $this->runChatWithThrowingHandler($this->providerOverload(503));

        $this->assertStringContainsString('overloaded', $response);
        $this->assertStringContainsString('again', $response);
        $this->assertStringNotContainsString('I ran into a hiccup', $response);
        $this->assertStringNotContainsString('rephrasing', $response);
    }

    /** Same fault, whichever provider reports it — 429 rate limit, 529 Anthropic overloaded, 5xx. */
    public function testEveryTransientProviderStatusReadsAsRetryable(): void
    {
        foreach ([429, 500, 502, 503, 504, 529] as $status) {
            $response = $this->runChatWithThrowingHandler($this->providerOverload($status));

            $this->assertStringContainsString(
                'overloaded',
                $response,
                sprintf('HTTP %d should read as a transient provider fault.', $status),
            );
        }
    }

    /** A 4xx is a bad request of ours; telling someone to retry it would loop them. */
    public function testAClientErrorIsNotTreatedAsRetryable(): void
    {
        $response = $this->runChatWithThrowingHandler($this->providerOverload(400));

        $this->assertStringContainsString('I ran into a hiccup', $response);
        $this->assertStringNotContainsString('overloaded', $response);
    }

    /** The detail still has to stay out of the channel — same rule as every other fallback here. */
    public function testTheProviderResponseBodyNeverReachesTheReply(): void
    {
        $response = $this->runChatWithThrowingHandler($this->providerOverload(503));

        $this->assertStringNotContainsString('generativelanguage.googleapis.com', $response);
        $this->assertStringNotContainsString('ServerException', $response);
        $this->assertStringNotContainsString('gemini', strtolower($response));
    }

    private function providerOverload(int $status): ServerException
    {
        return new ServerException(
            sprintf(
                'Server error: `POST https://generativelanguage.googleapis.com/v1beta/models/'
                . 'gemini-3.7-flash:generateContent` resulted in a `%d` response',
                $status,
            ),
            new Request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.7-flash:generateContent'),
            new Response($status, [], '{"error":{"code":' . $status . ',"message":"This model is currently experiencing high demand."}}'),
        );
    }

    private function uniqueConstraintViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(
            'accounting',
            "insert into bills (bill_number, apps_id, companies_id) values ('1498', 31, 9659)",
            [],
            new PDOException(
                'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '
                . "'31-9659-148291-1498-MDF' for key 'bills.bills_vendor_number_uq' "
                . '(Connection: accounting, Host: prod-kanvas-niche-cluster-cvyag02o4s55.us-east-1.rds.amazonaws.com, Port: 3306)'
            ),
        );
    }
}
