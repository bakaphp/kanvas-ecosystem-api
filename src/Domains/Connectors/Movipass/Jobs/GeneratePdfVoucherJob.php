<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Jobs;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Souk\Orders\Models\Order;

class GeneratePdfVoucherJob implements ShouldQueue
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

        $this->entity->addFile($pdfFile, $this->filename);
        $this->entity->set("voucher_url", $pdfFile->url);

        activity()
        ->causedBy($this->user)
        ->performedOn($this->entity)
        ->withProperties([
            'order_id' => $this->entity->id,
            'order_number' => $this->entity->order_number,
            'user_id' => $this->user->id,
            'timestamp' => now(),
            'file_id' => $pdfFile->id,
            'file_url' =>  $pdfFile->url,
            'file_path' => $pdfFile->path
        ])
        ->log('COMPROBANTE_DESPACHO_GENERADO');
    }
}
