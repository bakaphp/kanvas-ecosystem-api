<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Baka\Enums\StateEnums;
use Illuminate\Support\Collection;
use Kanvas\Connectors\SalesAssist\DataTransferObject\TradeIn;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\Models\Message;

class AttachTradeInPhotosAction
{
    public function __construct(
        protected Message $message
    ) {
    }

    /**
     * Read-only, so the trade-in PDF template can render the photos whether or not
     * they have been attached yet.
     *
     * @return Collection<string, Collection<int, Filesystem>>
     */
    public function photos(): Collection
    {
        $photoIds = TradeIn::fromMessageData($this->message->getMessage()['data'] ?? [])->photoIds;

        // TODO: restore the companies_id filter below once the action page sends X-Kanvas-Location.
        // It uploads with the app key and no branch header, so its files land in the key owner's
        // company, not the dealer's (needs `use Kanvas\Enums\AppEnums;` back).
        $files = Filesystem::query()
            ->whereIn('id', array_merge([], ...array_values($photoIds)))
            ->where('apps_id', $this->message->apps_id)
            // ->whereIn('companies_id', [
            //     $this->message->companies_id,
            //     AppEnums::GLOBAL_COMPANY_ID->getValue(),
            // ])
            ->where('is_deleted', StateEnums::NO->getValue())
            ->get()
            ->keyBy('id');

        return collect($photoIds)
            ->map(fn (array $ids) => collect($ids)->map(fn (int $id) => $files->get($id))->filter()->values())
            ->filter(fn (Collection $group) => $group->isNotEmpty());
    }

    public function execute(): int
    {
        $attached = 0;
        foreach ($this->photos() as $field => $files) {
            // Unique field name per file: unless the app allows duplicates by name, a second
            // file under the same field name replaces the first.
            foreach ($files as $index => $file) {
                $this->message->addFile($file, $files->count() > 1 ? $field . '_' . ($index + 1) : $field);
                $attached++;
            }
        }

        return $attached;
    }
}
