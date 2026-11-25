<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Baka\Enums\StateEnums;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\ActionEngine\Engagements\Actions\AttachUploadedFilesToLeadAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\CreatesFilesystemRecords;

final class AttachUploadedFilesToLeadActionTest extends TestCase
{
    use CreatesFilesystemRecords;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    public function testAttachesEveryPayloadFileToTheLeadUnderItsOwnName(): void
    {
        $lead = $this->makeLead();
        $paystub = $this->createFilesystemRecord('paystub.pdf');
        $w2 = $this->createFilesystemRecord('w2.pdf');
        $taxes = $this->createFilesystemRecord('1040.pdf');

        $attached = new AttachUploadedFilesToLeadAction($lead, $this->payload([
            1 => [$paystub, $w2],
            2 => [$taxes],
        ]))->execute();

        $this->assertCount(3, $attached);
        $this->assertSame(['paystub.pdf', 'w2.pdf', '1040.pdf'], $this->attachedFieldNames($lead));
    }

    public function testAcceptsAFileReferencedByIdLikeTheMessagePayload(): void
    {
        $lead = $this->makeLead();
        $file = $this->createFilesystemRecord('paystub.pdf');

        $attached = new AttachUploadedFilesToLeadAction(
            $lead,
            $this->payload([1 => [$file]], byId: true)
        )->execute();

        $this->assertCount(1, $attached);
        $this->assertSame(['paystub.pdf'], $this->attachedFieldNames($lead));
    }

    public function testRejectsAFileFromAnotherCompanyAndWritesNothing(): void
    {
        $lead = $this->makeLead();
        $own = $this->createFilesystemRecord('own.pdf');
        $foreign = $this->createFilesystemRecord('foreign.pdf', companyOffset: 100000);

        try {
            new AttachUploadedFilesToLeadAction($lead, $this->payload([1 => [$own, $foreign]]))->execute();
            $this->fail('a file from another company must be rejected');
        } catch (ModelNotFoundException) {
            $this->assertSame(
                [],
                $this->attachedFieldNames($lead),
                'the valid file must not be attached when another one is rejected'
            );
        }
    }

    public function testRejectsADeletedFile(): void
    {
        $lead = $this->makeLead();
        $file = $this->createFilesystemRecord('gone.pdf');
        $file->is_deleted = StateEnums::YES->getValue();
        $file->saveOrFail();

        $this->expectException(ValidationException::class);

        new AttachUploadedFilesToLeadAction($lead, $this->payload([1 => [$file]]))->execute();
    }

    public function testRetryingTheSameSubmissionChangesNothing(): void
    {
        $lead = $this->makeLead();
        $data = $this->payload([
            1 => [$this->createFilesystemRecord('paystub.pdf'), $this->createFilesystemRecord('w2.pdf')],
        ]);

        new AttachUploadedFilesToLeadAction($lead, $data)->execute();
        $before = $this->attachedFieldNames($lead);

        $second = new AttachUploadedFilesToLeadAction($lead, $data)->execute();

        $this->assertCount(0, $second);
        $this->assertSame($before, $this->attachedFieldNames($lead));
    }

    public function testADifferentFileWithTheSameNameGetsTheNextVersion(): void
    {
        $lead = $this->makeLead();

        foreach (['scan.pdf', 'scan.pdf', 'SCAN.pdf'] as $name) {
            $data = $this->payload([1 => [$this->createFilesystemRecord($name)]]);

            new AttachUploadedFilesToLeadAction($lead, $data)->execute();
        }

        $this->assertEqualsCanonicalizing(
            ['scan.pdf', 'scan (2).pdf', 'SCAN (3).pdf'],
            $this->attachedFieldNames($lead),
            'a same-name file must be added as a new version, not replace the previous one'
        );
    }

    public function testTwoSameNameFilesInOneSubmissionAreBothKept(): void
    {
        $lead = $this->makeLead();

        new AttachUploadedFilesToLeadAction($lead, $this->payload([
            1 => [$this->createFilesystemRecord('scan.pdf'), $this->createFilesystemRecord('scan.pdf')],
        ]))->execute();

        $this->assertEqualsCanonicalizing(['scan.pdf', 'scan (2).pdf'], $this->attachedFieldNames($lead));
    }

    public function testKeepsTheFieldNameWithinTheColumnLength(): void
    {
        $lead = $this->makeLead();
        $name = str_repeat('a', 80) . '.pdf';

        $data = $this->payload([
            12 => [$this->createFilesystemRecord($name), $this->createFilesystemRecord($name)],
        ]);

        new AttachUploadedFilesToLeadAction($lead, $data)->execute();

        $names = $this->attachedFieldNames($lead);
        $this->assertCount(2, $names);

        foreach ($names as $fieldName) {
            $this->assertLessThanOrEqual(50, mb_strlen($fieldName));
            $this->assertStringEndsWith('.pdf', $fieldName);
        }
    }

    public function testAFailureHalfWayLeavesNoFilesAttached(): void
    {
        $lead = $this->makeLead();
        $data = $this->payload([
            1 => [$this->createFilesystemRecord('first.pdf'), $this->createFilesystemRecord('second.pdf')],
        ]);

        $action = new class ($lead, $data) extends AttachUploadedFilesToLeadAction {
            private int $calls = 0;

            protected function uniqueFieldName(string $fileName, array $usedNames): string
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('boom');
                }

                return parent::uniqueFieldName($fileName, $usedNames);
            }
        };

        try {
            $action->execute();
            $this->fail('the second attachment should have failed');
        } catch (RuntimeException) {
            $this->assertSame(
                [],
                $this->attachedFieldNames($lead),
                'the first attachment must be rolled back'
            );
        }
    }

    private function makeLead(): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }

    /**
     * @param array<int, Filesystem[]> $documents document type id => files
     *
     * @return array<int, array<string, mixed>>
     */
    private function payload(array $documents, bool $byId = false): array
    {
        $data = [];

        foreach ($documents as $typeId => $files) {
            $data[$typeId] = [
                'type' => ['id' => $typeId, 'name' => 'Document ' . $typeId],
                'files' => array_map(
                    fn (Filesystem $file): array => $byId
                        ? ['id' => (string) $file->getId(), 'name' => $file->name]
                        : ['uuid' => $file->uuid, 'name' => $file->name],
                    $files
                ),
            ];
        }

        return $data;
    }
}
