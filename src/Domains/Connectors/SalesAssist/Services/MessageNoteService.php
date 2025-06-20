<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Services;

use Baka\Support\Url;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;

class MessageNoteService
{
    public function __construct(
        protected Message $message
    ) {
    }

    /**
     * Generate notes file links.
     */
    public function generateFileLinks(): ?string
    {
        $linkPreview = '';
        $totalCount = 0;

        //overwrite note likes if we have pdf
        if ($files = $this->message->getFiles()) {
            $i = 1;
            foreach ($files as $pdf) {
                $fileName = $this->cleanFiles($pdf, 'File' . $i);
                $url = Url::getShortUrl($pdf->url, $this->message->app);
                $linkPreview .= '(' . $fileName . ': ' . $url . '),';
                $i++;
                $totalCount++;
            }
        }

        if ($this->message->hasParent()) {
            if ($files = $this->message->parent->getFiles()) {
                $i = 1;
                foreach ($files as $pdf) {
                    $fileName = $this->cleanFiles($pdf, 'File' . $i);
                    $url = Url::getShortUrl($pdf->url, $this->message->app);
                    $linkPreview .= '(' . $fileName . ': ' . $url . '),';
                    $i++;
                    $totalCount++;
                }
            }
        }

        /**
         * @todo remove all previous code and just leave global file viewer
         */
        if ($this->message->entity() instanceof Lead && $totalCount > 0) {
            $linkPreview = $this->filesViewUrl($this->message->entity());
        }

        return $linkPreview;
    }

    /**
     * Get file viewer url.
     */
    public function filesViewUrl(Lead $lead): string
    {
        $uuid = $this->message->hasParent() ? $this->message->parentMessage->uuid : $this->message->uuid;
        $url = $this->message->app->get(ConfigurationEnum::SALES_ASSIST_LANDING_PAGE->value) . '/global-viewer?lid=' . $lead->uuid . '&mid=' . $uuid . '&ia=1';

        return Url::getShortUrl(
            $url,
            $this->message->app
        ) . '?openInSa=true';
    }

    /**
     * Cleanup filename.
     */
    public function cleanFiles(FilesystemEntities $file, string $proposeFileName): string
    {
        //remove anything after the .
        $fileName = preg_replace('/\\.[^.\\s]{3,4}$/', '', $file->field_name);

        return ! empty($fileName) ? $fileName : $proposeFileName;
    }
}
