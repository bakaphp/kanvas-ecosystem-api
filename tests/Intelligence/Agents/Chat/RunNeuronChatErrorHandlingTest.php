<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Chat;

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

    private function runChatWithThrowingHandler(Throwable $exception): string
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
        )->execute();
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
