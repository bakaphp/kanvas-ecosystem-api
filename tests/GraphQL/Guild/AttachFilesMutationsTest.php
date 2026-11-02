<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use Tests\Traits\MakesGuildEntities;

/**
 * The singular and plural attach mutations share one resolver per entity; this walks the real
 * multipart request for each shape. Skipped under paratest like every multipart test here —
 * HasMutationUploadFilesTest covers the same normalisation without HTTP so CI still guards it.
 */
final class AttachFilesMutationsTest extends TestCase
{
    use MakesGuildEntities;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('TEST_TOKEN') !== false) {
            $this->markTestSkipped('multipartGraphQL uploads hang under paratest');
        }
    }

    public function testAttachFilesToLeadTakesAListUnderFile(): void
    {
        $lead = $this->makeLead();

        $this->attach(
            'mutation ($file: [Upload!]!, $id: ID!) { attachFilesToLead(file: $file, id: $id) { id } }',
            ['file' => [null, null], 'id' => $lead->getId()],
            ['0' => ['variables.file.0'], '1' => ['variables.file.1']],
            ['application.pdf', 'bank-statement.pdf']
        );

        $this->assertSame(['application.pdf', 'bank-statement.pdf'], $this->attachedFileNames($lead));
    }

    public function testAttachFileToLeadTakesASingleUpload(): void
    {
        $lead = $this->makeLead();

        $this->attach(
            'mutation ($file: Upload!, $id: ID!) { attachFileToLead(file: $file, id: $id) { id } }',
            ['file' => null, 'id' => $lead->getId()],
            ['0' => ['variables.file']],
            ['application.pdf']
        );

        $this->assertSame(['application.pdf'], $this->attachedFileNames($lead));
    }

    public function testAttachFilesToPeopleTakesAListUnderFiles(): void
    {
        $people = $this->makePeople();

        $this->attach(
            'mutation ($files: [Upload!]!, $id: ID!) { attachFilesToPeople(files: $files, id: $id) { id } }',
            ['files' => [null, null], 'id' => $people->getId()],
            ['0' => ['variables.files.0'], '1' => ['variables.files.1']],
            ['passport.pdf', 'selfie.jpg']
        );

        $this->assertSame(['passport.pdf', 'selfie.jpg'], $this->attachedFileNames($people));
    }

    public function testAttachFileToPeopleTakesASingleUpload(): void
    {
        $people = $this->makePeople();

        $this->attach(
            'mutation ($file: Upload!, $id: ID!) { attachFileToPeople(file: $file, id: $id) { id } }',
            ['file' => null, 'id' => $people->getId()],
            ['0' => ['variables.file']],
            ['selfie.jpg']
        );

        $this->assertSame(['selfie.jpg'], $this->attachedFileNames($people));
    }

    /**
     * @param array<string, list<string>> $map
     * @param list<string> $fileNames
     */
    private function attach(
        string $mutation,
        array $variables,
        array $map,
        array $fileNames
    ): void {
        $files = [];

        foreach (array_values($fileNames) as $index => $name) {
            $files[(string) $index] = UploadedFile::fake()->create($name, 10);
        }

        $this->multipartGraphQL(['query' => $mutation, 'variables' => $variables], $map, $files)
            ->assertSuccessful()
            ->assertJsonMissing(['errors']);
    }
}
