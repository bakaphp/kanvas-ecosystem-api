<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

class MessageEntityTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
    }

    /**
     * The Twilio / RespondIO / SalesAssist shape: one message, a People row and a Lead row.
     * app_module_message has no ordering, so the People row can come back first.
     */
    public function testEntityByClassReturnsTheLeadEvenWhenThePeopleRowComesFirst(): void
    {
        $lead = $this->createLead();
        $message = $this->createMessage();

        $message->addEntity(People::getById((int) $lead->people_id));
        $message->addEntity($lead);

        $message = $message->fresh();

        $this->assertInstanceOf(People::class, $message->entity());
        $this->assertSame($lead->getId(), $message->entity(Lead::class)?->getId());
        $this->assertSame((int) $lead->people_id, $message->entity(People::class)?->getId());
    }

    public function testEntityByClassReturnsNullWhenNothingOfThatTypeIsLinked(): void
    {
        $message = $this->createMessage();
        $message->addEntity($this->createPeople());

        $this->assertNull($message->fresh()->entity(Lead::class));
    }

    public function testEntitiesReturnsEveryLinkedModel(): void
    {
        $lead = $this->createLead();
        $message = $this->createMessage();

        $message->addEntity($lead);
        $message->addEntity(People::getById((int) $lead->people_id));

        $entities = $message->fresh()->entities();

        $this->assertCount(2, $entities);
        $this->assertInstanceOf(Lead::class, $entities->first(fn (Model $entity) => $entity instanceof Lead));
        $this->assertInstanceOf(People::class, $entities->first(fn (Model $entity) => $entity instanceof People));
    }

    public function testEntityByClassResolvesLegacyModuleNames(): void
    {
        $lead = $this->createLead();
        $message = $this->createMessage();

        DB::connection('social')->table('app_module_message')->insert([
            'message_id' => $message->getId(),
            'message_types_id' => $message->message_types_id,
            'apps_id' => $message->apps_id,
            'companies_id' => $message->companies_id,
            'system_modules' => 'Gewaer\\Models\\Leads',
            'entity_id' => $lead->getId(),
            'created_at' => now(),
        ]);

        $this->assertSame($lead->getId(), $message->fresh()->entity(Lead::class)?->getId());
    }

    public function testAddEntityIsVisibleOnTheSameInstance(): void
    {
        $lead = $this->createLead();
        $message = $this->createMessage();

        $this->assertNull($message->entity(Lead::class));

        $message->addEntity($lead);

        $this->assertSame($lead->getId(), $message->entity(Lead::class)?->getId());
    }

    public function testPreferredEntityFallsBackToWhateverIsLinked(): void
    {
        $people = $this->createPeople();
        $message = $this->createMessage();
        $message->addEntity($people);

        $this->assertSame($people->getId(), $message->preferredEntity(Lead::class)?->getId());
    }

    public function testEntitiesSkipsALinkWhoseEntityWasSoftDeleted(): void
    {
        $lead = $this->createLead();
        $people = $this->createPeople();
        $message = $this->createMessage();

        $message->addEntity($people);
        $message->addEntity($lead);
        $people->softDelete();

        $entities = $message->fresh()->entities();

        $this->assertCount(1, $entities);
        $this->assertSame($lead->getId(), $entities->first()->getId());
    }

    public function testAMessageWithNoLinksReturnsNothing(): void
    {
        $message = $this->createMessage();

        $this->assertNull($message->entity());
        $this->assertNull($message->entity(Lead::class));
        $this->assertTrue($message->entities()->isEmpty());
    }

    private function createLead(): Lead
    {
        return Lead::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
        ]);
    }

    private function createPeople(): People
    {
        return People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }

    private function createMessage(): Message
    {
        return Message::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
        ]);
    }
}
