<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Jobs;

use Baka\Users\Contracts\UserInterface;
use Exception;
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

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = [10, 30, 60]; // Wait 10s, then 30s, then 60s between retries

    /**
     * The maximum number of seconds the job can run.
     */
    public $timeout = 120;

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
        $attemptNumber = $this->attempts();

        activity()
        ->causedBy($this->user)
        ->performedOn($this->entity)
        ->withProperties([
            'order_id' => $this->entity->id,
            'order_number' => $this->entity->order_number,
            'user_id' => $this->user->id,
            'filename' => $this->filename,
            'attempt' => $attemptNumber,
            'timestamp' => now(),
        ])
        ->log('GENERATING_PDF_VOUCHER');

        try {
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

            if ($attemptNumber < 2) {
                throw new Exception('Simulated failure to test retries');
            }

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
        } catch (Exception $e) {
            report($e);

            $willRetry = $attemptNumber < $this->tries;

            activity()
            ->causedBy($this->user)
            ->performedOn($this->entity)
            ->withProperties([
                'order_id' => $this->entity->id,
                'order_number' => $this->entity->order_number,
                'user_id' => $this->user->id,
                'attempt' => $attemptNumber,
                'will_retry' => $willRetry,
                'timestamp' => now(),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ])
            ->log($willRetry ? 'ERROR_GENERATING_PDF_VOUCHER_RETRYING' : 'ERROR_GENERATING_PDF_VOUCHER_FAILED');
            throw $e;
        }
    }

    /**
     * Handle a job failure (after all retries exhausted).
     */
    public function failed(?Exception $exception): void
    {
        activity()
            ->causedBy($this->user)
            ->performedOn($this->entity)
            ->withProperties([
                'order_id' => $this->entity->id,
                'order_number' => $this->entity->order_number,
                'user_id' => $this->user->id,
                'total_attempts' => $this->attempts(),
                'error_message' => $exception?->getMessage(),
                'timestamp' => now(),
            ])
            ->log('PDF_VOUCHER_GENERATION_PERMANENTLY_FAILED');
    }
}
