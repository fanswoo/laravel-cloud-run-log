# Changelog

All notable changes to this package are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The `cloud-run` log channel, which the service provider registers unless the application defines a channel of that name. `CloudRunLogServiceProvider::channel()` builds its configuration for an application that defines the channel itself.
- `CloudLoggingFormatter`: writes each record as one JSON line with `severity`, `time` and `message`, keeps the stack trace of an exception inside the entry, and writes every extra named with the `logging.googleapis.com/` prefix as a field of the entry. A record at the `error` level or above that carries an exception writes its stack trace in `stack_trace`, in the format that Error Reporting reads for PHP, and its context names the exception without the list of frames.
- `AddTheTraceOfTheRequest`: names the trace of the current request, from `X-Cloud-Trace-Context` or, by the rules of W3C Trace Context, `traceparent`, in `logging.googleapis.com/trace`.
- `PhpStackTrace`: the text of an exception and its chain as PHP writes it, without the arguments of any frame.
- The `cloud-run-log.php` configuration file, with `project` (`CLOUD_RUN_LOG_PROJECT`, then `GOOGLE_CLOUD_PROJECT`), `level` (`CLOUD_RUN_LOG_LEVEL`, then `LOG_LEVEL`) and `stream` (`CLOUD_RUN_LOG_STREAM`).
- Support for PHP 8.3 to 8.5, Laravel 12 and 13, and Monolog 3.6 or later.
