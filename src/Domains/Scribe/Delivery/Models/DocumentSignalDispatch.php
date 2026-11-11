<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Models;

use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Delivery\Enums\DocumentSignalDispatchStatusEnum;
use Kanvas\Scribe\Delivery\Enums\DocumentSignalEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $signal_key
 * @property string $document_type
 * @property int $document_id
 * @property DocumentSignalEnum $signal
 * @property string $brief
 * @property Carbon|null $due_at
 * @property DocumentSignalDispatchStatusEnum $status
 * @property int|null $task_id
 * @property int $attempts
 * @property Carbon|null $next_retry_at
 * @property string|null $last_error
 */
class DocumentSignalDispatch extends EloquentModel
{
    use KanvasModelTrait;

    protected $connection = 'accounting';
    protected $table = 'document_signal_dispatches';
    protected $guarded = ['id'];

    protected $attributes = [
        'attempts' => 0,
    ];

    protected $casts = [
        'document_id' => 'integer',
        'signal' => DocumentSignalEnum::class,
        'status' => DocumentSignalDispatchStatusEnum::class,
        'task_id' => 'integer',
        'attempts' => 'integer',
        'due_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public static function keyFor(Invoice|Quote $document, DocumentSignalEnum $signal): string
    {
        return $document->documentKind() . ':' . $document->getId() . ':' . $signal->value;
    }

    public function document(): Invoice|Quote|null
    {
        $class = $this->document_type === 'quote' ? Quote::class : Invoice::class;

        /** @var Invoice|Quote|null $document */
        $document = $class::query()
            ->whereKey($this->document_id)
            ->where('apps_id', $this->apps_id)
            ->where('companies_id', $this->companies_id)
            ->where('is_deleted', 0)
            ->first();

        return $document;
    }
}
