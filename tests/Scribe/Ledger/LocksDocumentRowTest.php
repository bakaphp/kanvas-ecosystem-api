<?php

declare(strict_types=1);

namespace Tests\Scribe\Ledger;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Ledger\Actions\Concerns\LocksDocumentRow;
use Tests\Scribe\ScribeTestCase;

class LocksDocumentRowTest extends ScribeTestCase
{
    use LocksDocumentRow;

    private const string READER = 'lock_row_reader';
    private const string WRITER = 'lock_row_writer';

    /**
     * Needs two real connections: a snapshot only goes stale against a commit made by somebody else.
     */
    public function test_the_guard_sees_what_was_committed_after_the_transaction_took_its_snapshot(): void
    {
        foreach ([self::READER, self::WRITER] as $name) {
            config(["database.connections.{$name}" => config('database.connections.accounting')]);
        }

        $reader = DB::connection(self::READER);
        $writer = DB::connection(self::WRITER);
        $table = 'lock_row_' . bin2hex(random_bytes(6));
        $writer->statement("CREATE TABLE `{$table}` (id BIGINT PRIMARY KEY, status VARCHAR(20)) ENGINE=InnoDB");

        try {
            $writer->table($table)->insert(['id' => 1, 'status' => 'draft']);
            $reader->beginTransaction();
            $document = $this->documentIn($table);
            $writer->table($table)->where('id', 1)->update(['status' => 'cancelled']);

            $seen = $this->withRowLocked($document, fn (): string => $document->status);

            $this->assertSame('cancelled', $seen);
        } finally {
            $reader->rollBack();
            $writer->statement("DROP TABLE `{$table}`");
            DB::purge(self::READER);
            DB::purge(self::WRITER);
        }
    }

    /**
     * The ledger connection reads committed rows on every statement. Under the server default a transaction
     * keeps the snapshot of its first read, and a balance summed inside a caller's transaction misses a
     * payment another request committed meanwhile.
     */
    public function test_a_ledger_transaction_reads_what_was_committed_after_it_began(): void
    {
        foreach ([self::READER, self::WRITER] as $name) {
            config(["database.connections.{$name}" => config('database.connections.accounting')]);
        }

        $reader = DB::connection(self::READER);
        $writer = DB::connection(self::WRITER);
        $table = 'lock_row_' . bin2hex(random_bytes(6));
        $writer->statement("CREATE TABLE `{$table}` (id BIGINT PRIMARY KEY, status VARCHAR(20)) ENGINE=InnoDB");

        try {
            $reader->beginTransaction();
            $this->assertSame(0, $reader->table($table)->count());
            $writer->table($table)->insert(['id' => 1, 'status' => 'paid']);

            $this->assertSame(1, $reader->table($table)->count());
        } finally {
            $reader->rollBack();
            $writer->statement("DROP TABLE `{$table}`");
            DB::purge(self::READER);
            DB::purge(self::WRITER);
        }
    }

    private function documentIn(string $table): Model
    {
        $document = new class () extends Model {
            public $timestamps = false;
        };
        $document->setConnection(self::READER);
        $document->setTable($table);

        return $document->newQuery()->findOrFail(1);
    }
}
