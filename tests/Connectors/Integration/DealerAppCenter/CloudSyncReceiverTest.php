<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DealerAppCenter;

use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DealerAppCenter\Enums\ConfigurationEnum;
use Kanvas\Connectors\DealerAppCenter\Services\InventorySftpClient;
use Kanvas\Connectors\DealerAppCenter\Webhooks\ProcessDealerAppCenterCloudSyncWebhookJob;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Override;
use Tests\TestCase;

class CloudSyncReceiverTest extends TestCase
{
    private const string SHARED_KEY = 'test-shared-secret';
    private const string SHARED_ID = 'test-shared-id';

    private ReceiverWebhook $receiver;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->set(ConfigurationEnum::CLOUD_SYNC->value, [
            'key' => self::SHARED_KEY,
            'id' => self::SHARED_ID,
            'ftps' => [
                ['host' => 'ftp.example.com', 'username' => 'u', 'password' => 'p', 'port' => 22],
            ],
        ]);

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessDealerAppCenterCloudSyncWebhookJob::class],
            ['name' => 'ProcessDealerAppCenterCloudSyncWebhookJob'],
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
            ]);
    }

    public function testRejectsRequestWithMissingCredentials(): void
    {
        $result = $this->dispatchJob(
            files: ['aisj_inventory.csv' => $this->sampleCsv()],
            payload: ['rooftop_ids' => ['aisj']],
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('Unauthorized', $result['message']);
        $this->assertNull($result['data']);
    }

    public function testRejectsRequestWithWrongKey(): void
    {
        $result = $this->dispatchJob(
            files: ['aisj_inventory.csv' => $this->sampleCsv()],
            payload: [
                'key' => 'wrong',
                'id' => self::SHARED_ID,
                'rooftop_ids' => ['aisj'],
            ],
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('Unauthorized', $result['message']);
    }

    public function testRejectsRequestWithEmptyRooftopList(): void
    {
        $result = $this->dispatchJob(
            files: ['aisj_inventory.csv' => $this->sampleCsv()],
            payload: $this->authenticatedPayload([]),
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('No rooftop IDs provided', $result['message']);
    }

    public function testReturnsErrorWhenShortnameNotFoundOnAnyFtp(): void
    {
        $result = $this->dispatchJob(
            files: ['aisj_inventory.csv' => $this->sampleCsv()],
            payload: $this->authenticatedPayload(['ghost']),
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('No inventory file found for rooftop ghost', $result['message']);
    }

    public function testSingleRooftopSuccess(): void
    {
        $result = $this->dispatchJob(
            files: ['aisj_inventory.csv' => $this->sampleCsv()],
            payload: $this->authenticatedPayload(['aisj']),
        );

        $this->assertSame('success', $result['status']);
        $this->assertIsArray($result['data']);

        $data = $result['data'];
        $this->assertContains('VIN', $data['fileHeaders']);
        $this->assertContains('Rooftop ID', $data['fileHeaders']);

        // parity assertions from the doc's §5 curl/jq sanity check
        $this->assertCount(count($data['fileHeaders']), $data['fileFirstRow']);
        foreach ($data['fileValues'] as $row) {
            $this->assertCount(count($data['fileHeaders']), $row);
        }
        $this->assertSame($data['fileValues'][0], $data['fileFirstRow']);

        // every row carries the external rooftop id in the injected column
        $rooftopColumn = array_search('Rooftop ID', $data['fileHeaders'], true);
        foreach ($data['fileValues'] as $row) {
            $this->assertSame('aisj', $row[$rooftopColumn]);
        }
    }

    public function testFallsBackToBareShortnameCsv(): void
    {
        $result = $this->dispatchJob(
            files: ['caggm.csv' => $this->sampleCsv()],
            payload: $this->authenticatedPayload(['caggm']),
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('caggm', $result['data']['fileValues'][0][array_search('Rooftop ID', $result['data']['fileHeaders'], true)]);
    }

    public function testMultiRooftopUnionsHeadersAndStampsRooftopIds(): void
    {
        $aisjCsv = "VIN,Make,Model\nVIN-A,Ford,F-150\n";
        $kmtrCsv = "VIN,Make,Model,Year\nVIN-B,BMW,X5,2024\n";

        $result = $this->dispatchJob(
            files: [
                'aisj_inventory.csv' => $aisjCsv,
                'kmotors_inventory.csv' => $kmtrCsv,
            ],
            payload: $this->authenticatedPayload(['aisj', 'kmotors']),
        );

        $this->assertSame('success', $result['status']);
        $headers = $result['data']['fileHeaders'];

        // unioned: VIN, Make, Model (from aisj) then Year, Rooftop ID (appended)
        $this->assertSame(['VIN', 'Make', 'Model', 'Year', 'Rooftop ID'], $headers);

        $this->assertCount(2, $result['data']['fileValues']);
        $rooftopColumn = array_search('Rooftop ID', $headers, true);
        $yearColumn = array_search('Year', $headers, true);

        $this->assertSame('aisj', $result['data']['fileValues'][0][$rooftopColumn]);
        $this->assertSame('', $result['data']['fileValues'][0][$yearColumn]); // aisj row pads Year with ""
        $this->assertSame('kmotors', $result['data']['fileValues'][1][$rooftopColumn]);
        $this->assertSame('2024', $result['data']['fileValues'][1][$yearColumn]);
    }

    public function testRaggedRowsArePaddedWithEmptyString(): void
    {
        $csv = "VIN,Make,Model,Year\nVIN-A,Ford,F-150\n";

        $result = $this->dispatchJob(
            files: ['aisj_inventory.csv' => $csv],
            payload: $this->authenticatedPayload(['aisj']),
        );

        $this->assertSame('success', $result['status']);
        $headers = $result['data']['fileHeaders'];
        $row = $result['data']['fileValues'][0];

        $this->assertCount(count($headers), $row);
        $yearIdx = array_search('Year', $headers, true);
        $this->assertSame('', $row[$yearIdx]);
    }

    public function testPreservesCaseOfRooftopIdEchoButMatchesFilenameCaseInsensitively(): void
    {
        // Consumer sends uppercase; file on FTP is lowercase. We must:
        //   - resolve `aisj_inventory.csv` despite case difference (filename lookup)
        //   - echo "AISJ" verbatim in every row's Rooftop ID column (no normalization)
        // The consumer's `Rooftops::getByExternalId()` may be case-sensitive against
        // a DB row stored uppercase, so case normalization on our side would break it.
        $result = $this->dispatchJob(
            files: ['aisj_inventory.csv' => $this->sampleCsv()],
            payload: $this->authenticatedPayload(['AISJ']),
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('Inventory loaded for AISJ.', $result['message']);

        $rooftopColumn = array_search('Rooftop ID', $result['data']['fileHeaders'], true);
        foreach ($result['data']['fileValues'] as $row) {
            $this->assertSame('AISJ', $row[$rooftopColumn]);
        }
    }

    public function testReturnsErrorOnEmptyCsv(): void
    {
        $result = $this->dispatchJob(
            files: ['aisj_inventory.csv' => ''],
            payload: $this->authenticatedPayload(['aisj']),
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('Inventory file for aisj is empty or invalid', $result['message']);
    }

    private function sampleCsv(): string
    {
        return "VIN,Stock Number,Year,Make,Model,Price\n"
            . "1FTFW1E5XMFA12345,STK-001,2024,Ford,F-150,54995\n"
            . "WBAJA7C5XKWW12345,STK-002,2023,BMW,X5,62500\n";
    }

    /**
     * @param list<string> $rooftopIds
     *
     * @return array<string,mixed>
     */
    private function authenticatedPayload(array $rooftopIds): array
    {
        return [
            'key' => self::SHARED_KEY,
            'id' => self::SHARED_ID,
            'rooftop_ids' => $rooftopIds,
        ];
    }

    /**
     * @param array<string,string> $files    filename → CSV contents on the (single fake) FTP
     * @param array<string,mixed>  $payload  POST body
     *
     * @return array<string,mixed>
     */
    private function dispatchJob(array $files, array $payload): array
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload,
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();

        $job = new class ($webhookRequest, $files) extends ProcessDealerAppCenterCloudSyncWebhookJob {
            /**
             * @param array<string,string> $files
             */
            public function __construct(ReceiverWebhookCall $webhookRequest, private readonly array $files)
            {
                parent::__construct($webhookRequest);
            }

            #[Override]
            protected function makeSftpClient(array $config): InventorySftpClient
            {
                return new class ($this->files) extends InventorySftpClient {
                    /**
                     * @param array<string,string> $files
                     */
                    public function __construct(private readonly array $files)
                    {
                        // intentionally skip parent constructor to avoid network calls
                    }

                    #[Override]
                    public function listRoot(): array
                    {
                        return array_keys($this->files);
                    }

                    #[Override]
                    public function read(string $remotePath): ?string
                    {
                        return $this->files[$remotePath] ?? null;
                    }

                    #[Override]
                    public function disconnect(): void
                    {
                    }
                };
            }
        };

        return $job->handle();
    }
}
