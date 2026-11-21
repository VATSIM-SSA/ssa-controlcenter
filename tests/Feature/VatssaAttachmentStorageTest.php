<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\File;
use App\Models\Training;
use App\Models\TrainingObjectAttachment;
use App\Models\TrainingReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// VATSSA: attachment storage owned by the web server, and the attachment-key repair.
class VatssaAttachmentStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
    }

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

    private static function onePixelPng(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    private function report(): TrainingReport
    {
        $report = TrainingReport::factory()->create([
            'training_id' => Training::factory()->create(['user_id' => User::factory()->create(['id' => 10000005])->id])->id,
            'written_by_id' => User::factory()->create(['id' => 10000001])->id,
        ]);
        $report->author->roleAssignments()->create(['role' => 'mentor', 'area_id' => $report->training->area->id]);
        $report->training->mentors()->attach($report->author, ['expire_at' => now()->addYear()]);

        return $report;
    }

    private function storeUrl(TrainingReport $report): string
    {
        return route('training.object.attachment.store', ['trainingObjectType' => 'report', 'trainingObject' => $report]);
    }

    #[Test]
    public function only_the_validated_field_is_saved(): void
    {
        $report = $this->report();

        $this->actingAs($report->author)->post($this->storeUrl($report), [
            'file' => UploadedFile::fake()->createWithContent('ok.png', self::onePixelPng()),
            'evil' => UploadedFile::fake()->createWithContent('evil.html', '<script>alert(1)</script>'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(1, $report->attachments()->count());
        $this->assertSame(0, File::where('name', 'evil.html')->count());
    }

    #[Test]
    public function a_lone_file_in_the_files_field_is_refused(): void
    {
        $report = $this->report();

        $this->actingAs($report->author)
            ->post(route('training.report.store', $report->training), ['files' => UploadedFile::fake()->createWithContent('evil.html', '<script></script>')])
            ->assertSessionHasErrors('files');
    }

    #[Test]
    public function the_upload_permission_does_not_reach_someone_elses_training(): void
    {
        $report = $this->report();
        $outsider = User::factory()->create(['id' => 10000009]);
        $otherArea = Area::where('id', '!=', $report->training->area_id)->first() ?? Area::factory()->create();
        $outsider->roleAssignments()->create(['role' => 'mentor', 'area_id' => $otherArea->id]);
        $this->assertTrue($outsider->can('create', TrainingObjectAttachment::class));

        $this->actingAs($outsider)->postJson($this->storeUrl($report), ['file' => UploadedFile::fake()->createWithContent('x.png', self::onePixelPng())])->assertForbidden();
        $this->assertSame(0, $report->attachments()->count());
    }

    #[Test]
    public function html_downloads_and_images_render(): void
    {
        $user = User::factory()->create(['id' => 10000002]);
        Storage::put('public/files/a.html', '<!doctype html><html><script>alert(1)</script></html>');
        Storage::put('public/files/b.png', self::onePixelPng());
        File::create(['id' => 'html-file', 'name' => 'a.html', 'path' => 'a.html', 'uploaded_by' => $user->id]);
        File::create(['id' => 'png-file', 'name' => 'b.png', 'path' => 'b.png', 'uploaded_by' => $user->id]);

        $response = $this->actingAs($user)->get(route('file.get', ['file' => 'html-file']));
        $response->assertOk();
        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        $response = $this->actingAs($user)->get(route('file.get', ['file' => 'png-file']));
        $response->assertHeader('Content-Disposition', 'inline');
    }

    #[Test]
    public function the_entrypoint_hands_an_existing_volume_to_the_web_server(): void
    {
        $script = file_get_contents(base_path('container/entrypoint.sh'));

        $chown = strpos($script, 'chown -R www-data:www-data');
        $this->assertNotFalse($chown);
        $this->assertLessThan(strpos($script, 'exec docker-php-entrypoint'), $chown);
    }
}
