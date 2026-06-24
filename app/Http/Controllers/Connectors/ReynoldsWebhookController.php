<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connectors;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Webhooks\ProcessReynoldsWebhookJob;
use Ramsey\Uuid\Uuid;

class ReynoldsWebhookController extends BaseController
{
    public function receive(Request $request): Response
    {
        $rawXml = $request->getContent();

        if (empty($rawXml)) {
            return $this->confirmResponse('Failed', '202', 'Empty request body');
        }

        try {
            ProcessReynoldsWebhookJob::dispatch(app(Apps::class), $rawXml);
        } catch (\Throwable $e) {
            Log::error('Reynolds webhook dispatch failed', ['error' => $e->getMessage()]);

            return $this->confirmResponse('Failed', '500', $e->getMessage());
        }

        return $this->confirmResponse('Success', '0', 'Received');
    }

    private function confirmResponse(string $status, string $statusCode, string $message): Response
    {
        $bodId = Uuid::uuid4()->toString();
        $createdAt = Carbon::now()->format('Y-m-d\TH:i:s');

        $body = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
    <soapenv:Body>
        <ConfirmMessage xmlns="http://www.starstandards.org/webservices/2005/10/transport">
            <ApplicationArea xmlns="http://www.starstandards.org/STAR">
                <BODId>{$bodId}</BODId>
                <CreationDateTime>{$createdAt}</CreationDateTime>
            </ApplicationArea>
            <TransStatus xmlns="http://www.starstandards.org/STAR">
                <Status>{$status}</Status>
                <StatusCode>{$statusCode}</StatusCode>
                <StatusMessage>{$message}</StatusMessage>
            </TransStatus>
        </ConfirmMessage>
    </soapenv:Body>
</soapenv:Envelope>
XML;

        return response($body, 200)->header('Content-Type', 'text/xml; charset=utf-8');
    }
}
