<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Actions\PullLeadAction;
use Kanvas\Connectors\SalesAssist\Enums\LeadVehicleNoteEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

final class PullLeadVehicleNotesTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    protected function setUp(): void
    {
        parent::setUp();

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );
    }

    public function testAPulledTradeInAndVehicleOfInterestAreAnnouncedOnce(): void
    {
        $record = [
            'Identifier' => ['ProspectId' => (string) random_int(10000000, 99999999)],
            'Prospect' => ['IsAiGenerated' => 'N'],
            'IndividualCustomer' => [
                'FirstName' => 'Trent',
                'LastName' => 'Ferrell',
                'NameRecId' => (string) random_int(10000000, 99999999),
            ],
            'DesiredVehicle' => ['VehicleYear' => '2024', 'VehicleMake' => 'Honda', 'VehicleModel' => 'Civic'],
            'PotentialTrade' => ['TradeVehicleYear' => '2018', 'TradeVehicleMake' => 'Nissan', 'TradeVehicleModel' => 'Altima'],
        ];

        $lead = $this->pull($record);
        $this->pull($record);

        $titles = $this->notes($lead)
            ->map(fn (Message $note): string => $note->message['content'])
            ->filter(fn (string $content): bool => str_ends_with($content, ' Added'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['Trade In Added', 'Vehicle of Interest Added'], $titles);
        $this->assertCount(4, $this->notes($lead));
    }

    private function pull(array $record): Lead
    {
        $user = auth()->user();

        return new PullLeadAction(app(Apps::class), $user->getCurrentCompany(), $user)->execute($record);
    }

    /**
     * @return Collection<int, Message>
     */
    private function notes(Lead $lead): Collection
    {
        $tags = array_map(fn (LeadVehicleNoteEnum $kind): string => $kind->value, LeadVehicleNoteEnum::cases());

        return $lead->systemNotes()->firstOrFail()
            ->messages()
            ->get()
            ->filter(fn (Message $message): bool => $message->hasTag($tags));
    }
}
