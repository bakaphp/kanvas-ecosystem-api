<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Kanvas\ActionEngine\Engagements\Actions\AttachGetDocsFilesToMessageAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

final class AttachGetDocsFilesToMessageActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'ecosystem'];

    public function testAttachesEveryPayloadFileToTheMessage(): void
    {
        $income = $this->uploadFile('paystub.png');
        $incomeSecond = $this->uploadFile('w2.png');
        $taxes = $this->uploadFile('1040.png');

        $message = $this->makeGetDocsMessage([
            1 => ['name' => 'Income documents', 'files' => [$income, $incomeSecond]],
            2 => ['name' => 'Personal tax returns', 'files' => [$taxes]],
        ]);

        $attached = new AttachGetDocsFilesToMessageAction($message)->execute();

        $this->assertCount(3, $attached);
        $this->assertEqualsCanonicalizing(
            [$income->getId(), $incomeSecond->getId(), $taxes->getId()],
            $message->files()->pluck('filesystem.id')->all(),
            'two files of the same document type must not replace each other'
        );
        $this->assertNotNull($message->getFileByName('income_documents_' . $income->getId()));
    }

    public function testIgnoresFilesFromAnotherCompany(): void
    {
        $this->markTestSkipped('Pending the docs frontend change.');

        $foreign = $this->uploadFile('foreign.png');
        $foreign->companies_id = $foreign->companies_id + 100000;
        $foreign->users_id = auth()->user()->getId() + 100000;
        $foreign->saveOrFail();

        $message = $this->makeGetDocsMessage([
            1 => ['name' => 'Income documents', 'files' => [$foreign]],
        ]);

        $attached = new AttachGetDocsFilesToMessageAction($message)->execute();

        $this->assertCount(0, $attached);
        $this->assertSame(0, $message->files()->count());
    }

    public function testAttachesFilesTheMessageUserUploadedUnderAnotherCompany(): void
    {
        $upload = $this->uploadFile('paystub.png');
        $upload->companies_id = $upload->companies_id + 100000;
        $upload->saveOrFail();

        $message = $this->makeGetDocsMessage([
            1 => ['name' => 'Income documents', 'files' => [$upload]],
        ]);

        $attached = new AttachGetDocsFilesToMessageAction($message)->execute();

        $this->assertCount(1, $attached, 'an upload is stamped with the uploader\'s current company, not the lead\'s');
    }

    public function testReturnsEmptyWhenThePayloadHasNoFiles(): void
    {
        $message = $this->makeGetDocsMessage([]);

        $this->assertCount(0, new AttachGetDocsFilesToMessageAction($message)->execute());
    }

    private function uploadFile(string $name): Filesystem
    {
        return new FilesystemServices(app(Apps::class))->upload(
            UploadedFile::fake()->image($name),
            auth()->user()
        );
    }

    /**
     * @param array<int, array{name: string, files: Filesystem[]}> $documents
     */
    private function makeGetDocsMessage(array $documents): Message
    {
        $data = [];

        foreach ($documents as $typeId => $document) {
            $data[$typeId] = [
                'type' => ['id' => $typeId, 'name' => $document['name']],
                'files' => array_map(
                    fn (Filesystem $file) => [
                        'id' => (string) $file->getId(),
                        'name' => $file->name,
                        'url' => $file->url,
                    ],
                    $document['files']
                ),
            ];
        }

        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create([
                'users_id' => auth()->user()->getId(),
                'message' => [
                    'data' => $data,
                    'text' => 'Get Docs',
                    'verb' => 'get-docs',
                    'status' => 'submitted',
                ],
            ]);
    }
}
