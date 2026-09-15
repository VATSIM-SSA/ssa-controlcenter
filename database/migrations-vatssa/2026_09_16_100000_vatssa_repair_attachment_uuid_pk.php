<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * VATSSA: `training_object_attachments.id` never became a UUID.
 *
 * ## The bug
 *
 * `TrainingObjectAttachment` uses `HasUuids`, so every insert writes a UUID into
 * `id`. On the DigitalOcean database that column was still
 * `bigint AUTO_INCREMENT`, and MySQL answered every attachment upload with
 *
 *     SQLSTATE[01000]: Warning: 1265 Data truncated for column 'id' at row 1
 *
 * which the user saw as a 500 on the training record. No attachment could ever
 * be saved, on the droplet or on the VPS: the table held zero rows on
 * 2026-09-16, while `files` held twelve uploads pointing at nothing.
 *
 * ## Why the schema was wrong
 *
 * Upstream's `2024_07_10_094930_change_objects_to_uuid` does the swap. The
 * retired `ssa-controlcenter-custom` overlay replaced that file with a version
 * that only added a separate `uuid CHAR(36)` column, because DigitalOcean's
 * `sql_require_primary_key` refused the upstream steps. Laravel recorded the
 * migration as run, so the real one can never execute again, and the wrong shape
 * travelled to the VPS inside the database dump. The overlay is gone (see
 * PATCHES.md, "Deliberately not carried across") but every database built from
 * that lineage still carries the damage.
 *
 * ## What this does
 *
 * Converts `id` to `CHAR(36)`, gives every existing row a fresh UUID, and drops
 * the unused `uuid` column. Attachment ids are not referenced by a foreign key
 * anywhere, so regenerating them is safe.
 *
 * Idempotent, and safe on a database that is already correct: it checks the live
 * column type first. Production was repaired by hand on 2026-09-16, so there
 * this migration is a no-op; staging and dev need it.
 */
return new class extends Migration
{
    private const TABLE = 'training_object_attachments';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->idIsUuid()) {
            $this->dropLeftoverUuidColumn();

            return;
        }

        // Widening the column keeps the primary key and drops AUTO_INCREMENT.
        // Existing numeric ids survive the change as strings, and are replaced
        // row by row immediately after.
        DB::statement('ALTER TABLE `'.self::TABLE.'` MODIFY `id` CHAR(36) NOT NULL');

        DB::table(self::TABLE)->select('id')->orderBy('id')->each(function ($row) {
            DB::table(self::TABLE)->where('id', $row->id)->update(['id' => (string) Str::uuid()]);
        });

        $this->dropLeftoverUuidColumn();
    }

    public function down(): void
    {
        // No reverse. Going back to an integer key would mean discarding the
        // UUIDs that the model writes, which breaks every attachment again.
    }

    private function idIsUuid(): bool
    {
        $column = DB::selectOne(
            'SELECT DATA_TYPE AS data_type FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::TABLE, 'id']
        );

        return $column !== null && strtolower($column->data_type) === 'char';
    }

    private function dropLeftoverUuidColumn(): void
    {
        if (Schema::hasColumn(self::TABLE, 'uuid')) {
            DB::statement('ALTER TABLE `'.self::TABLE.'` DROP COLUMN `uuid`');
        }
    }
};
