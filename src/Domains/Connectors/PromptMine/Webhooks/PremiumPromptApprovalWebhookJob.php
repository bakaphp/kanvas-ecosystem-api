<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Webhooks;

use DOMDocument;
use Exception;
use Illuminate\Support\Facades\Log;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class PremiumPromptApprovalWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;

        // Mailgun inbound POST uses keys like body-html or stripped-html
        $htmlContent = $this->getHtmlContentFromMailPayload($payload);

        if ($htmlContent === null) {
            return [
                'success' => false,
                'message' => 'No HTML content found in Mailgun payload',
                'payload' => $payload,
            ];
        }

        // Parse approval hash from HTML
        $approvalHash = $this->parseApprovalHashFromHtml($htmlContent);

        if ($approvalHash) {
            $messageApproval = Message::getByCustomField('premium_hash', $approvalHash);

            if ($messageApproval) {
                $messageApproval->set('premium_approval', true);

                return [
                    'success' => true,
                    'message' => 'Premium prompt approved successfully',
                    'data' => $messageApproval,
                ];
            }

            return [
                'success' => false,
                'message' => 'Approval hash found but no matching message',
                'approval_hash' => $approvalHash,
            ];
        }

        return [
            'success' => false,
            'message' => 'No premium hash found in HTML content',
            'html_content' => $htmlContent,
        ];
    }

    /**
     * Extract HTML content from Mailgun inbound payload.
     */
    private function getHtmlContentFromMailPayload(array $payload): ?string
    {
        // Mailgun inbound POST uses these keys:
        $htmlKeys = ['body-html', 'stripped-html', 'html'];

        foreach ($htmlKeys as $key) {
            if (! empty($payload[$key])) {
                return $payload[$key];
            }
        }

        return null;
    }

    /**
     * Parse approval hash from HTML content.
     */
    private function parseApprovalHashFromHtml(string $htmlContent): ?string
    {
        try {
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML($htmlContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();

            $h3Elements = $dom->getElementsByTagName('h3');

            foreach ($h3Elements as $h3) {
                if (stripos($h3->textContent, 'Approval Hash') !== false) {
                    $nextSibling = $h3->nextSibling;

                    while ($nextSibling && $nextSibling->nodeType === XML_TEXT_NODE) {
                        $nextSibling = $nextSibling->nextSibling;
                    }

                    if ($nextSibling && $nextSibling->nodeName === 'p') {
                        $hashValue = trim($nextSibling->textContent);
                        if ($this->isValidApprovalHash($hashValue)) {
                            return $hashValue;
                        }
                    }
                }
            }

            return $this->parseApprovalHashWithRegex($htmlContent);
        } catch (Exception $e) {
            Log::warning('Failed DOM parsing. Trying regex fallback.', [
                'error' => $e->getMessage(),
            ]);

            return $this->parseApprovalHashWithRegex($htmlContent);
        }
    }

    private function parseApprovalHashWithRegex(string $htmlContent): ?string
    {
        $patterns = [
            '/<h3[^>]*>Approval Hash:<\/h3>\s*<p[^>]*>([a-zA-Z0-9]{32})<\/p>/i',
            '/Approval Hash:\s*<\/h3>\s*<p[^>]*>([a-zA-Z0-9]{32})<\/p>/i',
            '/Approval Hash[:\s]*([a-zA-Z0-9]{32})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $htmlContent, $matches)) {
                $hashValue = trim($matches[1]);
                if ($this->isValidApprovalHash($hashValue)) {
                    return $hashValue;
                }
            }
        }

        return null;
    }

    private function isValidApprovalHash(string $hash): bool
    {
        return preg_match('/^[a-zA-Z0-9]{32}$/', $hash) === 1;
    }
}
