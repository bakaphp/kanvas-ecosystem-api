<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Actions;

use Illuminate\Support\Str;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Connectors\ShortIo\Services\ShortIoConfigurationService;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Scribe\Delivery\DataTransferObject\DocumentLink;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\Delivery\Services\DocumentHtmlService;
use Kanvas\Scribe\Delivery\Support\DocumentActionsSetup;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Users\Models\Users;

/**
 * The engagement (and its link) through which one person sees one document. The open engagement for
 * the same document, person, action and sharer is reused, so a link handed out by hand, a send and a
 * dunning reminder all carry the same link and the page's history stays one thread.
 */
class OpenDocumentEngagementAction
{
    /**
     * @param list<Filesystem> $files
     */
    public function __construct(
        private readonly Invoice|Quote $document,
        private readonly People $people,
        private readonly Users $user,
        private readonly DocumentActionEnum $action,
        private readonly string $via = 'copy',
        private readonly array $files = [],
        private readonly DocumentHtmlService $html = new DocumentHtmlService(),
    ) {
    }

    /**
     * Without a page path the engagement would get no link, and a link that points nowhere is worse
     * than none; checked before anything is written.
     */
    public function assertSetUp(): void
    {
        $key = DocumentActionsSetup::PAGE_CONFIG_KEY;
        $config = $this->document->company->get($key) ?? $this->document->app->get($key) ?? [];

        if (! is_array($config) || ! (array_key_exists($this->action->value, $config) || in_array($this->action->value, $config, true))) {
            throw $this->notSetUp();
        }

        // Without a base URL the page link comes out as a bare "/view/{uuid}" a customer cannot open.
        if (! str_starts_with(ShortIoConfigurationService::linkPageBaseUrl($this->document->app, $this->document->company), 'https://')) {
            throw new DocumentDeliveryException(
                DocumentDeliveryException::NOT_SET_UP,
                'Document links have no page address yet. A Kanvas admin sets the link page URL (the short.io '
                . 'integration, or the app\'s NEW_ACTION_PAGE_V4); nothing was created or sent.'
            );
        }
    }

    public function execute(): DocumentLink
    {
        $this->assertSetUp();
        $data = $this->html->for($this->document, $this->action);

        $engagement = new CreateEngagementAction(
            EngagementData::from(
                $this->user,
                $this->people,
                $this->document,
                [
                    'action' => $this->action->value,
                    'request_id' => (string) Str::uuid(),
                    'source' => 'scribe',
                    'status' => ActionStatusEnum::SENT->value,
                    'via' => $this->via,
                    'data' => $data,
                    'files' => $this->files,
                ]
            ),
            allowDuplicate: false,
        )->execute();
        $this->refreshSnapshot($engagement, $data);

        $link = (string) ($engagement->message?->getMessage()['action_link'] ?? '');
        if ($link === '') {
            throw $this->notSetUp();
        }

        return new DocumentLink(
            engagement: $engagement,
            link: $link,
            people: $this->people,
            action: $this->action,
        );
    }

    /**
     * A reused engagement keeps the message it was opened with, so a document edited between two shares
     * would show its first body forever; only the page data is rewritten, the link and the rest stay.
     *
     * @param array<string, string> $data
     */
    private function refreshSnapshot(Engagement $engagement, array $data): void
    {
        $message = $engagement->message;
        if ($message === null) {
            return;
        }

        $body = $message->getMessage();
        $current = is_array($body['data'] ?? null) ? $body['data'] : [];
        if (array_intersect_key($current, $data) === $data) {
            return;
        }

        $body['data'] = [...$current, ...$data];
        $message->message = $body;
        $message->saveOrFail();
    }

    private function notSetUp(): DocumentDeliveryException
    {
        return new DocumentDeliveryException(
            DocumentDeliveryException::NOT_SET_UP,
            "The {$this->action->value} page is not set up for this company. A company admin finishes the "
            . 'accounting setup in onboarding, or asks the setup assistant to run it again (run_accounting_setup).'
        );
    }
}
