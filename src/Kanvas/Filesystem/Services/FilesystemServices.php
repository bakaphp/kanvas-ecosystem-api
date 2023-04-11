<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Actions\CreateFilesystemAction;
use Kanvas\Filesystem\Models\Filesystem as ModelsFilesystem;
use Kanvas\Users\Models\Users;

class FilesystemServices
{
    protected Filesystem $storage;
    /**
     * Construct function.
     */
    public function __construct(
        protected Apps $app
    ) {
        $this->storage = $this->getStorageByDisk();
    }

    /**
     * Upload a file using filesystem.
     *
     * @param UploadedFile $file
     * @param Users $user
     * @return ModelsFilesystem
     */
    public function upload(UploadedFile $file, Users $user): ModelsFilesystem
    {
        $uploadedFile = $this->storage->put('/public', $file);

        $createFileSystem = new CreateFilesystemAction($file, $user);

        return $createFileSystem->execute(
            $this->storage->url($uploadedFile),
            $this->storage->path($uploadedFile)
        );
    }

    /**
     * Build an on-demand Storage client based on the config filesystem service
     * of the current app.
     *
     * @return Filesystem
     */
    public function getStorageByDisk(): Filesystem
    {
        return match ($this->app->get('filesystem-service')) {
            'gcs' => $this->buildGoogleCloudStorage(),
            's3' => $this->buildS3Storage(),
        };
    }

    /**
     * Build an on-demand google cloud storage using the (must) already saved service account-file.
     *
     * @return Filesystem
     */
    public function buildGoogleCloudStorage(): Filesystem
    {
        return Storage::build([
            'driver' => 'gcs',
            'key_file' => $this->app->get('service-account-file'), // optional: Array of data that substitutes the .json file (see below)
            'bucket' => $this->app->get('cloud-bucket'),
            'storage_api_uri' => null, // see: Public URLs below
            'apiEndpoint' => null, // set storageClient apiEndpoint
            'visibility' => 'public', // optional: public|private
            'visibility_handler' => \League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility::class, // optional: set to \League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility::class to enable uniform bucket level access
            'metadata' => ['cacheControl'=> 'public,max-age=86400'], // optional: default metadata
        ]);
    }

    /**
     * Build an on-demand aws s3 storage using the (must) already saved service-account-file
     *
     * @return Filesystem
     */
    public function buildS3Storage(): Filesystem
    {
        $aws = $this->app->get('service-account-file');

        return Storage::build([
            'driver' => 's3',
            'key' => $aws['key'],
            'secret' => $aws['secret'],
            'region' => $aws['region'],
            'bucket' => $this->app->get('cloud-bucket'),
        ]);
    }

    /**
     * Delete a file from the filesystem cloud service.
     *
     * @param ModelsFilesystem $file
     * @return boolean
     */
    public function delete(ModelsFilesystem $file): bool
    {
        return $this->storage->delete($file->path);
    }
}
