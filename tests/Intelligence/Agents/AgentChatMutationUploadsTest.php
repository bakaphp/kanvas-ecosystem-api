<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use App\GraphQL\Intelligence\Mutations\AgentChatMutation;
use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Kanvas\Filesystem\Enums\AllowedFileExtensionEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\TestCase;

/**
 * Covers {@see AgentChatMutation::mergeUploadsWithUrls()} — the resolver helper
 * that turns multipart `uploads: [Upload!]` into classified images/files URL lists
 * and merges them with the client-supplied `images` / `files` arrays.
 *
 * Stubs {@see HasMutationUploadFiles::uploadFilesAndCollect} so no real Filesystem
 * write happens. The real upload path is exercised in the end-to-end test
 * {@see Tests\GraphQL\Intelligence\UserAgentChatUploadsTest}.
 */
class AgentChatMutationUploadsTest extends TestCase
{
    public function testImageUploadLandsInTheImagesList(): void
    {
        $stub = new MergeUploadsAgentChatMutationStub([
            $this->fakeFilesystem(file_type: 'image/png', url: 'https://cdn.test/photo.png'),
        ]);

        [$images, $files] = $stub->exposeMergeUploadsWithUrls(
            ['uploads' => [UploadedFile::fake()->create('photo.png')]],
            new Agent(),
        );

        $this->assertSame(['https://cdn.test/photo.png'], $images);
        $this->assertSame([], $files);
    }

    public function testNonImageUploadLandsInTheFilesList(): void
    {
        $stub = new MergeUploadsAgentChatMutationStub([
            $this->fakeFilesystem(file_type: 'application/pdf', url: 'https://cdn.test/doc.pdf', name: 'doc.pdf'),
        ]);

        [$images, $files] = $stub->exposeMergeUploadsWithUrls(
            ['uploads' => [UploadedFile::fake()->create('doc.pdf')]],
            new Agent(),
        );

        $this->assertSame([], $images);
        $this->assertSame(['https://cdn.test/doc.pdf'], $files);
    }

    public function testMixedUploadsAreSplitByMediaType(): void
    {
        $stub = new MergeUploadsAgentChatMutationStub([
            $this->fakeFilesystem(file_type: 'image/jpeg', url: 'https://cdn.test/a.jpg'),
            $this->fakeFilesystem(file_type: 'application/pdf', url: 'https://cdn.test/b.pdf', name: 'b.pdf'),
        ]);

        [$images, $files] = $stub->exposeMergeUploadsWithUrls(
            ['uploads' => [
                UploadedFile::fake()->create('a.jpg'),
                UploadedFile::fake()->create('b.pdf'),
            ]],
            new Agent(),
        );

        $this->assertSame(['https://cdn.test/a.jpg'], $images);
        $this->assertSame(['https://cdn.test/b.pdf'], $files);
    }

    public function testClientSuppliedImageAndFileUrlsAreMergedWithUploads(): void
    {
        $stub = new MergeUploadsAgentChatMutationStub([
            $this->fakeFilesystem(file_type: 'image/png', url: 'https://cdn.test/uploaded.png'),
        ]);

        [$images, $files] = $stub->exposeMergeUploadsWithUrls(
            [
                'images' => ['https://cdn.client/already.png'],
                'files' => ['https://cdn.client/already.pdf'],
                'uploads' => [UploadedFile::fake()->create('uploaded.png')],
            ],
            new Agent(),
        );

        $this->assertSame(
            ['https://cdn.client/already.png', 'https://cdn.test/uploaded.png'],
            $images,
        );
        $this->assertSame(['https://cdn.client/already.pdf'], $files);
    }

    public function testNonUploadedFileEntriesAreFilteredOut(): void
    {
        $stub = new MergeUploadsAgentChatMutationStub([]);
        $stub->failIfCollectorRuns();

        [$images, $files] = $stub->exposeMergeUploadsWithUrls(
            ['uploads' => ['just a string', 42, null]],
            new Agent(),
        );

        $this->assertSame([], $images);
        $this->assertSame([], $files);
    }

    public function testNoAttachTargetSkipsUploadEntirely(): void
    {
        $stub = new MergeUploadsAgentChatMutationStub([]);
        $stub->failIfCollectorRuns();

        [$images, $files] = $stub->exposeMergeUploadsWithUrls(
            [
                'images' => ['https://cdn.client/keep.png'],
                'files' => ['https://cdn.client/keep.pdf'],
                'uploads' => [UploadedFile::fake()->create('orphan.png')],
            ],
            null,
        );

        $this->assertSame(['https://cdn.client/keep.png'], $images);
        $this->assertSame(['https://cdn.client/keep.pdf'], $files);
    }

    public function testMissingUploadsKeyIsTreatedAsEmpty(): void
    {
        $stub = new MergeUploadsAgentChatMutationStub([]);
        $stub->failIfCollectorRuns();

        [$images, $files] = $stub->exposeMergeUploadsWithUrls([], new Agent());

        $this->assertSame([], $images);
        $this->assertSame([], $files);
    }

    private function fakeFilesystem(string $file_type, string $url, string $name = ''): Filesystem
    {
        $fs = new Filesystem();
        $fs->file_type = $file_type;
        $fs->url = $url;
        $fs->name = $name === '' ? basename(parse_url($url, PHP_URL_PATH) ?: 'file') : $name;

        return $fs;
    }
}

class MergeUploadsAgentChatMutationStub extends AgentChatMutation
{
    private bool $collectorMustNotRun = false;

    /**
     * @param list<Filesystem> $filesystemReturn
     */
    public function __construct(private array $filesystemReturn)
    {
    }

    public function failIfCollectorRuns(): void
    {
        $this->collectorMustNotRun = true;
    }

    public function exposeMergeUploadsWithUrls(array $input, ?Model $attachTo): array
    {
        $user = $this->fakeUser();

        return $this->mergeUploadsWithUrls($input, $user, app(\Kanvas\Apps\Models\Apps::class), $attachTo);
    }

    public function uploadFilesAndCollect(
        Model $model,
        AppInterface $app,
        UserInterface $user,
        array $files,
        AllowedFileExtensionEnum $allowed = AllowedFileExtensionEnum::WORK_FILES,
    ): array {
        if ($this->collectorMustNotRun) {
            throw new \LogicException('uploadFilesAndCollect was called but the test expected it to be skipped');
        }

        return $this->filesystemReturn;
    }

    private function fakeUser(): \Kanvas\Users\Models\Users
    {
        return auth()->user();
    }
}
