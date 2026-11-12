<?php

namespace App\Http\Controllers;

use App\Helpers\LogName;
use App\Services\ActivityLogService;
use App\Services\VatssaWebsiteStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * VATSSA: plain file areas (Other files, Staff files) and the private archive.
 *
 * Nothing here is ever deleted outright except from the archive, and only by
 * someone holding website.archive.manage. Everywhere else "remove" means
 * "move to the archive".
 */
class VatssaWebsiteFilesController extends Controller
{
    public function __construct(private VatssaWebsiteStore $store) {}

    // ---------------------------------------------------------------------
    // File areas
    // ---------------------------------------------------------------------

    public function index(Request $request, string $area): View
    {
        $config = $this->area($request, $area);

        return view('vatssa.website.files', [
            'area' => $area,
            'title' => $config['title'],
            'public' => $config['public'],
            'files' => $this->list($config['disk']),
            'types' => config('vatssa-website.file_types'),
            'maxMb' => intdiv(config('vatssa-website.file_max_kb'), 1024),
            'publicUrl' => fn (string $path) => $this->store->publicUrl($config['disk'], $path),
        ]);
    }

    public function upload(Request $request, string $area): RedirectResponse
    {
        $config = $this->area($request, $area);

        $input = $request->validate([
            'file' => 'required|file|mimes:' . implode(',', config('vatssa-website.file_types')) . '|max:' . config('vatssa-website.file_max_kb'),
            'folder' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9 _-]+$/'],
            'replace' => 'nullable|boolean',
        ], [
            'folder.regex' => 'A folder name can only use letters, numbers, spaces, hyphens and underscores.',
        ]);

        $file = $request->file('file');
        // The file's own extension when it is an allowed one (a .csv is often
        // detected as text/plain), otherwise the detected one.
        $clientExtension = strtolower($file->getClientOriginalExtension());
        $extension = in_array($clientExtension, config('vatssa-website.file_types'), true) ? $clientExtension : $file->extension();
        $path = $this->fileName($file->getClientOriginalName(), $extension);
        $folder = Str::slug($input['folder'] ?? '');
        if ($folder !== '') {
            $path = $folder . '/' . $path;
        }

        try {
            $exists = $this->store->exists($config['disk'], $path);
            if ($exists && empty($input['replace'])) {
                throw ValidationException::withMessages([
                    'file' => "A file called {$path} already exists. Tick \"Replace\" to publish a new version (the old one is kept in the archive), or rename your file.",
                ]);
            }

            if ($exists) {
                $this->store->archiveCopy($config['disk'], $path);
            }
            $this->store->putFile($config['disk'], $path, $file);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('VATSSA website: file upload failed', ['area' => $area, 'path' => $path, 'message' => $e->getMessage()]);

            return back()->withErrors(['storage' => 'Saving to file storage failed. Tech has the details in the log.']);
        }

        $verb = $exists ? 'replaced' : 'uploaded';
        ActivityLogService::info(LogName::Other, "Website: {$config['title']}: {$path} {$verb}");

