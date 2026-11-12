<?php

namespace App\Http\Controllers;

use App\Helpers\LogName;
use App\Services\ActivityLogService;
use App\Services\VatssaWebsiteConflict;
use App\Services\VatssaWebsiteStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * VATSSA: staff edit the public website's content from CC.
 *
 * Each page has its own permission, always checked as a GLOBAL permission:
 * the website has no areas. Every save writes the JSON the homepage reads into
 * MinIO, keeps the previous copy, logs the change and purges Cloudflare.
 */
class VatssaWebsiteController extends Controller
{
    private const BLANK_ROWS = 5;

    public function __construct(private VatssaWebsiteStore $store) {}

    public function index(Request $request): View
    {
        $pages = self::pagesFor($request->user());
        abort_if($pages === [], 403);

        return view('vatssa.website.index', ['pages' => $pages]);
    }

    /**
     * The pages a user may edit, keyed by page, in config order.
     *
     * @return array<string, string> page key => title
     */
    public static function pagesFor($user): array
    {
        $pages = [];

        if ($user === null) {
            return $pages;
        }

        foreach (config('vatssa-website.pages', []) as $key => $page) {
            if ($user->hasGlobalPermission($page['permission'])) {
                $pages[$key] = $page['title'];
            }
        }

        return $pages;
    }

    // ---------------------------------------------------------------------
    // Screenshot winner
    // ---------------------------------------------------------------------

    public function screenshot(Request $request): View
    {
        $this->authorizePage($request, 'screenshot');
        $current = $this->read('screenshot');

        return view('vatssa.website.screenshot', [
            'winner' => $this->winners($current['data'])[0] ?? null,
            'version' => $current['version'],
            'unreadable' => $current['unreadable'],
        ]);
    }

    public function saveScreenshot(Request $request): RedirectResponse
    {
        $this->authorizePage($request, 'screenshot');

        $input = $request->validate([
            'name' => 'required|string|max:80',
            'cid' => 'required|digits_between:3,8',
            'month' => ['required', 'string', 'regex:/^[A-Z][a-z]{2,4}[0-9]{2}$/'],
            'image' => 'required|file|mimes:jpg,jpeg,png,webp|max:8192|dimensions:min_width=1920',
            'version' => 'required|string',
        ], [
            'month.regex' => 'Write the month like Nov26 or Sept26.',
            'image.dimensions' => 'The picture must be at least 1920 pixels wide, or it looks blurry across the whole screen.',
        ]);

        return $this->saving('screenshot', $input['version'], function () use ($input, $request) {
            $image = $request->file('image');
            $extension = $image->extension() === 'jpeg' ? 'jpg' : $image->extension();

            // A new name every time: Cloudflare and browsers keep an old picture
            // under an old name.
            $path = 'screenshot-comp/' . Str::lower($input['month']) . '-' . Str::lower(Str::random(6)) . '.' . $extension;
            $this->store->putFile(VatssaWebsiteStore::HOMEPAGE_DISK, $path, $image);
            $link = $this->store->publicUrl(VatssaWebsiteStore::HOMEPAGE_DISK, $path);

            // Newest first; the homepage shows only the first. Earlier winners
            // are kept, but only well-formed ones: one bad entry makes the
            // homepage reject the whole file.
            $winners = array_slice($this->winners($this->read('screenshot')['data']), 0, 11);
            array_unshift($winners, [
                'name' => $input['name'] . ' - ' . $input['cid'],
                'link' => $link,
                'month' => $input['month'],
            ]);

            $this->store->write('screenshot', ['data' => $winners], $input['version']);

            return ['Screenshot winner set to ' . $input['name'] . ' (' . $input['month'] . ')', [$link]];
        });
    }

    // ---------------------------------------------------------------------
    // Staff list
    // ---------------------------------------------------------------------

    public function staff(Request $request): View
    {
        $this->authorizePage($request, 'staff');
        $current = $this->read('staff');

        $rows = [];
        foreach ($this->staffGroups($current['data']) as $team => $members) {
            foreach ($members as $member) {
                if (! is_array($member)) {
                    continue;
                }
                $rows[] = [
                    'team' => (string) $team,
                    'id' => $this->text($member['id'] ?? ''),
                    'name' => $this->text($member['name'] ?? ''),
                    'role' => $this->text($member['role'] ?? ''),
                    'email' => $this->text($member['email'] ?? ''),
                    'cid' => $this->text($member['cid'] ?? ''),
                ];
            }
        }

        return view('vatssa.website.staff', [
            'rows' => $this->withOrderAndBlanks($rows, ['team' => '', 'id' => '', 'name' => '', 'role' => '', 'email' => '', 'cid' => '']),
            'version' => $current['version'],
            'unreadable' => $current['unreadable'],
        ]);
    }

