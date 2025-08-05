<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Souk\Orders\Models\Order;
use Knp\Snappy\Pdf;

class GeneratePdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Order $entity,
        public string $html,
        public string $filename,
        public array $options = []
    ) {
    }

    public function handle(): array
    {
        try {
            $pdfFile = PdfService::generatePdfFromTemplate(
                $entity->app,
                $entity->user,
                $html,
                $entity,
                $pdfData
            );

            return [
                'status' => 'success',
                'download_url' => $pdfFile->url,
                'file_name' => "{$this->filename}.pdf",
                'file_path' => $pdfFile->path,
                'message' => 'PDF export completed successfully'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'download_url' => null,
                'file_name' => null,
                'file_path' => null,
                'message' => 'PDF generation failed: ' . $e->getMessage()
            ];
        }
    }
    public function handleOld(): array
    {
        try {
            // Find wkhtmltopdf binary
            $binaryPath = config('snappy.pdf.binary') ?? $this->findWkhtmltopdfBinary();
            
            // Create PDF using Knp\Snappy\Pdf
            $pdf = new Pdf($binaryPath);
            $pdf->setOptions(array_merge([
                'page-size' => 'A4',
                'orientation' => 'landscape',
                'margin-top' => 10,
                'margin-right' => 10,
                'margin-bottom' => 10,
                'margin-left' => 10,
                'encoding' => 'UTF-8',
                'enable-local-file-access' => true
            ], $this->options));

            $pdfContent = $pdf->getOutputFromHtml($this->html);

            $filePath = "exports/{$this->filename}.pdf";
            Storage::disk('public')->put($filePath, $pdfContent);

            return [
                'status' => 'success',
                'download_url' => Storage::disk('public')->url($filePath),
                'file_name' => "{$this->filename}.pdf",
                'file_path' => $filePath,
                'message' => 'PDF export completed successfully'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'download_url' => null,
                'file_name' => null,
                'file_path' => null,
                'message' => 'PDF generation failed: ' . $e->getMessage()
            ];
        }
    }

    private function findWkhtmltopdfBinary(): string
    {
        $commonPaths = [
            '/usr/local/bin/wkhtmltopdf',
            '/usr/bin/wkhtmltopdf',
            '/bin/wkhtmltopdf',
            'wkhtmltopdf' // Let system find it in PATH
        ];

        foreach ($commonPaths as $path) {
            if ($path === 'wkhtmltopdf' || file_exists($path)) {
                return $path;
            }
        }

        throw new \Exception('wkhtmltopdf binary not found. Please install wkhtmltopdf or set the binary path in config.');
    }
}