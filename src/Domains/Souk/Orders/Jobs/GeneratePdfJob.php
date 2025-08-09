<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Jobs;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Kanvas\Connectors\Movipass\Notifications\OrdersExportNotification;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Souk\Orders\Models\Order;

class GeneratePdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public array $result = [];

    public function __construct(
        public Order $entity,
        public UserInterface $user,
        public string $html = '',
        public string $filename = '',
        public array $data = [],
    ) {
    }

    public function handle(): void
    {
        $pdfFile = PdfService::generatePdfFromTemplate(
            $this->entity->app,
            $this->user,
            $this->html,
            $this->entity,
            $this->data,
            []
        );

        $this->sendEmail($pdfFile->url ?? "N/D");
    }


    private function sendEmail(string $path)
    {
        $notification = new OrdersExportNotification(
            $this->entity,
            [
                "downloadUrl" => $path,
                "appName" => $this->entity->app->name

            ],
        );
        Notification::route('mail', $this->user->email)->notify($notification);
    }
}
