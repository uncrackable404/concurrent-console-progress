<?php

namespace Uncrackable404\ConcurrentConsoleProgress\Exceptions;

use RuntimeException;
use Throwable;
use Uncrackable404\ConcurrentConsoleProgress\Support\Value;

class ChildProcessException extends RuntimeException
{
    public function __construct(private array $snapshot)
    {
        parent::__construct(
            message: $this->formatMessage($snapshot),
            code: (int) ($snapshot['code'] ?? 0),
        );
    }

    public static function fromSnapshot(array $snapshot): self
    {
        return new self($snapshot);
    }

    public static function fromResult(array $result): self
    {
        $snapshot = is_array($result['exception'] ?? null) ? $result['exception'] : [
            'queue' => $result['queue'] ?? null,
            'error_context' => $result['error_context'] ?? null,
            'class' => $result['error_class'] ?? null,
            'message' => $result['error'] ?? 'Child process failed.',
            'file' => $result['error_file'] ?? null,
            'line' => $result['error_line'] ?? null,
            'trace' => [],
        ];

        return self::fromSnapshot($snapshot);
    }

    public static function snapshotException(array $task, Throwable $exception): array
    {
        $errorLocation = self::resolveErrorLocation($exception);

        return [
            'queue' => $task['queue'] ?? null,
            'error_context' => $task['error_context'] ?? null,
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $errorLocation['file'],
            'line' => $errorLocation['line'],
            'trace' => self::traceLines($exception),
        ];
    }

    public function snapshot(): array
    {
        return $this->snapshot;
    }

    private static function resolveErrorLocation(Throwable $exception): array
    {
        foreach ($exception->getTrace() as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;

            if (is_string($file) === false || is_int($line) === false) {
                continue;
            }

            if (str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            return [
                'file' => $file,
                'line' => $line,
            ];
        }

        return [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }

    private static function traceLines(Throwable $exception, int $limit = 5): array
    {
        $lines = [];
        $trace = $exception->getTrace();

        foreach ($trace as $frame) {
            $formatted = self::formatTraceFrame($frame);

            if ($formatted === null) {
                continue;
            }

            $lines[] = '#' . count($lines) . ' ' . $formatted;

            if (count($lines) >= $limit) {
                return $lines;
            }
        }

        if ($lines === []) {
            $lines[] = '#0 ' . $exception->getFile() . ':' . $exception->getLine();
        }

        return $lines;
    }

    private static function formatTraceFrame(array $frame): ?string
    {
        $file = $frame['file'] ?? null;
        $line = $frame['line'] ?? null;

        if (is_string($file) === false || is_int($line) === false) {
            return null;
        }

        if (str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $call = '';
        $class = $frame['class'] ?? null;
        $type = $frame['type'] ?? null;
        $function = $frame['function'] ?? null;

        if (is_string($function) && $function !== '') {
            $call = is_string($class) ? $class . ($type ?? '::') . $function : $function;
        }

        return trim($file . ':' . $line . ($call !== '' ? ' ' . $call : ''));
    }

    private function formatMessage(array $snapshot): string
    {
        $lines = [];
        $context = array_filter([
            $snapshot['queue'] ?? null,
            $snapshot['error_context'] ?? null,
        ], fn (mixed $value): bool => Value::filled($value));

        if ($context !== []) {
            $lines[] = implode(' ', array_map(fn (mixed $value): string => (string) $value, $context));
        }

        if (Value::filled($snapshot['class'] ?? null)) {
            $lines[] = (string) $snapshot['class'];
        }

        if (Value::filled($snapshot['message'] ?? null)) {
            $lines[] = $this->normalizeMessage((string) $snapshot['message']);
        }

        if (Value::filled($snapshot['file'] ?? null) && Value::filled($snapshot['line'] ?? null)) {
            $lines[] = 'at ' . (string) $snapshot['file'] . ':' . (int) $snapshot['line'];
        }

        $trace = array_values(array_filter(
            is_array($snapshot['trace'] ?? null) ? $snapshot['trace'] : [],
            fn (mixed $line): bool => is_string($line) && $line !== '',
        ));

        if ($trace !== []) {
            $lines[] = 'Trace:';

            foreach ($trace as $line) {
                $lines[] = $line;
            }
        }

        return implode(PHP_EOL, array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    private function normalizeMessage(string $message): string
    {
        $message = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);

        return mb_strimwidth($message, 0, 400, '... (truncated)');
    }
}
