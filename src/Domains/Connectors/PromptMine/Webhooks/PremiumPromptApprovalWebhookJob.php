<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Webhooks;

use DOMDocument;
use Illuminate\Support\Facades\Notification;
use Kanvas\Notifications\Templates\Blank;
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

        $company = $this->receiver->company;

        if ($approvalHash) {
            $messageApproval = Message::getByCustomField('premium_hash', $approvalHash, $company);

            if ($messageApproval) {
                $messageApproval->set('premium_approval', true);

                //notify the user

                $notification = new Blank(
                    'premium-request-approved',
                    [
                        'message' => $messageApproval,
                    ],
                    ['mail'],
                    $messageApproval,
                );

                $notification->setSubject('Premium Prompt Approved');
                Notification::send($messageApproval->user, $notification);

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
            'html_content' => substr($htmlContent, 0, 500) . '...',
        ];
    }

    /**
     * Extract HTML content from Mailgun inbound payload.
     */
    private function getHtmlContentFromMailPayload(array $payload): ?string
    {
        // Mailgun inbound POST uses these keys, prioritizing body-html which contains the full forwarded email
        $htmlKeys = ['body-html', 'body-plain', 'stripped-html', 'stripped-text'];

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
        // First try regex patterns that work with the raw HTML including \r\n
        $approvalHash = $this->parseApprovalHashWithRegex($htmlContent);

        if ($approvalHash) {
            return $approvalHash;
        }

        // Fallback to DOM parsing if regex fails

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

        return null;
    }

    private function parseApprovalHashWithRegex(string $htmlContent): ?string
    {
        // Updated patterns to handle the specific format from Mailgun with \r\n
        $patterns = [
            // Pattern for the exact format from your example: <h3>Approval Hash:</h3>\r\n                <p>H0DAEzY7UagRHXmp3hMxl8I7ph5WhTnZ</p>
            '/<h3[^>]*>Approval Hash:<\/h3>[\r\n\s]*<p[^>]*>([a-zA-Z0-9]{32})<\/p>/i',
            // Pattern without the exact whitespace
            '/<h3[^>]*>Approval Hash:<\/h3>\s*<p[^>]*>([a-zA-Z0-9]{32})<\/p>/i',
            // More flexible pattern
            '/Approval Hash:\s*<\/h3>[\r\n\s]*<p[^>]*>([a-zA-Z0-9]{32})<\/p>/i',
            // Very flexible pattern that looks for the text pattern
            '/Approval Hash[:\s]*([a-zA-Z0-9]{32})/i',
            // Pattern for plain text version
            '/Approval Hash:\s*([a-zA-Z0-9]{32})/i',
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