    public function saveStaff(Request $request): RedirectResponse
    {
        $this->authorizePage($request, 'staff');

        $request->validate(['version' => 'required|string', 'rows' => 'required|array|max:300']);
        $rows = $this->keptRows($request->input('rows'), ['team', 'name', 'role', 'email', 'cid']);

        $this->validateRows($rows, [
            'team' => 'required|string|max:60',
            'name' => 'required|string|max:80',
            'role' => 'required|string|max:80',
            'email' => 'nullable|email|max:120',
            'cid' => 'nullable|string|max:40',
            // Ids name photo files; existing ones may use capitals or dots.
            'id' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
            'order' => 'nullable|integer|min:0|max:100000',
        ], 'Nobody would be left on the staff list. Nothing was saved.');

        $version = $request->input('version');

        return $this->saving('staff', $version, function () use ($rows, $version) {
            $groups = [];
            $usedIds = array_values(array_filter(array_map(fn ($row) => $row['id'] ?? null, $rows)));
            foreach ($this->sorted($rows) as $row) {
                if (! $this->filled($row['id'] ?? null)) {
                    $row['id'] = $this->staffId($row['name'], $usedIds);
                    $usedIds[] = $row['id'];
                }
                $member = [
                    // The id names the photo in the homepage repo, so an existing
                    // one is kept as it is, even when the name changes.
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'role' => $row['role'],
                ];
                if ($this->filled($row['email'] ?? null)) {
                    $member['email'] = $row['email'];
                }
                if ($this->filled($row['cid'] ?? null)) {
                    $member['cid'] = $row['cid'];
                }
                $groups[$row['team']][] = $member;
            }

            // Keep the envelope the file already uses.
            $current = $this->read('staff')['data'];
            // An object, never a list, even if a team were named "0".
            $groups = (object) $groups;
            $payload = is_array($current) && isset($current['data']) && is_array($current['data'])
                ? ['data' => $groups]
                : $groups;

            $this->store->write('staff', $payload, $version);

            return ['Staff list saved (' . count($rows) . ' entries)', []];
        });
    }

    // ---------------------------------------------------------------------
    // Partner rVAs
    // ---------------------------------------------------------------------

    public function rvas(Request $request): View
    {
        $this->authorizePage($request, 'rvas');
        $current = $this->read('rvas');

        $rows = [];
        foreach ($this->dataList($current['data']) as $rva) {
            if (! is_array($rva)) {
                continue;
            }
            $rows[] = [
                'name' => $this->text($rva['name'] ?? ''),
                'site' => $this->text($rva['site'] ?? ''),
                'signup' => $this->text($rva['signup'] ?? ''),
                'tier' => $this->text($rva['tier'] ?? ''),
                'description' => $this->text($rva['description'] ?? ''),
            ];
        }

        return view('vatssa.website.rvas', [
            'rows' => $this->withOrderAndBlanks($rows, ['name' => '', 'site' => '', 'signup' => '', 'tier' => '', 'description' => '']),
            'version' => $current['version'],
            'unreadable' => $current['unreadable'],
        ]);
    }

    public function saveRvas(Request $request): RedirectResponse
    {
        $this->authorizePage($request, 'rvas');

        $request->validate(['version' => 'required|string', 'rows' => 'required|array|max:100']);
        $rows = $this->keptRows($request->input('rows'), ['name', 'site', 'signup', 'tier', 'description']);

        $this->validateRows($rows, [
            'name' => 'required|string|max:80',
            'site' => 'required|url:https|max:255',
            'signup' => 'nullable|url:https|max:500',
            'tier' => 'required|in:1,2',
            'description' => 'nullable|string|max:500',
            'order' => 'nullable|integer|min:0|max:100000',
        ], 'No airline would be left on the list. Nothing was saved.');

        $version = $request->input('version');

        return $this->saving('rvas', $version, function () use ($rows, $version) {
            $airlines = [];
            foreach ($this->sorted($rows) as $row) {
                $airline = ['name' => $row['name'], 'site' => $row['site']];
                if ($this->filled($row['signup'] ?? null)) {
                    $airline['signup'] = $row['signup'];
                }
                $airline['tier'] = (int) $row['tier'];
                if ($this->filled($row['description'] ?? null)) {
                    $airline['description'] = $row['description'];
                }
                $airlines[] = $airline;
            }

            $this->store->write('rvas', ['data' => $airlines], $version);

            return ['Partner rVA list saved (' . count($airlines) . ' airlines)', []];
        });
    }

