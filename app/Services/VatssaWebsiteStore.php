<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use League\Flysystem\FileAttributes;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * VATSSA: reads and writes the website's JSON and files in MinIO, and purges
 * Cloudflare's cached copies afterwards.
 *
 * Every write is synchronous on purpose: CC has no queue worker in production,
 * and an editor must see a failure on the page, not have it vanish into a job.
 */
class VatssaWebsiteStore
{
    public const HOMEPAGE_DISK = 'vatssa-homepage';

    public const ARCHIVE_DISK = 'vatssa-archive';

    /**
     * The JSON object for a page, as stored.
     *
     * @return array{raw: ?string, data: mixed, version: string}
     */
    public function read(string $page): array
    {
        $path = $this->jsonPath($page);
        $disk = $this->disk(self::HOMEPAGE_DISK);

        $raw = $disk->exists($path) ? $disk->get($path) : null;
        $data = null;

        if ($raw !== null) {
            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // A hand-edited file with a stray comma. Shown to the editor as
                // empty, with a warning, rather than crashing.
                $data = null;
            }
        }

        return [
            'raw' => $raw,
            'data' => $data,
            'version' => $this->version($raw),
            'unreadable' => $raw !== null && $data === null,
        ];
    }

    /**
     * Replace a page's JSON. Refuses if the object changed since the editor
     * loaded it, so two editors cannot silently overwrite each other. The check
     * and the write are two calls, not one atomic step: two saves landing in
     * the same instant can still both pass. Fine for a handful of editors.
     *
     * @throws VatssaWebsiteConflict
     */
    public function write(string $page, array|object $payload, string $expectedVersion): void
    {
        $path = $this->jsonPath($page);
        $disk = $this->disk(self::HOMEPAGE_DISK);

        $current = $disk->exists($path) ? $disk->get($path) : null;
        if (! hash_equals($this->version($current), $expectedVersion)) {
            throw new VatssaWebsiteConflict;
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        if ($current !== null) {
            // The previous JSON goes to the private archive bucket.
            $this->put($this->disk(self::ARCHIVE_DISK), 'json/' . $page . '/' . $this->stamp() . '.json', $current);
        }

        $this->put($disk, $path, $json);
    }

    public function exists(string $diskName, string $path): bool
    {
        return $this->disk($diskName)->exists($path);
    }

    /**
     * Store an uploaded file under an exact name.
     */
    public function putFile(string $diskName, string $path, UploadedFile $file): void
    {
        $disk = $this->disk($diskName);
        $stream = fopen($file->getRealPath(), 'rb');

        try {
            $this->put($disk, $path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Copy an object into the private archive, keeping it where it is. Used
     * before a file is replaced, so every earlier version is kept.
     *
     * Archive layout: <bucket>/<object path>/<UTC stamp>__<file name>, so all
     * versions of one file sit together.
     */
    public function archiveCopy(string $diskName, string $path): ?string
    {
        $source = $this->disk($diskName);
        if (! $source->exists($path)) {
            return null;
        }

        $bucket = config("vatssa-website.buckets.{$diskName}");
        $target = $bucket . '/' . $path . '/' . $this->stamp() . '__' . basename($path);
        $archive = $this->disk(self::ARCHIVE_DISK);

        // On MinIO, copy inside the server (CopyObject): nothing passes through
        // PHP, so a large legacy file cannot hit memory or time limits.
        if ($source instanceof AwsS3V3Adapter && $archive instanceof AwsS3V3Adapter) {
            $source->getClient()->copyObject([
                'Bucket' => config('vatssa-website.buckets.' . self::ARCHIVE_DISK),
                'Key' => $target,
                'CopySource' => rawurlencode($bucket) . '/' . implode('/', array_map('rawurlencode', explode('/', $path))),
            ]);

            return $target;
        }

        $stream = $source->readStream($path);

        try {
            $this->put($archive, $target, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $target;
    }

    /**
     * Move an object into the private archive: it stops being public (or
     * listed) but is never lost. Copy first, delete only once the copy exists.
     */
    public function archive(string $diskName, string $path): void
    {
        if ($this->archiveCopy($diskName, $path) === null) {
            return;
        }

        if ($this->disk($diskName)->delete($path) === false) {
            throw new RuntimeException("MinIO refused to remove {$path} after archiving it.");
        }
    }

    /**
     * Every object in a bucket, newest first.
     *
     * @return array<int, array{path: string, size: int, modified: int}>
     */
    public function listFiles(string $diskName): array
    {
        $files = [];

        // One listing with sizes and dates included: no request per object.
        foreach ($this->disk($diskName)->getDriver()->listContents('', true) as $item) {
            if ($item instanceof FileAttributes) {
                $files[] = [
                    'path' => $item->path(),
                    'size' => (int) $item->fileSize(),
                    'modified' => (int) $item->lastModified(),
                ];
            }
        }

        usort($files, fn ($a, $b) => $b['modified'] <=> $a['modified'] ?: strcmp($a['path'], $b['path']));

        return $files;
    }

    public function delete(string $diskName, string $path): void
    {
        if ($this->disk($diskName)->delete($path) === false) {
            throw new RuntimeException("MinIO refused to delete {$path}.");
        }
    }

    /**
     * True only for an object (not a folder prefix).
     */
    public function fileExists(string $diskName, string $path): bool
    {
        return $this->disk($diskName)->fileExists($path);
    }

    /**
     * A download response for an object in a private bucket, with its type
     * and size set.
     */
    public function download(string $diskName, string $path): StreamedResponse
    {
        return $this->disk($diskName)->download($path, basename($path));
    }

    /**
     * The public address of an object, as the homepage and members see it.
     */
    public function publicUrl(string $diskName, string $path): string
    {
        $bucket = config("vatssa-website.buckets.{$diskName}");
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        return config('vatssa-website.files_url') . "/{$bucket}/{$encoded}";
    }

    public function jsonUrl(string $page): string
    {
        return $this->publicUrl(self::HOMEPAGE_DISK, $this->jsonPath($page));
    }

    /**
     * @return array<int, string>
     */
    public function pageUrls(string $page): array
    {
        return array_map(
            fn (string $path) => config('vatssa-website.homepage_url') . $path,
            config("vatssa-website.pages.{$page}.pages", []),
        );
    }

    /**
     * Ask Cloudflare to drop its cached copies.
     *
     * Never throws: the save already happened. Returns null on success, or a
     * sentence for the editor saying why the cache was not cleared.
     *
     * @param  array<int, string>  $urls
     */
    public function purge(array $urls): ?string
    {
        $zone = config('vatssa-website.cloudflare.zone_id');
        $token = config('vatssa-website.cloudflare.api_token');

        if (empty($zone) || empty($token)) {
            return 'Cloudflare purging is not set up, so a cached copy may show for a while.';
        }

        $body = config('vatssa-website.cloudflare.purge_everything')
            ? ['purge_everything' => true]
            // Cloudflare takes at most 30 URLs per call on every plan.
            : ['files' => array_slice(array_values(array_unique($urls)), 0, 30)];

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(10)
                ->post('https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone) . '/purge_cache', $body);

            if ($response->successful() && $response->json('success') === true) {
                return null;
            }

            Log::warning('VATSSA website: Cloudflare purge refused', ['status' => $response->status(), 'errors' => $response->json('errors')]);
        } catch (Throwable $e) {
            Log::warning('VATSSA website: Cloudflare purge failed', ['message' => $e->getMessage()]);
        }

        return 'Saved, but Cloudflare did not clear its cache. The change may take a while to show. Tech has the details in the log.';
    }

    private function jsonPath(string $page): string
    {
        $path = config("vatssa-website.pages.{$page}.json");
        if (! is_string($path) || $path === '') {
            throw new RuntimeException("Unknown website page '{$page}'.");
        }

        return $path;
    }

    private function stamp(): string
    {
        // Random suffix: two writes in the same second keep both copies.
        return now()->utc()->format('Ymd-His') . '-' . Str::lower(Str::random(4));
    }

    private function version(?string $raw): string
    {
        return $raw === null ? 'missing' : hash('sha256', $raw);
    }

    private function disk(string $name): Filesystem
    {
        return Storage::disk($name);
    }

    /**
     * @param  string|resource  $contents
     */
    private function put(Filesystem $disk, string $path, $contents): void
    {
        // The disks are registered with 'throw' => true, so a refused write
        // raises. The false check covers a disk configured without it.
        if ($disk->put($path, $contents) === false) {
            throw new RuntimeException("MinIO refused to store {$path}.");
        }
    }
}
