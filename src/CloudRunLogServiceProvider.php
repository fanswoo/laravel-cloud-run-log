<?php

namespace Fanswoo\CloudRunLog;

use Illuminate\Support\ServiceProvider;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Registers the `cloud-run` log channel, so that `LOG_CHANNEL=cloud-run`
 * writes each record as one Cloud Logging entry.
 */
final class CloudRunLogServiceProvider extends ServiceProvider
{
    /** The name of the log channel that the package registers. */
    public const string CHANNEL = 'cloud-run';

    /**
     * Register the package configuration and the `cloud-run` channel. An
     * application that defines a channel of that name in its own
     * `config/logging.php` keeps its own; a key that holds null defines none.
     * A cached configuration already holds the channel, as it holds every
     * merged package configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cloud-run-log.php', 'cloud-run-log');

        if ($this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $channel = 'logging.channels.'.self::CHANNEL;

        if ($config->get($channel) === null) {
            $config->set($channel, self::channel($config->get('cloud-run-log')));
        }
    }

    /**
     * Offer the configuration file for publishing.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/cloud-run-log.php' => $this->app->configPath('cloud-run-log.php'),
        ], ['cloud-run-log', 'cloud-run-log-config']);
    }

    /**
     * The configuration of the `cloud-run` channel. An application that
     * defines the channel itself can build it here and change a part.
     *
     * @param  array{project?: string|null, level?: string, stream?: string}  $options
     * @return array<string, mixed>
     */
    public static function channel(array $options = []): array
    {
        return [
            'driver' => 'monolog',
            'level' => $options['level'] ?? 'debug',
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => $options['stream'] ?? 'php://stderr',
            ],
            'formatter' => CloudLoggingFormatter::class,
            'processors' => [
                PsrLogMessageProcessor::class,
                [
                    'processor' => AddTheTraceOfTheRequest::class,
                    'with' => ['project' => $options['project'] ?? null],
                ],
            ],
        ];
    }
}
