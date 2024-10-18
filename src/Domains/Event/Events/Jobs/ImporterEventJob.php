<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Jobs;

use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event;
use Kanvas\Event\Events\Events\ImportResultEvents;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Throwable;

class ImporterEventJob extends ProductImporterJob
{
    public function handle()
    {
        // Import the events from the file
        // $data = [
        //     'app' => $this->app,
        //     'user' => $this->user,
        //     'company' => $this->branch->company,
        //     'theme' => Theme::getDefault($this->branch->company),
        //     'themeArea' => ThemeArea::getDefault($this->branch->company),
        //     'status' => EventStatus::getDefault($this->branch->company),
        //     'type' => EventType::getDefault($this->branch->company),
        //     'category' => EventCategory::getDefault($this->branch->company),
        //     'class' => EventClass::getDefault($this->branch->company),
        // ];

        $totalItems = count($this->importer);
        $totalProcessSuccessfully = 0;
        $totalProcessFailed = 0;
        $created = 0;
        $updated = 0;
        $errors = [];
        foreach ($this->importer as $request) {
            try {
                $data = Event::fromMultiple($this->app, $this->user, $this->branch->company, $request);
                $event = (new CreateEventAction($data))->execute();

                if ($event->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
                $totalProcessSuccessfully++;
            } catch (Throwable $e) {
                $errors[] = [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'request' => $request,
                ];

                Log::error($e->getMessage());
                // captureException($e);
                $totalProcessFailed++;
            }
        }
        $this->notificationStatus(
            $totalItems,
            $totalProcessSuccessfully,
            $totalProcessFailed,
            $created,
            $updated,
            $errors,
            $this->branch->company
        );
    }

    protected function notificationStatus(
        int $totalItems,
        int $totalProcessSuccessfully,
        int $totalProcessFailed,
        int $created,
        int $updated,
        array $errors,
        Companies $company
    ): void {
        $subscriptionData = [
                   'jobUuid' => $this->jobUuid,
                   'status' => 'completed',
                   'results' => [
                       'total_items' => $totalItems,
                       'total_process_successfully' => $totalProcessSuccessfully,
                       'total_process_failed' => $totalProcessFailed,
                       'created' => $created,
                       'updated' => $updated,
                   ],
                   'exception' => $errors,
                   'user' => $this->user,
                   'company' => $company,
               ];
        ImportResultEvents::dispatch($subscriptionData);
    }
}