        return $this->done($area, $config, "{$path} {$verb}", $exists ? [$path] : []);
    }

    public function archive(Request $request, string $area): RedirectResponse
    {
        $config = $this->area($request, $area);
        $path = $this->existingPath($request, $config['disk']);

        try {
            $this->store->archive($config['disk'], $path);
        } catch (Throwable $e) {
            Log::error('VATSSA website: archiving failed', ['area' => $area, 'path' => $path, 'message' => $e->getMessage()]);

            return back()->withErrors(['storage' => 'Archiving failed. Tech has the details in the log.']);
        }

        ActivityLogService::info(LogName::Other, "Website: {$config['title']}: {$path} archived");

        return $this->done($area, $config, "{$path} moved to the archive", [$path]);
    }

    public function download(Request $request, string $area): StreamedResponse
    {
        $config = $this->area($request, $area);

        return $this->stream($config['disk'], $this->existingPath($request, $config['disk']));
    }

    // ---------------------------------------------------------------------
    // Archive
    // ---------------------------------------------------------------------

    public function archiveIndex(Request $request): View
    {
        $this->authorizeArchive($request);

        return view('vatssa.website.archive', [
            'files' => $this->list(VatssaWebsiteStore::ARCHIVE_DISK),
        ]);
    }

    public function archiveDownload(Request $request): StreamedResponse
    {
        $this->authorizeArchive($request);

        return $this->stream(VatssaWebsiteStore::ARCHIVE_DISK, $this->existingPath($request, VatssaWebsiteStore::ARCHIVE_DISK));
    }

    public function archiveDelete(Request $request): RedirectResponse
    {
        $this->authorizeArchive($request);
        $request->validate(['confirm' => 'accepted'], ['confirm.accepted' => 'Tick the box to confirm. Deleting from the archive cannot be undone.']);
        $path = $this->existingPath($request, VatssaWebsiteStore::ARCHIVE_DISK);

        try {
            $this->store->delete(VatssaWebsiteStore::ARCHIVE_DISK, $path);
        } catch (Throwable $e) {
            Log::error('VATSSA website: archive delete failed', ['path' => $path, 'message' => $e->getMessage()]);

            return back()->withErrors(['storage' => 'Deleting failed. Tech has the details in the log.']);
        }

        ActivityLogService::warning(LogName::Other, "Website: archive: {$path} deleted for good");

        return redirect()->route('vatssa.website.archive')->withSuccess("{$path} deleted for good");
    }

    // ---------------------------------------------------------------------
    // Shared
    // ---------------------------------------------------------------------

    /**
     * The area's config, after checking the user may manage it.
     */
    private function area(Request $request, string $area): array
    {
        $config = config("vatssa-website.files.{$area}");

        abort_unless(is_array($config) && $request->user()?->hasGlobalPermission($config['permission']), 403);

        return $config;
    }

    private function authorizeArchive(Request $request): void
    {
        abort_unless($request->user()?->hasGlobalPermission(config('vatssa-website.archive.permission')), 403);
    }

    private function list(string $diskName): array
    {
        try {
            return $this->store->listFiles($diskName);
        } catch (Throwable $e) {
            Log::error('VATSSA website: listing failed', ['disk' => $diskName, 'message' => $e->getMessage()]);
            abort(503, 'File storage cannot be reached right now. Tech has the details in the log.');
        }
    }

    /**
     * The requested path, but only if it is an object that exists in the
     * bucket. A form cannot name anything else.
     */
    private function existingPath(Request $request, string $diskName): string
    {
        $path = $request->input('path');

        abort_unless(is_string($path) && $path !== '' && ! self::hasDotSegment($path), 404);

        try {
            $exists = $this->store->fileExists($diskName, $path);
        } catch (Throwable $e) {
            Log::error('VATSSA website: exists check failed', ['disk' => $diskName, 'message' => $e->getMessage()]);
            abort(503, 'File storage cannot be reached right now. Tech has the details in the log.');
        }
        abort_unless($exists, 404);

        return $path;
    }

    private function stream(string $diskName, string $path): StreamedResponse
    {
        try {
            return $this->store->download($diskName, $path);
        } catch (Throwable $e) {
            // Gone between the check and the read, or storage down.
            Log::warning('VATSSA website: download failed', ['disk' => $diskName, 'path' => $path, 'message' => $e->getMessage()]);
            abort(404);
        }
    }

    /**
     * A '.' or '..' segment. Names like "minutes..final.pdf" are fine.
     */
    public static function hasDotSegment(string $path): bool
    {
        return array_intersect(explode('/', $path), ['.', '..']) !== [];
    }

    /**
     * Log, purge the public URLs that changed, and go back to the area.
     */
    private function done(string $area, array $config, string $message, array $changedPaths): RedirectResponse
    {
        $redirect = redirect()->route('vatssa.website.' . $area)->withSuccess($message);

        if (! $config['public'] || $changedPaths === []) {
            return $redirect;
        }

        $warning = $this->store->purge(array_map(fn ($path) => $this->store->publicUrl($config['disk'], $path), $changedPaths));

        return $warning === null ? $redirect : $redirect->with('vatssa_website_warning', $warning);
    }

    /**
     * Naming convention: lowercase words joined by hyphens, from the
     * uploaded file's own name, with its real extension.
     */
    private function fileName(string $originalName, ?string $extension): string
    {
        $base = Str::of(Str::slug(pathinfo($originalName, PATHINFO_FILENAME)))->limit(120, '')->trim('-')->toString();
        $extension = $extension === 'jpeg' ? 'jpg' : ($extension ?: 'bin');

        return ($base === '' ? 'file-' . now()->utc()->format('Ymd-His') : $base) . '.' . $extension;
    }

    private function filled(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
}
