<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Filesystem;

use Illuminate\Support\Facades\Auth;
use Kanvas\Filesystem\Actions\UploadFileAction;
use Kanvas\Filesystem\Models\Filesystem;

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
    public function __invoke($_, array $args) : Filesystem
    {
        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $args['file'];

        $uploadFile = new UploadFileAction(Auth::user());

        return $uploadFile->execute($file);
    }
}
