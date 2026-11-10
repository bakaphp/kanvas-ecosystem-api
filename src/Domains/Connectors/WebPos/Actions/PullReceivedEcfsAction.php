<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WebPos\Client;
use Kanvas\Connectors\WebPos\Enums\HostEnum;
use Kanvas\Connectors\WebPos\Support\ResponseField;
use Kanvas\Scribe\Bills\Actions\CreateBillFromReceivedEcfAction;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfProviderEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\ReceivedEcfXmlParserService;
use Kanvas\Users\Models\Users;
use RuntimeException;
use Throwable;

/**
 * Vendors' e-CFs from WebPOS's reception API → draft bills.
 *
 * A document is marked downloaded only after its bill is saved: WebPOS stops returning what is marked, so
 * marking first and failing the bill would lose the document for good. A page is acknowledged
 * (`updRecDocPaging`) only when every document on it landed; otherwise the pull stops and the next run
 * picks up the same page. Re-pulling is free, the bill is idempotent on vendor RNC + e-NCF.
 *
 * WebPOS documents no reception route publicly. The ApiKey forms here follow `getRecDoc`, the one seen answering
 * on the test host: `/ak/` before the version, the key right after the license.
 */
class PullReceivedEcfsAction
{
    // WebPOS refuses a getRecDoc window wider than 31 days.
    public const int MAX_WINDOW_DAYS = 31;
    private const int MAX_PAGES = 50;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly Users $user,
        public readonly int $lookbackDays = 7,
        private ?Client $client = null,
    ) {
    }

    /**
     * @return array{created: int, failed: int, pages: int}
     */
    public function execute(): array
    {
        $client = $this->client ??= new Client($this->company);
        $parser = new ReceivedEcfXmlParserService();

        $to = Carbon::today();
        $from = $to->copy()->subDays(max(0, min($this->lookbackDays, self::MAX_WINDOW_DAYS - 1)));
        $path = sprintf(
            'api/fedo/ak/v1/getRecDoc/%s/%s/%s/%s',
            $client->companyLicense,
            Client::API_KEY,
            $from->toDateString(),
            $to->toDateString()
        );

        $result = ['created' => 0, 'failed' => 0, 'pages' => 0];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $client->get(HostEnum::RECEPTION, $path) ?? [];
            $documents = $this->documents($response);

            if ($documents === []) {
                break;
            }

            $result['pages']++;
            $pageFailed = 0;

            foreach ($documents as $document) {
                if ($this->ingest($client, $parser, $document)) {
                    $result['created']++;
                } else {
                    $pageFailed++;
                }
            }

            $result['failed'] += $pageFailed;
            $pagingId = ResponseField::string($response, 'pagingId', 'PagingId');

            if ($pageFailed > 0 || $pagingId === null) {
                break;
            }

            $client->post(
                HostEnum::RECEPTION,
                sprintf('api/fedo/ak/v1/updRecDocPaging/%s/%s/%s', $client->companyLicense, Client::API_KEY, rawurlencode($pagingId))
            );
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $document
     */
    private function ingest(Client $client, ReceivedEcfXmlParserService $parser, array $document): bool
    {
        $vendorRnc = ResponseField::string(
            $document,
            'vendorRNC',
            'vendorRnc',
            'rncEmisor',
            'RNCEmisor'
        );
        $encf = ResponseField::string(
            $document,
            'eCF',
            'ecf',
            'encf',
            'eNCF'
        );

        if ($vendorRnc === null || $encf === null) {
            report(new RuntimeException('WebPOS getRecDoc returned a document without vendor RNC or e-NCF.'));

            return false;
        }

        $docPath = sprintf(
            '%s/%s/%s/%s',
            $client->companyLicense,
            Client::API_KEY,
            rawurlencode($vendorRnc),
            rawurlencode($encf)
        );

        try {
            $xmlResponse = $client->get(HostEnum::RECEPTION, 'api/fedo/ak/v1/getRecXml/' . $docPath) ?? [];
            $xml = base64_decode((string) ResponseField::string(
                $xmlResponse,
                'xml',
                'xmlB64',
                'xmlFe',
                'content'
            ), true);

            if ($xml === false || $xml === '') {
                throw new RuntimeException("WebPOS returned no XML for received e-CF {$encf} from {$vendorRnc}.");
            }

            new CreateBillFromReceivedEcfAction(
                app: $this->app,
                company: $this->company,
                user: $this->user,
                received: $parser->parse(
                    $xml,
                    EcfProviderEnum::WEBPOS,
                    ResponseField::string($xmlResponse, 'qrContent')
                ),
            )->execute();

            $client->post(HostEnum::RECEPTION, 'api/fedo/ak/v1/updRecDocDownload/' . $docPath);

            return true;
        } catch (Throwable $e) {
            // One unreadable vendor document must not hold back every other vendor's bills.
            report($e);

            return false;
        }
    }

    /**
     * @param array<array-key, mixed> $response
     * @return list<array<array-key, mixed>>
     */
    private function documents(array $response): array
    {
        $list = array_is_list($response)
            ? $response
            : ($response['docs'] ?? $response['documents'] ?? $response['data'] ?? $response['items'] ?? []);

        return array_values(array_filter((array) $list, 'is_array'));
    }
}