    // ---------------------------------------------------------------------
    // Policies and meeting minutes: a PDF per entry
    // ---------------------------------------------------------------------

    public function documents(Request $request, string $page): View
    {
        $this->authorizePage($request, $page);
        $current = $this->read($page);

        $rows = [];
        foreach ($this->documentList($page, $current['data']) as $document) {
            $rows[] = [
                'id' => $this->text($document['id'] ?? ''),
                'title' => $this->text($document['title'] ?? ''),
                'description' => $this->text($document['description'] ?? ''),
                'url' => $this->text($document['url'] ?? ''),
            ];
        }

        return view('vatssa.website.documents', [
            'page' => $page,
            'title' => config("vatssa-website.pages.{$page}.title"),
            'rows' => $this->withOrderAndBlanks($rows, ['id' => '', 'title' => '', 'description' => '', 'url' => ''], 3),
            'version' => $current['version'],
            'unreadable' => $current['unreadable'],
        ]);
    }

    public function saveDocuments(Request $request, string $page): RedirectResponse
    {
        $this->authorizePage($request, $page);

        $request->validate(['version' => 'required|string', 'rows' => 'required|array|max:200']);

        $diskName = config("vatssa-website.pages.{$page}.disk");
        $existing = [];
        foreach ($this->documentList($page, $this->read($page)['data']) as $document) {
            if (is_string($document['id'] ?? null)) {
                // A hand-edited entry may lack a usable url; '' makes it
                // unreplaceable rather than a crash.
                $document['url'] = is_string($document['url'] ?? null) ? $document['url'] : '';
                $existing[$document['id']] = $document;
            }
        }

        $files = $request->file('rows', []);
        $input = (array) $request->input('rows', []);
        foreach ($input as $key => $row) {
            if (is_array($row) && isset($files[$key]['file'])) {
                $input[$key]['file'] = $files[$key]['file'];
            }
        }
        $rows = $this->keptRows($input, ['id', 'title', 'description', 'file']);

        $rules = [
            'title' => 'required|string|max:150',
            'description' => 'required|string|max:300',
            // Only ids already in the list: a form cannot point an entry at an
            // arbitrary object.
            'id' => ['nullable', 'string', 'distinct', Rule::in(array_keys($existing))],
            'order' => 'nullable|integer|min:0|max:100000',
        ];
        $fileRule = 'file|mimes:pdf|mimetypes:application/pdf|max:20480';
        $perRow = [];
        foreach ($rows as $key => $row) {
            $perRow["rows.{$key}.file"] = ($this->filled($row['id'] ?? null) ? 'nullable|' : 'required|') . $fileRule;
        }
        $this->validateRows($rows, $rules, 'The list would be empty. Nothing was saved.', $perRow);

        // New files: a clean name that is not taken yet. An existing file is
        // only ever replaced from its own row, under its own name.
        $newNames = [];
        $errors = [];
        foreach ($rows as $key => $row) {
            if ($this->filled($row['id'] ?? null)) {
                if (isset($row['file']) && $this->objectPath($diskName, $existing[$row['id']]['url'] ?? '') === null) {
                    $errors["rows.{$key}.file"] = 'This entry\'s file is not stored in CC\'s bucket, so it cannot be replaced here. Delete the entry and add it again.';
                }

                continue;
            }
            $name = $this->fileName($row['file'], $page);
            if (in_array($name, $newNames, true) || isset($existing[$name]) || $this->exists($diskName, $name)) {
                $errors["rows.{$key}.file"] = "A file called {$name} already exists. Rename the file before uploading it, or upload it on that entry's own row to replace it.";
            }
            $newNames[$key] = $name;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $version = $request->input('version');

        return $this->saving($page, $version, function () use ($page, $diskName, $rows, $existing, $newNames, $version) {
            $documents = [];
            $changedUrls = [];
            $replacements = [];

            foreach ($this->sorted($rows) as $row) {
                $key = $row['__key'];

                if ($this->filled($row['id'] ?? null)) {
                    $id = $row['id'];
                    $url = $existing[$id]['url'];
                    if (isset($row['file'])) {
                        $replacements[] = [$this->objectPath($diskName, $url), $row['file']];
                        $changedUrls[] = $url;
                    }
                } else {
                    $id = $newNames[$key];
                    $this->store->putFile($diskName, $id, $row['file']);
                    $url = $this->store->publicUrl($diskName, $id);
                }

                $documents[] = ['id' => $id, 'title' => $row['title'], 'description' => $row['description'], 'url' => $url];
            }

            $this->store->write($page, [config("vatssa-website.pages.{$page}.list_key") => $documents], $version);

            // A live file is replaced only once the JSON is saved: a conflict
            // above leaves the old PDF untouched.
            foreach ($replacements as [$path, $file]) {
                $this->store->putFile($diskName, $path, $file);
            }

            return [config("vatssa-website.pages.{$page}.title") . ' saved (' . count($documents) . ' documents)', $changedUrls];
        });
    }

    // ---------------------------------------------------------------------
    // Shared
    // ---------------------------------------------------------------------

    private function authorizePage(Request $request, string $page): void
    {
        $permission = config("vatssa-website.pages.{$page}.permission");

        abort_unless(is_string($permission) && $request->user()?->hasGlobalPermission($permission), 403);
    }

    /**
     * Run a save, then log it and purge Cloudflare. The closure returns
     * [message, changed file URLs].
     */
    private function saving(string $page, string $version, callable $save): RedirectResponse
    {
        // Checked before any upload, so a stale form leaves no orphan files.
        // write() checks again just before replacing the JSON.
        if (! hash_equals($this->read($page)['version'], $version)) {
            return back()->withInput()->withErrors(['version' => (new VatssaWebsiteConflict)->getMessage()]);
        }

        try {
            [$message, $changedUrls] = $save();
        } catch (VatssaWebsiteConflict $e) {
            return back()->withInput()->withErrors(['version' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('VATSSA website: save failed', ['page' => $page, 'message' => $e->getMessage()]);

            return back()->withInput()->withErrors(['storage' => 'Saving to file storage failed, so the website may not have changed. Tech has the details in the log.']);
        }

        ActivityLogService::info(LogName::Other, 'Website: ' . $message);

        $warning = $this->store->purge(array_merge(
            [$this->store->jsonUrl($page)],
            $changedUrls,
            $this->store->pageUrls($page),
        ));

        $redirect = redirect()->route("vatssa.website.{$page}")->withSuccess($message);

        return $warning === null ? $redirect : $redirect->with('vatssa_website_warning', $warning);
    }

    /**
     * Drop rows marked for deletion and rows left completely blank. Keys are
     * kept, so validation errors point at the right row on the form.
     *
     * @param  array<int|string, string>  $fields
     * @return array<int|string, array>
     */
    private function keptRows(mixed $rows, array $fields): array
    {
        $kept = [];

        foreach ((array) $rows as $key => $row) {
            // The form only sends numbered rows. Anything else could slip a
            // dotted key past the per-row validation rules.
            if (! is_int($key) || ! is_array($row) || ! empty($row['delete'])) {
                continue;
            }

            $blank = true;
            foreach ($fields as $field) {
                if ($this->filled($row[$field] ?? null) || ($row[$field] ?? null) instanceof UploadedFile) {
                    $blank = false;
                    break;
                }
            }

            if (! $blank) {
                $row['__key'] = $key;
                $kept[$key] = $row;
            }
        }

        return $kept;
    }

    /**
     * @param  array<int|string, array>  $rows
     * @param  array<string, mixed>  $rules  per-field rules
     * @param  array<string, mixed>  $extraRules  full keys
     */
    private function validateRows(array $rows, array $rules, string $emptyMessage, array $extraRules = []): void
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['rows' => $emptyMessage]);
        }

        $fullRules = $extraRules;
        foreach (array_keys($rows) as $key) {
            foreach ($rules as $field => $rule) {
                $fullRules["rows.{$key}.{$field}"] = $rule;
            }
        }

        // 'distinct' needs the wildcard form to compare across rows.
        foreach ($rules as $field => $rule) {
            if (is_array($rule) && in_array('distinct', $rule, true)) {
                $fullRules["rows.*.{$field}"] = ['nullable', 'distinct'];
            }
        }

        Validator::make(['rows' => $rows], $fullRules, [], $this->rowAttributes($rows, array_keys($rules)))->validate();
    }

