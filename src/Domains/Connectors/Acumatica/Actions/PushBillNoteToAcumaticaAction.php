<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Client;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Kanvas\Connectors\Acumatica\Traits\HasAcumaticaWriter;
use Kanvas\Scribe\Bills\Models\Bill;

/** Appends a note to an already-pushed AP bill's Notes field in Acumatica. */
class PushBillNoteToAcumaticaAction
{
    use HasAcumaticaWriter;

    /** The bill's own app — the tenant whose Acumatica config/credentials this push runs against. */
    protected Apps $app;

    public function __construct(
        protected Bill $bill,
        ?AcumaticaWriteService $writer = null,
    ) {
        $this->app = $bill->app;
        $this->writer = $writer;
    }

    /**
     * @return string the full note text now stored in Acumatica
     */
    public function execute(string $note): string
    {
        $billGuid = (string) $this->bill->get(CustomFieldEnum::BILL_ID->value, '');

        if ($billGuid === '') {
            throw new AcumaticaWriteException(
                "Bill {$this->bill->getId()} has no Acumatica reference — it must be pushed before adding a note."
            );
        }

        return $this->writer()->withSession(
            function (Client $client) use ($billGuid, $note): string {
                $existing = (string) AcumaticaPayload::value($client->get('Bill/' . $billGuid), 'note', '');
                $combined = $existing !== '' ? $existing . "\n" . $note : $note;

                $client->put('Bill', ['id' => $billGuid, 'note' => ['value' => $combined]]);

                return $combined;
            }
        );
    }
}
