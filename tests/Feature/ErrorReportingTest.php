<?php

use Fanswoo\CloudRunLog\Tests\Support\ExceptionWithATextOfItsOwn;
use Illuminate\Support\Facades\Log;

/*
 * Error Reporting reads `stack_trace` before `message`. For PHP it takes one
 * of four prefixes followed by the exception as PHP writes it, the earliest
 * exception first and each later one after `Next`.
 */

it('hands Error Reporting the stack trace of an exception that Laravel reports', function () {
    $log = sendTheCloudRunLogToAFile();
    config(['logging.default' => 'cloud-run']);

    report(new RuntimeException('The import failed', previous: new LogicException('Row 7 is broken')));

    $entry = cloudRunLogEntries($log)[0];

    expect($entry)->toMatchArray(['severity' => 'ERROR', 'message' => 'The import failed'])
        ->and($entry['stack_trace'])->toStartWith('PHP Fatal error: LogicException: Row 7 is broken in '.__FILE__.':')
        ->toContain("\nStack trace:\n#0 ")
        ->toContain("\n\nNext RuntimeException: The import failed in ".__FILE__.':')
        ->toEndWith(' {main}');
});

it('hands Error Reporting the stack trace of every record above a warning', function (string $level) {
    $log = sendTheCloudRunLogToAFile();

    Log::channel('cloud-run')->log($level, 'The disk is full', ['exception' => new RuntimeException('No space left')]);

    expect(cloudRunLogEntries($log)[0]['stack_trace'])->toStartWith('PHP Fatal error: RuntimeException: No space left in ');
})->with(['error', 'critical', 'alert', 'emergency']);

it('keeps the stack trace of an error once, in stack_trace, and its exception\'s other details in the context', function () {
    $log = sendTheCloudRunLogToAFile();

    Log::channel('cloud-run')->error('The import failed', ['exception' => new RuntimeException('Timed out', previous: new LogicException('Row 7'))]);
    Log::channel('cloud-run')->warning('Retrying the import', ['exception' => new RuntimeException('Timed out')]);

    [$error, $warning] = cloudRunLogEntries($log);

    expect($error['context']['exception'])->toMatchArray(['class' => RuntimeException::class, 'message' => 'Timed out'])
        ->not->toHaveKey('trace')
        ->and($error['context']['exception']['previous'])->toMatchArray(['class' => LogicException::class])
        ->not->toHaveKey('trace')
        ->and($warning['context']['exception']['trace'] ?? [])->not->toBeEmpty();
});

it('hands Error Reporting nothing for a record below an error or a record with no exception', function () {
    $log = sendTheCloudRunLogToAFile();

    Log::channel('cloud-run')->warning('Retrying the import', ['exception' => new RuntimeException('Timed out')]);
    Log::channel('cloud-run')->error('The import failed', ['exception' => 'Timed out']);
    Log::channel('cloud-run')->critical('The import failed');

    expect(cloudRunLogEntries($log))->each->not->toHaveKey('stack_trace');
});

it('names no argument of any frame in the stack trace', function () {
    $log = sendTheCloudRunLogToAFile();
    $connect = function (string $password): never {
        throw new RuntimeException('The database refused the connection');
    };

    // PHP's default: an exception records the arguments of every frame.
    $ignoredArguments = ini_set('zend.exception_ignore_args', '0');

    try {
        $connect('S3cretPassword');
    } catch (RuntimeException $exception) {
        Log::channel('cloud-run')->error('The database refused the connection', ['exception' => $exception]);
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignoredArguments);
    }

    expect($exception->getTrace()[0]['args'] ?? [])->toBe(['S3cretPassword'])
        ->and(cloudRunLogEntries($log)[0]['stack_trace'])
        ->toMatch('/^#0 '.preg_quote(__FILE__, '/').'\(\d+\): .+\(\)$/m')
        ->not->toContain('S3cret');
});

it('writes the stack trace in PHP\'s own text even for an exception that writes itself otherwise', function () {
    $log = sendTheCloudRunLogToAFile();

    Log::channel('cloud-run')->error('The reply was refused', ['exception' => new ExceptionWithATextOfItsOwn('Refused')]);

    expect(cloudRunLogEntries($log)[0]['stack_trace'])
        ->toStartWith('PHP Fatal error: '.ExceptionWithATextOfItsOwn::class.': Refused in '.__FILE__.':')
        ->toContain("\nStack trace:\n#0 ");
});

it('writes the text that PHP writes for the exception, with every argument list left empty', function () {
    $log = sendTheCloudRunLogToAFile();

    // With the arguments left out, PHP still names the file of an `include`
    // frame, as a quoted string in the brackets that end the line.
    $ignoredArguments = ini_set('zend.exception_ignore_args', '1');

    try {
        $exception = (fn (): RuntimeException => new RuntimeException('outer', 3, new LogicException('inner')))();
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignoredArguments);
    }

    Log::channel('cloud-run')->error('Failed', ['exception' => $exception]);

    $phpTextWithoutArguments = preg_replace("/\\('[^'\\n]*'\\)$/m", '()', (string) $exception);

    expect(cloudRunLogEntries($log)[0]['stack_trace'])->toBe('PHP Fatal error: '.$phpTextWithoutArguments);
});

it('writes an exception without a message as PHP does', function () {
    $log = sendTheCloudRunLogToAFile();

    Log::channel('cloud-run')->error('Failed', ['exception' => new RuntimeException]);

    expect(cloudRunLogEntries($log)[0]['stack_trace'])->toStartWith('PHP Fatal error: RuntimeException in '.__FILE__.':');
});
