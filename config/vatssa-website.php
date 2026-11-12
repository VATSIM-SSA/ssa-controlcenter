<?php

// VATSSA: public website content edited from CC (2026-11-12).
// CC writes the JSON the homepage reads, and the files it links to, straight
// into MinIO, then asks Cloudflare to drop its cached copies. See PATCHES.md,
// "Website content".

return [

    // MinIO, reached inside Docker. Never files.vatssa.com: Cloudflare blocks
    // writes there on purpose (MinIO CVE stop-gap).
    'minio' => [
        'endpoint' => env('VATSSA_MINIO_ENDPOINT', 'http://minio:9000'),
        'key' => env('VATSSA_MINIO_KEY'),
        'secret' => env('VATSSA_MINIO_SECRET'),
        'region' => env('VATSSA_MINIO_REGION', 'us-east-1'),
    ],

    // Disk name => bucket. VatssaServiceProvider registers each as a filesystem disk.
    // Public-read buckets: homepage-data, policy, meeting-minutes, documents.
    // Private: staff-shared, website-archive.
    'buckets' => [
        'vatssa-homepage' => 'homepage-data',
        'vatssa-policy' => 'policy',
        'vatssa-minutes' => 'meeting-minutes',
        'vatssa-documents' => 'documents',
        'vatssa-staff-shared' => 'staff-shared',
        'vatssa-archive' => 'website-archive',
    ],

    // Public addresses, used for the links written into the JSON and for purging.
    'files_url' => rtrim(env('VATSSA_FILES_URL', 'https://files.vatssa.com'), '/'),
    'homepage_url' => rtrim(env('VATSSA_HOMEPAGE_URL', 'https://vatssa.com'), '/'),

    // A token with only Zone > Cache Purge on vatssa.com. Without both values
    // nothing is purged and the editor is told so.
    'cloudflare' => [
        'zone_id' => env('VATSSA_CLOUDFLARE_ZONE_ID'),
        'api_token' => env('VATSSA_CLOUDFLARE_PURGE_TOKEN'),
        // false: purge only the changed files and the pages showing them.
        // true: purge the whole zone (Moodle, CC, News too) on every save.
        'purge_everything' => (bool) env('VATSSA_CLOUDFLARE_PURGE_EVERYTHING', false),
    ],

    // One entry per page. The permission is checked as a GLOBAL permission.
    // 'json' is the object in the homepage-data bucket; 'pages' are homepage
    // paths purged after a save.
    'pages' => [
        'screenshot' => [
            'title' => 'Screenshot winner',
            'permission' => 'website.screenshot.manage',
            'json' => 'screenshot-winner.json',
            'pages' => ['/'],
        ],
        'staff' => [
            'title' => 'Staff list',
            'permission' => 'website.staff.manage',
            'json' => 'stafflist.json',
            'pages' => ['/about/staff-team'],
        ],
        'rvas' => [
            'title' => 'Partner rVAs',
            'permission' => 'website.rvas.manage',
            'json' => 'rva.json',
            'pages' => ['/about/partner-rvas'],
        ],
        'policies' => [
            'title' => 'Policies',
            'permission' => 'website.policies.manage',
            'json' => 'policies.json',
            'pages' => ['/about/policies'],
            'disk' => 'vatssa-policy',
            'list_key' => 'policies',
        ],
        'minutes' => [
            'title' => 'Meeting minutes',
            'permission' => 'website.minutes.manage',
            'json' => 'minutes.json',
            'pages' => ['/about/meeting-minutes'],
            'disk' => 'vatssa-minutes',
            'list_key' => 'minutes',
        ],
    ],

    // Plain file areas: a bucket listed as files, no JSON. 'public' areas give
    // each file a files.vatssa.com link; private ones download through CC.
    'files' => [
        'documents' => [
            'title' => 'Other files',
            'permission' => 'website.documents.manage',
            'disk' => 'vatssa-documents',
            'public' => true,
        ],
        'staff-files' => [
            'title' => 'Staff files',
            'permission' => 'website.staff-files.manage',
            'disk' => 'vatssa-staff-shared',
            'public' => false,
        ],
    ],

    // Types and size allowed in the file areas. No HTML or SVG: both run
    // scripts when opened from files.vatssa.com.
    'file_types' => ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'docx', 'xlsx', 'pptx', 'txt', 'csv', 'zip'],
    'file_max_kb' => 20480,

    // The private archive: every replaced or archived file and every earlier
    // JSON. Viewing it, downloading from it and deleting for good need this.
    'archive' => [
        'title' => 'Archive',
        'permission' => 'website.archive.manage',
    ],
];
