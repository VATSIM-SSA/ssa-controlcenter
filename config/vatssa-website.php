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
    'buckets' => [
        'vatssa-homepage' => 'homepage-data',
        'vatssa-policy' => 'policy',
        'vatssa-minutes' => 'meeting-minutes',
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

    // Earlier versions of each JSON are copied here (in homepage-data) before
    // every save, so a bad save can be put back by hand.
    'history_prefix' => '_history/',
];
