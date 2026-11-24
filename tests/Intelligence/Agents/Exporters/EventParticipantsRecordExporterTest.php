<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Exporters;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Support\CompanyScope;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Neuron\Exporters\EventParticipantsRecordExporter;
use Tests\TestCase;
use Tests\Traits\BuildsEventVersionFixtures;

final class EventParticipantsRecordExporterTest extends TestCase
{
    use BuildsEventVersionFixtures;

    public function testExportsParticipantsThroughACompanyScope(): void
    {
        $version = $this->createEventVersionWithParticipants(participantCount: 2);
        $app = app(Apps::class);
        $scope = CompanyScope::ownCompanyOnly($app, auth()->user()->getCurrentCompany());

        $exporter = new EventParticipantsRecordExporter();
        $rows = $exporter->rows($app, $scope, ['version_id' => $version->getId()]);

        $this->assertCount(2, $rows);
        $this->assertCount(count($exporter->headers()), $rows[0]);
    }

    public function testVersionOutsideTheScopeIsNotFound(): void
    {
        $version = $this->createEventVersionWithParticipants(participantCount: 1);
        $app = app(Apps::class);
        $scope = CompanyScope::ownCompanyOnly($app, auth()->user()->getCurrentCompany());

        $version->companies_id = 999999;
        $version->saveQuietly();

        $this->expectException(ValidationException::class);
        new EventParticipantsRecordExporter()->rows($app, $scope, ['version_id' => $version->getId()]);
    }
}
