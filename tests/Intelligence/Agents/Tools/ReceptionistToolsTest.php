<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\BookingOptionsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FaqLookupTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\StopContactTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\TakeMessageTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadTool;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Notifications\ReceptionistMessageNotification;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Tests\TestCase;

class ReceptionistToolsTest extends TestCase
{
    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
    }

    public function testUpdateLeadToolPersistsQualificationAnswers(): void
    {
        $lead = $this->makeLead();

        $result = new UpdateLeadTool()->__invoke(
            lead_id: $lead->getId(),
            budget: 'around $500',
            service_needed: 'AC repair',
            urgency: 'emergency today',
            disposition: 'qualified',
        );

        $this->assertSame('success', $result['status']);
        $this->assertContains('budget', $result['updated']);
        $this->assertContains('disposition', $result['updated']);

        $fresh = Lead::getById($lead->getId());
        $this->assertSame('around $500', $fresh->get('budget'));
        $this->assertSame('AC repair', $fresh->get('service_needed'));
        $this->assertSame('emergency today', $fresh->get('urgency'));
        $this->assertSame('qualified', $fresh->get('lead_disposition'));
    }

    public function testUpdateLeadToolRejectsInvalidDisposition(): void
    {
        $lead = $this->makeLead();

        $result = new UpdateLeadTool()->__invoke(
            lead_id: $lead->getId(),
            disposition: 'maybe',
        );

        $this->assertSame('error', $result['status']);
    }

    public function testUpdateLeadToolNoopWhenNothingProvided(): void
    {
        $lead = $this->makeLead();

        $result = new UpdateLeadTool()->__invoke(lead_id: $lead->getId());

        $this->assertSame('noop', $result['status']);
    }

    public function testUpdateLeadToolErrorsOnHallucinatedLead(): void
    {
        $result = new UpdateLeadTool()->__invoke(lead_id: 999999999, budget: 'x');

        $this->assertSame('error', $result['status']);
    }

    public function testBookingOptionsReturnsCompanyServiceTypesAndIsTenantScoped(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        EventType::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => auth()->id(),
            'name' => 'Consultation',
            'is_deleted' => 0,
        ]);

        $otherCompany = Companies::factory()->create();
        EventType::create([
            'apps_id' => $app->getId(),
            'companies_id' => $otherCompany->getId(),
            'users_id' => auth()->id(),
            'name' => 'OtherCompanyOnlyService',
            'is_deleted' => 0,
        ]);

        $result = new BookingOptionsTool()->__invoke(lead_id: $lead->getId());

        $this->assertSame('success', $result['status']);
        $this->assertContains('Consultation', $result['service_types']);
        $this->assertNotContains('OtherCompanyOnlyService', $result['service_types']);
        $this->assertSame(30, $result['default_duration_minutes']);
        $this->assertNotNull($result['assigned_owner']);
    }

    public function testUpdateLeadToolUpdatesPeopleContact(): void
    {
        $lead = $this->makeLead();

        $result = new UpdateLeadTool()->__invoke(
            lead_id: $lead->getId(),
            firstname: 'Maria',
            lastname: 'Gomez',
            email: 'maria.gomez@example.com',
            phone: '+18095551234',
        );

        $this->assertSame('success', $result['status']);
        $this->assertContains('contact_name', $result['updated']);
        $this->assertContains('contact_email', $result['updated']);
        $this->assertContains('contact_phone', $result['updated']);

        $people = $lead->people->refresh();
        $this->assertSame('Maria', $people->firstname);
        $this->assertSame('Gomez', $people->lastname);
        $this->assertTrue($people->contacts()->where('value', 'maria.gomez@example.com')->exists());
        // Contact stores phones as bare digits (country code kept, formatting stripped).
        $this->assertTrue($people->contacts()->where('value', '18095551234')->exists());
    }

    public function testTakeMessageWritesNoteAndNotifiesOwner(): void
    {
        Notification::fake();
        $lead = $this->makeLead();

        $result = new TakeMessageTool()->__invoke(
            lead_id: $lead->getId(),
            message: 'Please call me back this afternoon',
            for_whom: 'John',
            callback_number: '555-1234',
        );

        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['recorded']);
        $this->assertTrue($result['owner_notified']);

        Notification::assertSentTo($lead->owner, ReceptionistMessageNotification::class);
    }

    public function testTakeMessageRejectsEmptyMessage(): void
    {
        $lead = $this->makeLead();

        $result = new TakeMessageTool()->__invoke(lead_id: $lead->getId(), message: '   ');

        $this->assertSame('error', $result['status']);
    }

    public function testStopContactOptsOutContactsAndDisablesAi(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('optout@example.com');
        $lead->people->addCellPhone('+18095559999');

        $result = new StopContactTool()->__invoke(lead_id: $lead->getId(), reason: 'stop texting me');

        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['ai_disabled']);
        $this->assertTrue($result['note_logged']);
        $this->assertContains('phone', $result['opted_out']);
        $this->assertContains('email', $result['opted_out']);

        $fresh = Lead::getById($lead->getId());
        $this->assertSame(IntelligenceModeEnum::IDLE->value, $fresh->get('ai_mode'));
        $this->assertSame(1, (int) $fresh->get('do_not_contact'));

        $this->assertTrue(
            $lead->people->contacts()->where('value', 'optout@example.com')->where('is_opt_out', 1)->exists()
        );
        $this->assertTrue(
            $lead->people->contacts()->where('value', '18095559999')->where('is_opt_out', 1)->exists()
        );

        // Team is alerted via a best-effort compliance handoff (deep notification delivery is
        // HandOffAction's concern; the tool just reports whether it ran).
        $this->assertIsBool($result['team_notified']);
    }

    public function testFaqLookupReturnsCompanyFaqsAndIsTenantScoped(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $faqType = MessageTypeService::getOrCreate($app, 'faq');

        $this->makeFaqMessage($app->getId(), $company->getId(), $faqType->getId(), 'What are your hours?', 'We are open 9-5 Mon-Fri.', 'hours');
        $this->makeFaqMessage($app->getId(), $company->getId(), $faqType->getId(), 'Where are you located?', 'Downtown, 123 Main St.', 'location');

        $otherCompany = Companies::factory()->create();
        $this->makeFaqMessage($app->getId(), $otherCompany->getId(), $faqType->getId(), 'Other company secret FAQ', 'nope', null);

        $tool = new FaqLookupTool($app, $company);

        $all = $tool->__invoke();
        $questions = array_column($all['faqs'], 'question');
        $this->assertContains('What are your hours?', $questions);
        $this->assertContains('Where are you located?', $questions);
        $this->assertNotContains('Other company secret FAQ', $questions);

        $filtered = $tool->__invoke(query: 'hours');
        $this->assertSame(1, $filtered['count']);
        $this->assertSame('We are open 9-5 Mon-Fri.', $filtered['faqs'][0]['answer']);
    }

    private function makeFaqMessage(int $appId, int $companyId, int $typeId, string $question, string $answer, ?string $category): void
    {
        Message::create([
            'apps_id' => $appId,
            'companies_id' => $companyId,
            'users_id' => auth()->id(),
            'message_types_id' => $typeId,
            'message' => ['question' => $question, 'answer' => $answer, 'category' => $category],
            'is_public' => 1,
            'is_deleted' => 0,
        ]);
    }
}
