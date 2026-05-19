<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Tables this migration touches
        $tables = [
            'training_object_attachments',
            // Add other tables here if needed
            // e.g. 'training_objects', 'training_object_versions'
        ];

        foreach ($tables as $t) {

            // Table must exist
            if (!Schema::hasTable($t)) {
                continue;
            }

            // Add uuid column ONLY if it does not already exist
            if (!Schema::hasColumn($t, 'uuid')) {
                DB::statement("ALTER TABLE `$t` ADD COLUMN `uuid` CHAR(36) NOT NULL");
            }
        }
    }

    public function down(): void
    {
        // Optional: drop uuid column
        // foreach (['training_object_attachments'] as $t) {
        //     if (Schema::hasTable($t) && Schema::hasColumn($t, 'uuid')) {
        //         DB::statement("ALTER TABLE `$t` DROP COLUMN `uuid`");
        //     }
        // }
    }
};
