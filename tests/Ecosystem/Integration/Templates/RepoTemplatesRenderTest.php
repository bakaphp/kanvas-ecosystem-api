<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Templates;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Notifications\NewLeadNotification;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Notifications\NewOrderStoreOwnerNotification;
use Kanvas\Templates\Support\TemplateSandbox;
use Tests\TestCase;

/**
 * The templates under resources/views/{emails,push}/*.twig are what new apps and the seeders store,
 * so they must render from the data their notifications actually pass.
 */
final class RepoTemplatesRenderTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'inventory', 'crm', 'ecosystem'];

    public function testEveryRepoTemplateRendersInTheSandbox(): void
    {
        $files = array_merge(
            glob(resource_path('views/emails/*.twig')),
            glob(resource_path('views/emails/*/*.twig')),
            glob(resource_path('views/push/*.twig'))
        );

        $this->assertGreaterThanOrEqual(33, count($files));

        foreach ($files as $file) {
            $rendered = TemplateSandbox::renderHtml(file_get_contents($file), $this->genericContext());

            $this->assertIsString($rendered, basename($file));
        }
    }

    /**
     * A stored template is twig unless someone marks it blade by hand, so nothing that writes template
     * rows from the repo may load a Blade file.
     */
    public function testTemplateWritersOnlyLoadTwigFiles(): void
    {
        $writers = array_merge(glob(database_path('seeders/*.php')), [base_path('src/Kanvas/Apps/Actions/SyncEmailTemplateAction.php')]);
        $offenders = [];

        foreach ($writers as $writer) {
            if (preg_match('#views/(?:emails|push)/[\w/.-]*\.blade\.php#', file_get_contents($writer), $match)) {
                $offenders[] = basename($writer) . ': ' . $match[0];
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testInviteNamesTheInvitersBranchAndLinksTheHash(): void
    {
        $fromUser = auth()->user();

        $rendered = $this->render('emails/userInvite.twig', [
            'app' => app(Apps::class),
            'fromUser' => $fromUser,
            'entity' => ['firstname' => 'Ana', 'lastname' => 'Lopez', 'invite_hash' => 'hash-123'],
        ]);

        $this->assertStringContainsString('Ana Lopez', $rendered);
        $this->assertStringContainsString('hash-123', $rendered);
        $this->assertStringContainsString(e($fromUser->getCurrentBranch()->name), $rendered);
    }

    public function testAdminInviteLinksToTheFrontend(): void
    {
        $rendered = $this->render('emails/adminUserInvite.twig', [
            'app' => app(Apps::class),
            'fromUser' => auth()->user(),
            'entity' => ['firstname' => 'Ana', 'lastname' => 'Lopez', 'invite_hash' => 'hash-123'],
        ]);

        $this->assertStringContainsString(config('kanvas.app.frontend_url') . '/invite/hash-123', $rendered);
    }

    public function testOrderEmailsPrintTotalsAndContactFromTheOrder(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $notification = new NewOrderStoreOwnerNotification($order, ['app' => $app, 'company' => $company]);
        $notification->via($user);

        foreach (['emails/newOrder.twig', 'emails/newOrderStoreOwner.twig'] as $template) {
            $rendered = $this->render($template, $notification->getData());

            $this->assertStringContainsString((string) $order->order_number, $rendered, $template);
            $this->assertStringContainsString(number_format($order->getTotalAmount(), 2), $rendered, $template);
            $this->assertStringContainsString(
                $order->created_at->copy()->addDays(7)->format('d/m/Y'),
                $rendered,
                $template
            );
        }
    }

    public function testNewLeadEmailReadsTheOwnerAndCompany(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'leads_owner_id' => $user->getId(),
        ]);

        $notification = new NewLeadNotification(Lead::query()->findOrFail($lead->getId()), ['app' => $app]);
        $rendered = $this->render('emails/newLead.twig', $notification->getData());

        $this->assertStringContainsString(e($user->firstname), $rendered);
        $this->assertStringContainsString(e($company->name), $rendered);
        $this->assertStringContainsString((string) $lead->uuid, $rendered);
    }

    public function testLowStockListsQueryRows(): void
    {
        $rendered = $this->render('emails/lowStock.twig', [
            'user' => auth()->user(),
            'products' => collect([
                (object) ['product_name' => 'Widget', 'product_slug' => 'widget', 'total_stock_quantity' => 4],
            ]),
        ]);

        $this->assertStringContainsString('Widget', $rendered);
        $this->assertStringContainsString('#d32f2f', $rendered);
    }

    public function testDailyDigestKeepsTheTimezoneOfItsDates(): void
    {
        $start = Carbon::parse('2026-10-01 08:30:00', 'America/Santo_Domingo');

        $rendered = $this->render('emails/dailyLeadsDigest.twig', [
            'company' => auth()->user()->getCurrentCompany(),
            'period_start' => $start,
            'period_end' => $start->copy()->addDay(),
            'total' => 1,
            'top_sources' => [],
            'suspicious_emails_count' => 0,
            'sources' => [],
            'branches' => [],
            'days' => [],
            'leads' => [[
                'name' => 'Ana',
                'email' => 'ana@example.com',
                'phone' => null,
                'source' => 'web',
                'branch' => 'main',
                'created_at' => $start,
            ]],
        ]);

        $this->assertStringContainsString('Oct 1, 2026 8:30 AM', $rendered);
    }

    public function testMentionEmailsRenderTheBodyAsMarkdown(): void
    {
        foreach (['emails/userMentioned.twig', 'emails/agentMentionReply.twig'] as $template) {
            $rendered = $this->render($template, [
                'user' => auth()->user(),
                'fromUserName' => 'Roven PM',
                'agentName' => 'Polly',
                'body' => 'please **review** <script>x</script>',
            ]);

            $this->assertStringContainsString('<strong>review</strong>', $rendered, $template);
            $this->assertStringNotContainsString('<script>', $rendered, $template);
        }
    }

    public function testAgentMentionPushIsValidJson(): void
    {
        $rendered = $this->render('push/agent-mention-reply.twig', [
            'agentName' => 'Polly "the PM"',
            'pushMessage' => 'Done, see "plan"',
        ]);

        $payload = json_decode($rendered, true);

        $this->assertSame('Polly "the PM"', $payload['title']);
        $this->assertSame('Done, see "plan"', $payload['message']);
    }

    private function render(string $template, array $context): string
    {
        return TemplateSandbox::renderHtml(file_get_contents(resource_path('views/' . $template)), $context);
    }

    private function genericContext(): array
    {
        $user = auth()->user();

        return [
            'app' => app(Apps::class),
            'user' => $user,
            'toUser' => $user,
            'fromUser' => $user,
            'company' => $user->getCurrentCompany(),
            'entity' => ['title' => 'Entity', 'firstname' => 'Ana', 'invite_hash' => 'hash'],
            'body' => 'Hello',
            'verifyUrl' => 'https://example.com/verify',
            'resetUrl' => 'https://example.com/reset',
            'products' => [],
            'period_start' => now(),
            'period_end' => now(),
        ];
    }
}
