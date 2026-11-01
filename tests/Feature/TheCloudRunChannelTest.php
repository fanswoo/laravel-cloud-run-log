<?php

use Fanswoo\CloudRunLog\AddTheTraceOfTheRequest;
use Fanswoo\CloudRunLog\CloudLoggingFormatter;
use Fanswoo\CloudRunLog\CloudRunLogServiceProvider;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/*
 * The package registers the `cloud-run` channel, so `LOG_CHANNEL=cloud-run`
 * is the only setting an application needs.
 */

/**
 * The package configuration file as it reads under the given environment
 * variables; a null value reads as a variable that is not set.
 *
 * @param  array<string, string|null>  $environment
 * @return array<string, mixed>
 */
function cloudRunLogConfigUnder(array $environment): array
{
    $originals = [];

    foreach ($environment as $name => $value) {
        $originals[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);

        if ($value !== null) {
            $_SERVER[$name] = $value;
        }
    }

    try {
        return require __DIR__.'/../../config/cloud-run-log.php';
    } finally {
        foreach ($originals as $name => [$server, $env, $process]) {
            unset($_SERVER[$name], $_ENV[$name]);

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }

            if ($env !== null) {
                $_ENV[$name] = $env;
            }

            $process === false ? putenv($name) : putenv("{$name}={$process}");
        }
    }
}

/**
 * Registers the package again, as a new process does, with the given package
 * configuration and no `cloud-run` channel defined by the application.
 *
 * @param  array<string, mixed>  $options
 */
function registerTheCloudRunLogPackageWith(array $options): void
{
    $channels = config('logging.channels');
    unset($channels['cloud-run']);
    config(['logging.channels' => $channels, 'cloud-run-log' => $options]);

    (new CloudRunLogServiceProvider(app()))->register();
}

it('writes through the cloud-run channel once the application names it as its log channel', function () {
    $log = sendTheCloudRunLogToAFile();
    config(['logging.default' => 'cloud-run']);

    Log::info('Imported');

    expect(cloudRunLogEntries($log))->toHaveCount(1)
        ->and(cloudRunLogEntries($log)[0])->toMatchArray(['severity' => 'INFO', 'message' => 'Imported']);
});

it('builds the channel from the package configuration', function () {
    registerTheCloudRunLogPackageWith(['project' => 'acme-prod', 'level' => 'info', 'stream' => 'php://stdout']);

    expect(config('logging.channels.cloud-run'))->toBe([
        'driver' => 'monolog',
        'level' => 'info',
        'handler' => StreamHandler::class,
        'handler_with' => ['stream' => 'php://stdout'],
        'formatter' => CloudLoggingFormatter::class,
        'processors' => [
            PsrLogMessageProcessor::class,
            ['processor' => AddTheTraceOfTheRequest::class, 'with' => ['project' => 'acme-prod']],
        ],
    ]);
});

it('keeps a cloud-run channel that the application defines itself', function () {
    $ownChannel = ['driver' => 'single', 'path' => '/tmp/own.log'];
    config(['logging.channels.cloud-run' => $ownChannel]);

    (new CloudRunLogServiceProvider(app()))->register();

    expect(config('logging.channels.cloud-run'))->toBe($ownChannel);
});

it('registers the channel when the application\'s key for it holds null', function () {
    config(['logging.channels.cloud-run' => null]);

    (new CloudRunLogServiceProvider(app()))->register();

    expect(config('logging.channels.cloud-run.formatter'))->toBe(CloudLoggingFormatter::class);
});

it('reads the project from CLOUD_RUN_LOG_PROJECT, then from GOOGLE_CLOUD_PROJECT', function (?string $packageProject, ?string $googleProject, ?string $project) {
    expect(cloudRunLogConfigUnder([
        'CLOUD_RUN_LOG_PROJECT' => $packageProject,
        'GOOGLE_CLOUD_PROJECT' => $googleProject,
    ])['project'])->toBe($project);
})->with([
    'both' => ['acme-logs', 'acme-default', 'acme-logs'],
    'only GOOGLE_CLOUD_PROJECT' => [null, 'acme-default', 'acme-default'],
    'neither' => [null, null, null],
]);

it('writes from the level that CLOUD_RUN_LOG_LEVEL, then LOG_LEVEL, names, to standard error', function () {
    expect(cloudRunLogConfigUnder(['CLOUD_RUN_LOG_LEVEL' => 'warning', 'LOG_LEVEL' => 'info']))->toMatchArray(['level' => 'warning', 'stream' => 'php://stderr'])
        ->and(cloudRunLogConfigUnder(['CLOUD_RUN_LOG_LEVEL' => null, 'LOG_LEVEL' => 'info'])['level'])->toBe('info')
        ->and(cloudRunLogConfigUnder(['CLOUD_RUN_LOG_LEVEL' => null, 'LOG_LEVEL' => null])['level'])->toBe('debug');
});