    /**
     * "row 3 name" instead of "rows.2.name" in error messages.
     */
    private function rowAttributes(array $rows, array $fields): array
    {
        $attributes = [];
        foreach (array_keys($rows) as $key) {
            $number = is_int($key) ? $key + 1 : $key;
            foreach (array_merge($fields, ['file']) as $field) {
                $attributes["rows.{$key}.{$field}"] = "row {$number} {$field}";
            }
        }

        return $attributes;
    }

    /**
     * By the order number, then by position on the form.
     */
    private function sorted(array $rows): array
    {
        $position = 0;
        foreach ($rows as &$row) {
            $row['__position'] = $position++;
        }
        unset($row);

        usort($rows, function (array $a, array $b) {
            $orderA = $this->filled($a['order'] ?? null) ? (int) $a['order'] : PHP_INT_MAX;
            $orderB = $this->filled($b['order'] ?? null) ? (int) $b['order'] : PHP_INT_MAX;

            return [$orderA, $a['__position']] <=> [$orderB, $b['__position']];
        });

        return $rows;
    }

    private function withOrderAndBlanks(array $rows, array $blank, int $blanks = self::BLANK_ROWS): array
    {
        $order = 10;
        foreach ($rows as &$row) {
            $row['order'] = $order;
            $order += 10;
        }
        unset($row);

        for ($i = 0; $i < $blanks; $i++) {
            $rows[] = $blank + ['order' => ''];
        }

        return $rows;
    }

