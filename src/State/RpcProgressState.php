<?php

namespace Uncrackable404\ConcurrentConsoleProgress\State;

use Closure;
use RuntimeException;
use Uncrackable404\ConcurrentConsoleProgress\ProgressState;

class RpcProgressState implements ProgressState
{
    private const TRANSFORM_RETRY_LIMIT = 128;

    private const TRANSFORM_BACKOFF_AFTER = 16;

    private mixed $socket = null;

    private int $requestId = 0;

    public function __construct(
        private string $socketPath,
        private int $parentPid,
    ) {}

    public function __destruct()
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    public function get(string $key, ?string $queue = null): mixed
    {
        $response = $this->rpc([
            'op' => 'get',
            'key' => $key,
            'queue' => $queue,
        ]);

        return $response['value'] ?? null;
    }

    public function set(string $key, mixed $value, ?string $queue = null): void
    {
        $this->rpc([
            'op' => 'set',
            'key' => $key,
            'value' => $value,
            'queue' => $queue,
        ]);
    }

    public function compareAndSet(string $key, mixed $old, mixed $new, ?string $queue = null): bool
    {
        $response = $this->rpc([
            'op' => 'cas',
            'key' => $key,
            'old' => $old,
            'new' => $new,
            'queue' => $queue,
        ]);

        return ($response['ok'] ?? false) === true;
    }

    public function transform(string $key, Closure $fn, ?string $queue = null): mixed
    {
        for ($attempt = 0; $attempt < self::TRANSFORM_RETRY_LIMIT; $attempt++) {
            $current = $this->get($key, $queue);
            $new = $fn($current);

            if ($new === $current) {
                return $new;
            }

            if ($this->compareAndSet($key, $current, $new, $queue)) {
                return $new;
            }

            if ($attempt > self::TRANSFORM_BACKOFF_AFTER) {
                usleep(min(1000, 50 * ($attempt - self::TRANSFORM_BACKOFF_AFTER)));
            }
        }

        throw new RuntimeException(sprintf(
            'RpcProgressState::transform: CAS contention limit (%d attempts) reached for key "%s"',
            self::TRANSFORM_RETRY_LIMIT,
            $key,
        ));
    }

    public function advance(string $queue, int $steps = 1): void
    {
        if ($steps === 0) {
            return;
        }

        $this->rpc([
            'op' => 'advance',
            'queue' => $queue,
            'steps' => $steps,
        ]);
    }

    public function increment(string $key, int|float $delta = 1, ?string $queue = null): int|float
    {
        return $this->transform(
            $key,
            fn (mixed $current): int|float => is_int($delta)
                ? (int) ($current ?? 0) + $delta
                : (float) ($current ?? 0) + $delta,
            $queue,
        );
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function rpc(array $request): array
    {
        $this->connect();

        $request['id'] = ++$this->requestId;

        $payload = json_encode($request, JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('RpcProgressState: failed to encode request');
        }

        $written = @fwrite($this->socket, $payload . "\n");

        if ($written === false) {
            throw new RuntimeException('RpcProgressState: failed to write request to socket');
        }

        if (function_exists('posix_kill')) {
            @posix_kill($this->parentPid, SIGUSR1);
        }

        $line = @fgets($this->socket);

        if ($line === false) {
            throw new RuntimeException('RpcProgressState: failed to read response from socket');
        }

        $response = json_decode($line, true);

        if (! is_array($response)) {
            throw new RuntimeException('RpcProgressState: malformed response: ' . $line);
        }

        if (($response['id'] ?? null) !== $request['id']) {
            throw new RuntimeException(sprintf(
                'RpcProgressState: response id mismatch (expected %d, got %s)',
                $request['id'],
                var_export($response['id'] ?? null, true),
            ));
        }

        return $response;
    }

    private function connect(): void
    {
        if (is_resource($this->socket)) {
            return;
        }

        $socket = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $errstr,
            5.0,
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf(
                'RpcProgressState: cannot connect to parent socket %s (%d: %s)',
                $this->socketPath,
                $errno,
                $errstr,
            ));
        }

        stream_set_blocking($socket, true);

        $this->socket = $socket;
    }
}
