<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Mutations\FileSystem;

use Illuminate\Support\Facades\Storage;

final class Upload
{
    /**
     * Upload a file, store it on the server and return the path.
     *
     * @param  mixed  $root
     * @param  array<string, mixed>  $args
     *
     * @return string|null
     */
    public function __invoke($_, array $args) : ?string
    {
        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $args['file'];

        $imageName = time() . $file->getClientOriginalName();
        $filePath = 'images-stage/' . $imageName;
        try {
            // $file->storePublicly($filePath, 's3');
            Storage::disk('s3')->put($filePath, $file->get());
        } catch (\League\Flysystem\UnableToWriteFile $e) {
            throw new \Exception($e->getMessage());
        }
        return Storage::disk('s3')->url($filePath);
    }
}