    private function winners(mixed $data): array
    {
        $list = is_array($data) && isset($data['data']) ? $data['data'] : $data;

        return array_values(array_filter(is_array($list) ? $list : [], fn ($winner) => is_array($winner)
            && is_string($winner['name'] ?? null)
            && is_string($winner['link'] ?? null)
            && is_string($winner['month'] ?? null)));
    }

    private function staffGroups(mixed $data): array
    {
        if (is_array($data) && array_is_list($data)) {
            $data = $data[0]['data'] ?? [];
        } elseif (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        if (! is_array($data)) {
            return [];
        }

        return array_filter($data, 'is_array');
    }

    private function dataList(mixed $data): array
    {
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }

        return is_array($data) && array_is_list($data) ? $data : [];
    }

    private function documentList(string $page, mixed $data): array
    {
        $key = config("vatssa-website.pages.{$page}.list_key");
        $list = $data[$key] ?? ($data['data'][$key] ?? []);

        return array_values(array_filter(is_array($list) ? $list : [], 'is_array'));
    }

    /**
     * The object path inside a bucket, from a public URL, or null if the URL
     * does not point into that bucket.
     */
    private function objectPath(string $diskName, string $url): ?string
    {
        $prefix = $this->store->publicUrl($diskName, '');
        if ($url === '' || ! str_starts_with($url, $prefix)) {
            return null;
        }

        $path = rawurldecode(substr($url, strlen($prefix)));

        return $path === '' || str_contains($path, '..') ? null : $path;
    }

    private function fileName(UploadedFile $file, string $page): string
    {
        $base = Str::of(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->lower()
            ->replaceMatches('/[^a-z0-9._-]+/', '_')
            ->trim('._-')
            ->limit(120, '')
            ->toString();

        return ($base === '' ? $page . '-' . now()->utc()->format('Ymd-His') : $base) . '.pdf';
    }

    /**
     * A new person's id: their name as a slug, made unique. Vacant posts all
     * share 'vacant', as they always have.
     */
    private function staffId(string $name, array $usedIds): string
    {
        if (strcasecmp(trim($name), 'VACANT') === 0) {
            return 'vacant';
        }

        $base = Str::slug($name) ?: 'member';
        $id = $base;
        for ($n = 2; in_array($id, $usedIds, true); $n++) {
            $id = "{$base}-{$n}";
        }

        return $id;
    }

    /**
     * Read a page's JSON, or stop with a clear page if MinIO cannot be reached.
     */
    private function read(string $page): array
    {
        try {
            return $this->store->read($page);
        } catch (Throwable $e) {
            Log::error('VATSSA website: read failed', ['page' => $page, 'message' => $e->getMessage()]);
            abort(503, 'File storage cannot be reached right now, so the website content cannot be edited. Nothing was changed. Tech has the details in the log.');
        }
    }

    private function exists(string $diskName, string $path): bool
    {
        try {
            return $this->store->exists($diskName, $path);
        } catch (Throwable $e) {
            Log::error('VATSSA website: exists check failed', ['path' => $path, 'message' => $e->getMessage()]);
            abort(503, 'File storage cannot be reached right now. Nothing was changed. Tech has the details in the log.');
        }
    }

    private function filled(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
