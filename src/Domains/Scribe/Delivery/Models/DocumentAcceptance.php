<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Models;

use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Quotes\Models\Quote;
use LogicException;
use Override;

/**
 * The evidence that a customer accepted one revision of a quote. Written once, in the transaction that
 * accepts the quote, and never changed or removed: a second acceptance of the same revision finds this
 * row instead of rewriting the signer.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $quote_id
 * @property int $quote_revision
 * @property int|null $sow_message_id
 * @property string|null $sow_hash
 * @property int|null $engagement_id
 * @property int|null $people_id
 * @property int|null $users_id
 * @property string|null $signer_name
 * @property string|null $signer_email
 * @property int|null $signature_filesystem_id
 * @property Carbon $accepted_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class DocumentAcceptance extends EloquentModel
{
    use KanvasModelTrait;

    public const UPDATED_AT = null;

    protected $connection = 'accounting';
    protected $table = 'document_acceptances';
    protected $guarded = ['id'];

    protected $casts = [
        'quote_id' => 'integer',
        'quote_revision' => 'integer',
        'sow_message_id' => 'integer',
        'engagement_id' => 'integer',
        'people_id' => 'integer',
        'users_id' => 'integer',
        'signature_filesystem_id' => 'integer',
        'accepted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('A document acceptance is evidence and is never changed.');
        });
    }

    #[Override]
    public function delete(): never
    {
        throw new LogicException('A document acceptance is evidence and is never deleted.');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id', 'id');
    }

    public static function forQuoteRevision(Quote $quote): ?self
    {
        /** @var self|null $acceptance */
        $acceptance = self::query()
            ->where('quote_id', $quote->getId())
            ->where('quote_revision', (int) $quote->revision_number)
            ->first();

        return $acceptance;
    }
}
