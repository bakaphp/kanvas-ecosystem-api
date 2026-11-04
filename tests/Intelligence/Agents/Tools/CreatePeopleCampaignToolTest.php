<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\StateEnums;
use Kanvas\Guild\Campaigns\Jobs\ProcessLeadCampaignJob;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreatePeopleCampaignTool;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Tests\TestCase;
use Tests\Traits\MakesFilesystemRows;

class CreatePeopleCampaignToolTest extends TestCase
{
    use DatabaseTransactions;
    use MakesFilesystemRows;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    private function freshPerson(Companies $company, ?string $participantId = null): People
    {
        $person = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->withUserId(auth()->user()->getId())
            ->create();
        $person->contacts()->delete();
        $person->addEmail('p-' . uniqid() . '@example.com');
        if ($participantId !== null) {
            $person->set('participant_id', $participantId);
        }

        return $person;
    }

    private function tool(Companies $company, ?Users $user = null): CreatePeopleCampaignTool
    {
        return new CreatePeopleCampaignTool()->withContext(app(Apps::class), $company, $user ?? auth()->user());
    }

    private function campaignCount(Companies $company): int
    {
        return Campaign::query()->where('companies_id', $company->getId())->count();
    }

    public function testStoresPerRecipientVariablesAndAttachmentsOnTheRecipientRows(): void
    {
        Queue::fake();
        $company = Companies::factory()->create();
        $withExtras = $this->freshPerson($company);
        $plain = $this->freshPerson($company);

        $result = $this->tool($company)->__invoke(
            recipients: [
                [
                    'person_id' => $withExtras->getId(),
                    'variables' => '{"Saludo":"Hola"}',
                    'attachment_urls' => 'https://x.test/a.pdf, https://x.test/b.pdf',
                ],
                ['person_id' => $plain->getId()],
            ],
            message: 'Hello',
            attachment_urls: ['https://x.test/shared.pdf'],
        );

        $this->assertTrue($result['success']);
        $this->assertSame('ok', $result['outcome']);
        $this->assertSame(2, $result['total_recipients']);

        $campaign = Campaign::findOrFail($result['campaign_id']);
        $this->assertSame(['https://x.test/shared.pdf'], $campaign->attachment_urls);

        $extras = CampaignRecipient::query()->where('lead_campaigns_id', $campaign->getId())->where('peoples_id', $withExtras->getId())->firstOrFail();
        $this->assertSame(['Saludo' => 'Hola'], $extras->variables);
        $this->assertSame(['https://x.test/a.pdf', 'https://x.test/b.pdf'], $extras->attachment_urls);

        $bare = CampaignRecipient::query()->where('lead_campaigns_id', $campaign->getId())->where('peoples_id', $plain->getId())->firstOrFail();
        $this->assertNull($bare->variables);
        $this->assertNull($bare->attachment_urls);

        Queue::assertPushed(ProcessLeadCampaignJob::class);
    }

    public function testInvalidVariablesJsonIsRefusedBeforeAnyWrite(): void
    {
        Queue::fake();
        $company = Companies::factory()->create();
        $person = $this->freshPerson($company);

        $result = $this->tool($company)->__invoke(
            recipients: [['person_id' => $person->getId(), 'variables' => 'hello']],
            message: 'Hello',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
        $this->assertStringContainsString((string) $person->getId(), $result['error']);
        $this->assertSame(0, $this->campaignCount($company));
        Queue::assertNotPushed(ProcessLeadCampaignJob::class);
    }

    public function testMatchesUploadedFilesToPeopleByCustomFieldAndExcludesTheUnmatched(): void
    {
        Queue::fake();
        $company = Companies::factory()->create();
        $otherCompany = Companies::factory()->create();
        $matched = $this->freshPerson($company, 'PA1');
        $unmatched = $this->freshPerson($company, 'PA2');
        $ownFile = $this->makeFilesystemRow($company->getId(), 'PA1.pdf');
        $foreignFile = $this->makeFilesystemRow($otherCompany->getId(), 'PA2.pdf');

        $result = $this->tool($company)->__invoke(
            recipients: [['person_id' => $matched->getId()], ['person_id' => $unmatched->getId()]],
            message: 'Hello',
            attachment_filesystem_ids: [$ownFile->getId(), $foreignFile->getId()],
            attachment_match_field: 'participant_id',
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['total_recipients']);
        $this->assertCount(1, $result['excluded']);
        $this->assertSame($unmatched->getId(), $result['excluded'][0]['people_id']);
        $this->assertSame('missing_attachment', $result['excluded'][0]['compliance_status']);

        $campaign = Campaign::findOrFail($result['campaign_id']);
        $this->assertSame(1, $campaign->recipients()->count());
        $recipient = $campaign->recipients()->firstOrFail();
        $this->assertSame($matched->getId(), $recipient->peoples_id);
        $this->assertSame([$ownFile->url], $recipient->attachment_urls);
    }

    public function testMatchFieldIsRequiredWhenFilesAreGiven(): void
    {
        Queue::fake();
        $company = Companies::factory()->create();
        $person = $this->freshPerson($company, 'PA1');
        $file = $this->makeFilesystemRow($company->getId(), 'PA1.pdf');

        $result = $this->tool($company)->__invoke(
            recipients: [['person_id' => $person->getId()]],
            message: 'Hello',
            attachment_filesystem_ids: [$file->getId()],
        );

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
        $this->assertSame(0, $this->campaignCount($company));
    }

    public function testTestSendGoesToACompanyUserAndCreatesNoCampaign(): void
    {
        Notification::fake();
        Queue::fake();
        $company = Companies::factory()->create();
        $teammate = Users::factory()->create();
        $company->associateUser($teammate, StateEnums::YES->getValue(), $company->branch);
        $person = $this->freshPerson($company);

        $result = $this->tool($company)->__invoke(
            recipients: [['person_id' => $person->getId(), 'variables' => '{"Saludo":"Hola"}']],
            message: 'Hello',
            subject: 'Certificado',
            attachment_urls: ['https://x.test/shared.pdf'],
            test_to: strtoupper((string) $teammate->email),
        );

        $this->assertTrue($result['success']);
        $this->assertSame($teammate->email, $result['test_sent_to']);
        $this->assertSame($person->getId(), $result['rendered_for_person_id']);
        $this->assertArrayNotHasKey('campaign_id', $result);
        $this->assertSame(0, $this->campaignCount($company));
        Queue::assertNotPushed(ProcessLeadCampaignJob::class);

        Notification::assertSentOnDemand(
            Blank::class,
            function (Blank $notification, array $channels, object $notifiable) use ($teammate, $person): bool {
                $data = $notification->getData();
                $this->assertSame('Hola', $data['Saludo']);
                $this->assertSame($person->getId(), $data['people']->getId());
                $this->assertSame(['https://x.test/shared.pdf'], $notification->pathAttachment);

                return $notifiable->routes['mail'] === $teammate->email;
            }
        );
    }

    public function testTestSendToANonMemberIsDenied(): void
    {
        Notification::fake();
        Queue::fake();
        $company = Companies::factory()->create();
        $person = $this->freshPerson($company);

        $result = $this->tool($company)->__invoke(
            recipients: [['person_id' => $person->getId()]],
            message: 'Hello',
            test_to: 'stranger-' . uniqid() . '@example.com',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('denied', $result['outcome']);
        $this->assertSame(0, $this->campaignCount($company));
        Notification::assertNothingSent();
        Queue::assertNotPushed(ProcessLeadCampaignJob::class);
    }
}
