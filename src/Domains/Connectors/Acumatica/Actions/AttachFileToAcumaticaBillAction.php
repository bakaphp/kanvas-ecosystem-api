<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Client;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Kanvas\Connectors\Acumatica\Traits\HasAcumaticaWriter;
use Kanvas\Scribe\Bills\Models\Bill;

/** Attaches a file to an already-pushed AP bill in Acumatica via its own files:put link. */
class AttachFileToAcumaticaBillAction
{
    use HasAcumaticaWriter;

    /** The bill's own app — the tenant whose Acumatica config/credentials this push runs against. */
    protected Apps $app;

    public function __construct(
        protected Bill $bill,
        protected string $fileUrl,
        protected string $fileName,
        ?AcumaticaWriteService $writer = null,
    ) {
        $this->app = $bill->app;
        $this->writer = $writer;
    }

    public function execute(): void
    {
        $billGuid = (string) $this->bill->get(CustomFieldEnum::BILL_ID->value, '');

        if ($billGuid === '') {
            throw new AcumaticaWriteException(
                "Bill {$this->bill->getId()} has no Acumatica reference — it must be pushed before attaching a file."
            );
        }

        $this->writer()->withSession(function (Client $client) use ($billGuid): void {
            $template = AcumaticaPayload::filesPutHref($client->get('Bill/' . $billGuid));

            if ($template === null) {
                throw new AcumaticaWriteException(
                    "Bill {$this->bill->getId()} has no files:put link in Acumatica — cannot attach a file."
                );
            }

            $bytes = SafeUrlFetcher::fetch($this->fileUrl);
            $url = str_ireplace('{filename}', rawurlencode($this->fileName), $template);

            $client->putFile($url, $bytes, $this->contentType());
        });
    }

    private function contentType(): string
    {
        return match (strtolower(pathinfo($this->fileName, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}
