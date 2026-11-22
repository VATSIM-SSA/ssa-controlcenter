<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// VATSSA: the repair migration's MySQL path. Skipped unless pointed at a scratch server
// with VATSSA_REPAIR_TEST_HOST; it only ever touches a database named cc_repair_test.
class VatssaAttachmentRepairMysqlTest extends TestCase
{
    private const TABLE = 'training_object_attachments';

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('VATSSA_REPAIR_TEST_HOST')) {
            $this->markTestSkipped('Needs a scratch MySQL server.');
        }

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => env('VATSSA_REPAIR_TEST_HOST'),
            'database.connections.mysql.database' => 'cc_repair_test',
            'database.connections.mysql.username' => 'root',
            'database.connections.mysql.password' => env('VATSSA_REPAIR_TEST_PASSWORD'),
        ]);
        DB::purge('mysql');
        DB::statement('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    private function runRepair(): void
    {
        (require database_path('migrations-vatssa/2026_09_16_100000_vatssa_repair_attachment_uuid_pk.php'))->up();
    }

    #[Test]
    public function a_broken_table_is_converted_with_its_data_and_a_second_run_changes_nothing(): void
    {
        DB::statement('CREATE TABLE ' . self::TABLE . ' (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, uuid CHAR(36) NULL, file_id VARCHAR(64) NOT NULL)');
        DB::table(self::TABLE)->insert([['file_id' => 'a'], ['file_id' => 'b'], ['file_id' => 'c']]);

        $this->runRepair();

        $ids = DB::table(self::TABLE)->orderBy('file_id')->pluck('id', 'file_id');
        $this->assertSame(['a', 'b', 'c'], $ids->keys()->all());
        $this->assertTrue($ids->every(fn ($id) => Str::isUuid($id)));
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'uuid'));

        $this->runRepair();
        $this->assertEquals($ids, DB::table(self::TABLE)->orderBy('file_id')->pluck('id', 'file_id'));
    }

    #[Test]
    public function an_interrupted_repair_finishes_on_the_next_run(): void
    {
        $done = (string) Str::uuid();
        DB::statement('CREATE TABLE ' . self::TABLE . ' (id CHAR(36) NOT NULL PRIMARY KEY, uuid CHAR(36) NULL, file_id VARCHAR(64) NOT NULL)');
        DB::table(self::TABLE)->insert([['id' => $done, 'file_id' => 'a'], ['id' => '2', 'file_id' => 'b'], ['id' => '10', 'file_id' => 'c']]);

        $this->runRepair();

        $ids = DB::table(self::TABLE)->orderBy('file_id')->pluck('id', 'file_id');
        $this->assertSame($done, $ids['a']);
        $this->assertTrue($ids->every(fn ($id) => Str::isUuid($id)));
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'uuid'));
    }
}
