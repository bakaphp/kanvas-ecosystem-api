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


        $imageName=time().$file->getClientOriginalName();
        $filePath = 'ios/' . $imageName;
        //Storage::disk('s3')->put($filePath, $file->get());
        $file->storePublicly($filePath, 's3');
        return Storage::disk('s3')->url($filePath);
    }
}
