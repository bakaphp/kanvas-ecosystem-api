<?php

declare(strict_types=1);

namespace Tests\Connectors\SalesAssist\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\RecordLeadVehicleNotesAction;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Enums\LeadVehicleNoteEnum;
use Kanvas\Guild\Leads\Enums\LeadMessageTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

final class RecordLeadVehicleNotesActionTest extends TestCase
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

    public function testATradeInPostsTheAnnouncementAndTheRawValue(): void
    {
        $lead = $this->createLead();

        $this->save($lead, LeadVehicleNoteEnum::TRADE_IN, [
            'vin' => '1FTFW1E5XKFA00000',
            'year' => '2019',
            'make' => 'Ford',
            'model' => 'F-150',
            'trim' => null,
            'mileage' => 45000,
            'value' => 0,
            'condition' => 'UNKNOWN',
        ]);

        $this->assertSame(
            [
                'Trade In Added',
                "Trade In\n" . '{"vin":"1FTFW1E5XKFA00000","year":"2019","make":"Ford","model":"F-150","trim":null,"mileage":45000,"value":0,"condition":"UNKNOWN"}',
            ],
            $this->contents($lead)
        );

        $notes = $this->notes($lead);
        $this->assertSame(
            [LeadMessageTypeEnum::AI_CONTROL->value, 'message'],
            $notes->map(fn (Message $note): string => $note->messageType->verb)->all()
        );
        $this->assertSame([1, 0], $notes->map(fn (Message $note): int => $note->is_public)->all());
        foreach ($notes as $note) {
            $this->assertTrue($note->hasTag([LeadVehicleNoteEnum::TRADE_IN->value]));
            $this->assertSame($lead->getId(), $note->entity(Lead::class)?->getId());
        }
    }

    public function testAVehicleOfInterestPostsItsNotes(): void
    {
        $lead = $this->createLead();

        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, [
            'yearFrom' => 2020,
            'make' => 'Toyota',
            'model' => 'Camry',
            'trim' => 'LE',
        ]);

        $this->assertSame(
            ['Vehicle of Interest Added', "Vehicle of Interest\n" . '{"yearFrom":2020,"make":"Toyota","model":"Camry","trim":"LE"}'],
            $this->contents($lead)
        );
    }

    public function testTheSameVehicleIsNotAnnouncedTwice(): void
    {
        $lead = $this->createLead();

        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['year' => 2024, 'make' => 'Honda', 'model' => 'Civic']);
        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['year' => 2024, 'make' => 'Honda', 'model' => 'Civic']);

        $this->assertCount(2, $this->notes($lead));
    }

    public function testAChangedVehicleIsAnnouncedAgain(): void
    {
        $lead = $this->createLead();

        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['year' => 2024, 'make' => 'Honda', 'model' => 'Civic']);
        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['year' => 2025, 'make' => 'Honda', 'model' => 'Accord']);

        $this->assertSame(
            [
                'Vehicle of Interest Added',
                "Vehicle of Interest\n" . '{"year":2024,"make":"Honda","model":"Civic"}',
                'Vehicle of Interest Added',
                "Vehicle of Interest\n" . '{"year":2025,"make":"Honda","model":"Accord"}',
            ],
            $this->contents($lead)
        );
    }

    public function testAFreshMileageOnTheSameVehicleIsNotAnnouncedAgain(): void
    {
        $lead = $this->createLead();
        $tradeIn = ['id' => '5264344a', 'year' => 2024, 'make' => 'Nissan', 'model' => 'Sentra', 'vin' => '3N1AB8CV8RY348235'];

        $this->save($lead, LeadVehicleNoteEnum::TRADE_IN, $tradeIn + ['estimatedMileage' => 59354]);
        $this->save($lead, LeadVehicleNoteEnum::TRADE_IN, $tradeIn + ['estimatedMileage' => 59611]);

        $this->assertCount(2, $this->notes($lead));
    }

    public function testVehiclesSentInTurnsAreEachAnnouncedOnce(): void
    {
        $lead = $this->createLead();
        $audi = ['make' => 'Audi', 'model' => 'Q5', 'vin' => 'WA1ANAFY0K2101825', 'maxMileage' => 81523];
        $civic = ['make' => 'Honda', 'model' => 'Civic', 'vin' => 'SHHFK7H66MU223008', 'maxMileage' => 72115];

        foreach ([$audi, $civic, $audi, $civic, ['maxMileage' => 81531] + $audi] as $vehicle) {
            $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, $vehicle);
        }

        $this->assertCount(4, $this->notes($lead));
    }

    public function testTheSameVehicleWithItsKeysReorderedIsNotAnnouncedAgain(): void
    {
        $lead = $this->createLead();

        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['year' => 2024, 'make' => 'Honda', 'model' => 'Civic']);
        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['model' => 'Civic', 'make' => 'Honda', 'year' => 2024]);

        $this->assertCount(2, $this->notes($lead));
    }

    public function testAVehicleAnnouncedBeforeFingerprintsIsNotAnnouncedAgain(): void
    {
        $lead = $this->createLead();
        $vehicle = ['year' => 2024, 'make' => 'Honda', 'model' => 'Civic', 'mileage' => 100];
        $lead->set(LeadVehicleNoteEnum::VEHICLE_OF_INTEREST->noteHashKey(), md5((string) json_encode($vehicle)));

        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, $vehicle);
        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['mileage' => 250] + $vehicle);

        $this->assertCount(0, $this->notes($lead));
    }

    public function testTheSameVinIsAnnouncedOnceWhateverElseChanges(): void
    {
        $lead = $this->createLead();
        $tradeIn = ['vin' => '2T3P1RFV0RC404865', 'year' => 2024, 'make' => 'Toyota', 'model' => 'RAV4'];

        $this->save($lead, LeadVehicleNoteEnum::TRADE_IN, $tradeIn + ['value' => 0, 'payOff' => 0, 'driveTrain' => 'AWD']);
        $this->save($lead, LeadVehicleNoteEnum::TRADE_IN, $tradeIn + ['value' => 23000, 'payOff' => 22197.42, 'driveTrain' => 'AWD']);
        $this->save($lead, LeadVehicleNoteEnum::TRADE_IN, [
            'vin' => ' 2t3p1rfv0rc404865 ',
            'year' => '2024',
            'make' => 'TOYOTA',
            'model' => 'RAV4',
            'value' => 24632,
            'driveTrain' => 'Regular Gasoline I-4 2.5',
            'autoEntity' => ['vin' => '2T3P1RFV0RC404865', 'lastUpdate' => '2026-11-24T03:26:46.69', 'cost' => 22901],
        ]);

        $this->assertCount(2, $this->notes($lead));
    }

    public function testAnotherVinOnTheSameModelIsAnnounced(): void
    {
        $lead = $this->createLead();
        $vehicle = ['year' => 2024, 'make' => 'BMW', 'model' => 'iX', 'trim' => 'xDrive50'];

        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['vin' => 'WB523CF00RCN74100'] + $vehicle);
        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['vin' => 'WB523CF05RCP39476'] + $vehicle);

        $this->assertCount(4, $this->notes($lead));
    }

    public function testAVehicleWithoutAVinIsToldApartByYearMakeAndModel(): void
    {
        $lead = $this->createLead();

        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['year' => '2024', 'make' => 'Subaru', 'model' => 'Ascent', 'sellingPrice' => 41000]);
        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['year' => 2024, 'make' => 'SUBARU', 'model' => 'ASCENT', 'sellingPrice' => 39500]);
        $this->assertCount(2, $this->notes($lead));

        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['year' => 2022, 'make' => 'Subaru', 'model' => 'Outback']);
        $this->assertCount(4, $this->notes($lead));
    }

    public function testAPlaceholderInTheVinFieldDoesNotMergeDifferentVehicles(): void
    {
        $lead = $this->createLead();

        $this->save($lead, LeadVehicleNoteEnum::TRADE_IN, ['vin' => 'N/A', 'year' => 2018, 'make' => 'Nissan', 'model' => 'Altima']);
        $this->save($lead, LeadVehicleNoteEnum::TRADE_IN, ['vin' => 'N/A', 'year' => 2020, 'make' => 'Kia', 'model' => 'Rio']);
        $this->assertCount(4, $this->notes($lead));

        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['vin' => 'Not Available', 'year' => 2024, 'make' => 'Honda', 'model' => 'Civic']);
        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['vin' => 'Not Available', 'year' => 2025, 'make' => 'Honda', 'model' => 'Accord']);
        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['vin' => '00000000000000000', 'year' => 2023, 'make' => 'Honda', 'model' => 'Pilot']);
        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['vin' => '00000000000000000', 'year' => 2022, 'make' => 'Honda', 'model' => 'CR-V']);
        $this->assertCount(12, $this->notes($lead));
    }

    public function testTheInventoryRecordsVinIsUsedWhenTheVehicleHasNone(): void
    {
        $lead = $this->createLead();
        $vehicle = ['vin' => null, 'make' => 'BMW', 'model' => 'iX', 'autoEntity' => ['vin' => 'WB523CF05RCP39476', 'vinPrefix' => 'WB523CF0RC']];

        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['sellingPrice' => 47789] + $vehicle);
        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, ['sellingPrice' => 47000, 'model' => 'iX xDrive50'] + $vehicle);

        $this->assertCount(2, $this->notes($lead));
    }

    public function testAChangeInAListOfVehiclesIsAnnounced(): void
    {
        $lead = $this->createLead();
        $first = ['vin' => 'WA1ANAFY0K2101825', 'make' => 'Audi', 'model' => 'Q5'];

        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, [$first, ['vin' => 'SHHFK7H66MU223008', 'make' => 'Honda', 'model' => 'Civic']]);
        $this->save($lead, LeadVehicleNoteEnum::VEHICLE_OF_INTEREST, [$first, ['vin' => '3N1AB8CV8RY348235', 'make' => 'Nissan', 'model' => 'Sentra']]);

        $this->assertCount(4, $this->notes($lead));
    }

    public function testAVehicleAnnouncedUnderTheValueFingerprintIsNotAnnouncedAgain(): void
    {
        $lead = $this->createLead();
        $vehicle = ['make' => 'Audi', 'model' => 'Q5', 'vin' => 'WA1ANAFY0K2101825', 'value' => 0];
        $lead->set(
            LeadVehicleNoteEnum::TRADE_IN->noteFingerprintsKey(),
            [md5((string) json_encode(['make' => 'Audi', 'model' => 'Q5', 'value' => 0, 'vin' => 'WA1ANAFY0K2101825']))]
        );

        $this->save($lead, LeadVehicleNoteEnum::TRADE_IN, $vehicle);
        $this->save($lead, LeadVehicleNoteEnum::TRADE_IN, ['value' => 12500] + $vehicle);

        $this->assertCount(0, $this->notes($lead));
    }

    public function testAnEmptyValueIsNotAnnounced(): void
    {
        $lead = $this->createLead();

        $this->save($lead, LeadVehicleNoteEnum::TRADE_IN, []);

        $this->assertCount(0, $this->notes($lead));
    }

    public function testRecordAllAnnouncesOnlyTheVehiclesTheLeadHas(): void
    {
        $lead = $this->createLead();
        $lead->set(LeadCustomFieldEnum::TRADE_IN->value, ['year' => 2018, 'make' => 'Nissan', 'model' => 'Altima']);

        RecordLeadVehicleNotesAction::recordAll($lead);

        $this->assertSame(
            ['Trade In Added', "Trade In\n" . '{"year":2018,"make":"Nissan","model":"Altima"}'],
            $this->contents($lead)
        );
    }

    private function save(Lead $lead, LeadVehicleNoteEnum $kind, array $value): void
    {
        $lead->set($kind->customField(), $value);
        new RecordLeadVehicleNotesAction($lead, $kind)->execute();
    }

    private function createLead(): Lead
    {
        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), auth()->user()->getCurrentCompany()->getId())
            ->create();
    }

    /**
     * @return list<string>
     */
    private function contents(Lead $lead): array
    {
        return $this->notes($lead)->map(fn (Message $note): string => $note->message['content'])->all();
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
            ->filter(fn (Message $message): bool => $message->hasTag($tags))
            ->sortBy('id')
            ->values();
    }
}
