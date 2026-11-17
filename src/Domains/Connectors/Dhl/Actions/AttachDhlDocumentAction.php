<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Actions;

use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Souk\Orders\Models\Order;

class AttachDhlDocumentAction
{
    public function execute(
        Order $order,
        string $base64Content,
        string $fileName,
        string $field
    ): void {
        $file = new FilesystemServices($order->app, $order->company)->createFileSystemFromBase64(
            $base64Content,
            $fileName,
            $order->company->user
        );

        $order->addFile($file, $field);
    }
}
