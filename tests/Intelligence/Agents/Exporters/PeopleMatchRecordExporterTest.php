<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Exporters;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Exporters\PeopleMatchRecordExporter;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class PeopleMatchRecordExporterTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->actingUser = static::$cachedUser;
        $this->currentCompany = $this->actingUser->getCurrentCompany();
        $this->tag = 'Match' . uniqid();
    }

    /**
     * A 300-name sheet used to throw "at most 100 names per export", and the model obeyed that error
     * literally — three exporter calls, three CSVs the user had to stitch back together.
     */
    public function test_a_list_longer_than_one_query_batch_exports_as_a_single_row_set(): void
    {
        $first = $this->makePerson('Adanela', 'Cedeno');
        $late = $this->makePerson('Jorgelina', 'Duran');
        $last = $this->makePerson('Karina', 'Piantini');

        $names = $this->fillerNames(250);
        $names[0] = $this->fullName('Adanela', 'Cedeno');
        $names[180] = $this->fullName('Jorgelina', 'Duran');
        $names[249] = $this->fullName('Karina', 'Piantini');

        $rows = $this->exporter()->rows(
            $this->currentApp,
            $this->currentCompany,
            ['names' => implode("\n", $names)],
        );

        $this->assertCount(250, $rows);

        foreach ($rows as $index => $row) {
            $this->assertSame($names[$index], $row[0], 'Row ' . $index . ' lost its input order');
        }

        $this->assertSame('Yes', $rows[0][1]);
        $this->assertSame((int) $first->getId(), $rows[0][2]);

        $this->assertSame('Yes', $rows[180][1]);
        $this->assertSame((int) $late->getId(), $rows[180][2]);

        $this->assertSame('Yes', $rows[249][1]);
        $this->assertSame((int) $last->getId(), $rows[249][2]);
    }

    public function test_a_name_past_the_first_batch_is_still_found(): void
    {
        $person = $this->makePerson('Celenia', 'Vidal');

        $names = $this->fillerNames(150);
        $names[149] = $this->fullName('Celenia', 'Vidal');

        $rows = $this->exporter()->rows(
            $this->currentApp,
            $this->currentCompany,
            ['names' => implode(',', $names)],
        );

        $this->assertSame('Yes', $rows[149][1]);
        $this->assertSame((int) $person->getId(), $rows[149][2]);
    }

    public function test_unmatched_names_stay_in_place_as_blank_rows(): void
    {
        $names = $this->fillerNames(120);

        $rows = $this->exporter()->rows(
            $this->currentApp,
            $this->currentCompany,
            ['names' => implode("\n", $names)],
        );

        $this->assertCount(120, $rows);
        $this->assertSame('No', $rows[110][1]);
        $this->assertSame('', $rows[110][2]);
        $this->assertSame('', $rows[110][3]);
    }

    public function test_a_duplicate_across_the_batch_boundary_collapses_to_one_row(): void
    {
        $names = $this->fillerNames(150);
        $names[149] = $names[10];

        $rows = $this->exporter()->rows(
            $this->currentApp,
            $this->currentCompany,
            ['names' => implode("\n", $names)],
        );

        $this->assertCount(149, $rows);
    }

    public function test_a_list_beyond_the_export_ceiling_still_errors(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('at most 1000 names per export');

        $this->exporter()->rows(
            $this->currentApp,
            $this->currentCompany,
            ['names' => implode("\n", $this->fillerNames(1001))],
        );
    }

    public function test_missing_names_is_an_actionable_error(): void
    {
        $this->expectException(ValidationException::class);

        $this->exporter()->rows($this->currentApp, $this->currentCompany, []);
    }

    /**
     * @return list<string>
     */
    private function fillerNames(int $count): array
    {
        return array_map(
            fn (int $i): string => 'Ausente' . $this->tag . $i . ' Nohay' . $this->tag . $i,
            range(1, $count),
        );
    }

    private function fullName(string $first, string $last): string
    {
        return $first . $this->tag . ' ' . $last . $this->tag;
    }

    private function exporter(): PeopleMatchRecordExporter
    {
        return new PeopleMatchRecordExporter();
    }

    private function makePerson(string $first, string $last): People
    {
        return People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId())
            ->withUserId($this->actingUser->getId())
            ->create([
                'firstname' => $first . $this->tag,
                'lastname' => $last . $this->tag,
            ]);
    }
}
