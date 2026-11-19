<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// VATSSA: attachment storage owned by the web server, and the attachment-key repair.
class VatssaAttachmentStorageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_image_hands_storage_to_the_web_server_after_creating_the_files_folder(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $mkdir = strpos($dockerfile, 'mkdir -p /app/storage/app/public/files');
        $chown = strpos($dockerfile, 'chown -R www-data:www-data /app/storage');
        $this->assertNotFalse($mkdir, 'upstream no longer creates the files folder here; re-check the patch');
        $this->assertNotFalse($chown, 'storage is not handed to www-data: uploads will fail silently');
        $this->assertGreaterThan($mkdir, $chown, 'the chown must come after the folder is created');
    }

    #[Test]
    public function the_repair_migration_leaves_a_correct_table_alone(): void
    {
        $columnsBefore = Schema::getColumnListing('training_object_attachments');
        $migration = require database_path('migrations-vatssa/2026_09_16_100000_vatssa_repair_attachment_uuid_pk.php');

        $migration->up();

        $this->assertSame($columnsBefore, Schema::getColumnListing('training_object_attachments'));
    }
}
