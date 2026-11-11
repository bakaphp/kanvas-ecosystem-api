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

    public function generateFileLinks(): ?string
    {
        $linkPreview = '';
        $totalCount = 0;

        foreach (array_filter([$this->message, $this->message->parent]) as $source) {
            $i = 1;
            foreach ($source->getFiles() ?: [] as $pdf) {
                $fileName = $this->cleanFiles($pdf, 'File' . $i);
                $url = Url::getShortUrl($pdf->url, $this->message->app, company: $this->message->company);
                $linkPreview .= '(' . $fileName . ': ' . $url . '),';
                $i++;
                $totalCount++;
            }
        }

        /**
         * @todo remove all previous code and just leave global file viewer
         */
        $lead = $this->message->entity(Lead::class);
        if ($lead instanceof Lead && $totalCount > 0) {
            $linkPreview = $this->filesViewUrl($lead);
        }

        return $linkPreview;
    }

    public function filesViewUrl(Lead $lead): string
    {
        $uuid = $this->message->parent ? $this->message->parent->uuid : $this->message->uuid;
        $url = $this->message->app->get(ConfigurationEnum::SALES_ASSIST_LANDING_PAGE->value) . '/global-viewer?lid=' . $lead->uuid . '&mid=' . $uuid . '&ia=1';

        return Url::getShortUrl($url, $this->message->app, company: $this->message->company) . '?openInSa=true';
    }

    public function cleanFiles(FilesystemEntities $file, string $proposeFileName): string
    {
        $fileName = preg_replace('/\\.[^.\\s]{3,4}$/', '', $file->field_name);

        return ! empty($fileName) ? $fileName : $proposeFileName;
    }
}
