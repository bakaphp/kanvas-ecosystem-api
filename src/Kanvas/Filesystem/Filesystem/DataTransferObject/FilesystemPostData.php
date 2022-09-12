<?php

declare(strict_types=1);


namespace Kanvas\Filesystem\Filesystem\DataTransferObject;

use Spatie\DataTransferObject\DataTransferObject;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * AppsData class
 */
class FilesystemPostData extends DataTransferObject
{
    /**
     * Construct function
     *
     * @property string $name
     */
    public function __construct(
        public UploadedFile $file,
    ) {
    }

    /**
     * Create new instance of DTO from request
     *
     * @param Request $request Request Input data
     *
     * @return self
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            file: $request->file,
        );
    }

    /**
     * Create new instance of DTO from array of data
     *
     * @param array $data Input data
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            file: $data['file'],
        );
    }
}
