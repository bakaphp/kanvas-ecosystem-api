<?php

declare(strict_types=1);

namespace Baka\Helpers;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class GenerateQrCode
{
    public function __construct(
        protected readonly string $content,
        protected readonly int $size = 300,
    ) {
    }

    public function execute(): string
    {
        return (new Writer(
            new ImageRenderer(
                new RendererStyle($this->size),
                new ImagickImageBackEnd()
            )
        ))->writeString($this->content);
    }

    public function asBase64(): string
    {
        return 'data:image/png;base64,' . base64_encode($this->execute());
    }
}
