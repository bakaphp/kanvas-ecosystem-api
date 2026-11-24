<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intellicheck\Actions\VerifyPeopleIdAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class VerifyPeopleIdActionLicenseFileNamesTest extends TestCase
{
    public function testASideFromBase64IsNumberedPerSide(): void
    {
        $lead = $this->makeLead();

        $this->assertSame('drivers-license-front-1.jpg', $this->reserveImageFileName($lead, 'drivers_license_front'));
        $this->assertSame('drivers-license-back-1.jpg', $this->reserveImageFileName($lead, 'drivers_license_back'));
        $this->assertSame('drivers-license-front-2.jpg', $this->reserveImageFileName($lead, 'drivers_license_front'));
        $this->assertSame([
            ['name' => 'drivers-license-front', 'count' => 2],
            ['name' => 'drivers-license-back', 'count' => 1],
        ], $lead->get('file-count'));
    }

    /** A uuid is an existing file: it is looked up, never uploaded again, so it must not spend a number. */
    public function testAnExistingFileReservesNoNumber(): void
    {
        $lead = $this->makeLead();

        $this->assertNull($this->resolveFile($lead, (string) Str::uuid(), 'drivers_license_front'));
        $this->assertNull($lead->get('file-count'));
    }

    public function testTheSelfieIsNotNumbered(): void
    {
        $lead = $this->makeLead();

        $this->assertSame('face_image.jpg', $this->reserveImageFileName($lead, 'face_image'));
        $this->assertNull($lead->get('file-count'));
    }

    private function reserveImageFileName(Lead $lead, string $fieldName): string
    {
        return $this->action($lead)->reserve($fieldName);
    }

    private function resolveFile(Lead $lead, string $image, string $fieldName): ?Filesystem
    {
        return $this->action($lead)->resolve($image, $fieldName);
    }

    private function action(Lead $lead): VerifyPeopleIdAction
    {
        return new class ($lead->people, $lead) extends VerifyPeopleIdAction {
            public function reserve(string $fieldName): string
            {
                return $this->reserveImageFileName($fieldName);
            }

            public function resolve(string $image, string $fieldName): ?Filesystem
            {
                return $this->resolveFile($image, $fieldName);
            }
        };
    }

    private function makeLead(): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }
}
