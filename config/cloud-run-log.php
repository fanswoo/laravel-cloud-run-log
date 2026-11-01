<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Cloud Project
    |--------------------------------------------------------------------------
    |
    | The ID of the Google Cloud project that runs the application. Cloud
    | Logging files an entry under the trace of its request only when the
    | entry names the trace as projects/<project>/traces/<trace>, so no record
    | names a trace while this is null. Cloud Run does not set it: set
    | CLOUD_RUN_LOG_PROJECT on the service, or GOOGLE_CLOUD_PROJECT, which the
    | Google client libraries also read as their default project.
    |
    */

    'project' => env('CLOUD_RUN_LOG_PROJECT', env('GOOGLE_CLOUD_PROJECT')),

    /*
    |--------------------------------------------------------------------------
    | Minimum Level
    |--------------------------------------------------------------------------
    |
    | The lowest level that the `cloud-run` channel writes.
    |
    */

    'level' => env('CLOUD_RUN_LOG_LEVEL', env('LOG_LEVEL', 'debug')),

    /*
    |--------------------------------------------------------------------------
    | Stream
    |--------------------------------------------------------------------------
    |
    | Where the `cloud-run` channel writes its lines. Cloud Run reads both
    | standard output and standard error of the container.
    |
    */

    'stream' => env('CLOUD_RUN_LOG_STREAM', 'php://stderr'),

];
