<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
         * Fiscal documents are private by design. Locally, files are stored
         * outside the web root. In production, set FISCAL_DOCUMENTS_DRIVER=s3
         * and the FISCAL_DOCUMENTS_AWS_* variables to use a private bucket.
         */
        'fiscal-documents' => [
            'driver' => env('FISCAL_DOCUMENTS_DRIVER', 'local'),
            'root' => env('FISCAL_DOCUMENTS_ROOT', storage_path('app/private/fiscal-documents')),
            'key' => env('FISCAL_DOCUMENTS_AWS_ACCESS_KEY_ID'),
            'secret' => env('FISCAL_DOCUMENTS_AWS_SECRET_ACCESS_KEY'),
            'region' => env('FISCAL_DOCUMENTS_AWS_DEFAULT_REGION'),
            'bucket' => env('FISCAL_DOCUMENTS_AWS_BUCKET'),
            'endpoint' => env('FISCAL_DOCUMENTS_AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('FISCAL_DOCUMENTS_AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        /*
         * SAT e-signature material (.cer / .key). Kept on its own disk so the
         * bucket, IAM policy and lifecycle rules for signing credentials can be
         * tightened independently of generated documents. Never public.
         */
        'fiscal-credentials' => [
            'driver' => env('FISCAL_CREDENTIALS_DRIVER', 'local'),
            'root' => env('FISCAL_CREDENTIALS_ROOT', storage_path('app/private/fiscal-credentials')),
            'key' => env('FISCAL_CREDENTIALS_AWS_ACCESS_KEY_ID'),
            'secret' => env('FISCAL_CREDENTIALS_AWS_SECRET_ACCESS_KEY'),
            'region' => env('FISCAL_CREDENTIALS_AWS_DEFAULT_REGION'),
            'bucket' => env('FISCAL_CREDENTIALS_AWS_BUCKET'),
            'endpoint' => env('FISCAL_CREDENTIALS_AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('FISCAL_CREDENTIALS_AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
