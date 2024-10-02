<?php

declare(strict_types=1);

namespace Baka\Support;

use Illuminate\Support\Facades\Storage;

class Image
{
    public static function downloadFileToLocalDisk(string $url, string $path): string
    {
        $file = file_get_contents($url);
        Storage::disk('local')->put($path, $file);
        
        return $path;
    }


}
