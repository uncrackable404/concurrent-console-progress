<?php

namespace Uncrackable404\ConcurrentConsoleProgress\State;

use Closure;
use RuntimeException;

class ParentStateServer
{
    private mixed $server = null;

    /** @var array<int, resource> */
    private array $clients = [];

    /** @var array<int, string> */
    private array $buffers = [];

    private array $rows;

    private array $global;

    private bool $changed = false;

    public function __construct(
        private string $socketPath,
        array &$rows,
        array &$global,
        private Closure $onChange,
    ) {
        $this->rows = &$rows;
        $this->global = &$global;
    }

    public function socketPath(): string
    {
        return $this->socketPath;
    }

    public function listen(): void
    {
        if ($this->server !== null) {
            return;
        }

        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }

        $server = @stream_socket_server(
            'unix://' . $this->socketPath,
            $errno,
            $errstr,
        );

        if ($server === false) {
            throw new RuntimeException(sprintf(
                'ParentStateServer: failed to listen on %s (%d: %s)',
                $this->socketPath,
                $errno,
                $errstr,
            ));
        }

        stream_set_blocking($server, false);

        $this->server = $server;
    }

    public function serverResource(): mixed
    {
        return $this->server;
    }

    public function service(): void
    {
        if ($this->server === null) {
            return;
        }

        $this->acceptClients();
        $this->processClients();

        if ($this->changed) {
            $this->changed = false;
            ($this->onChange)();
        }
    }

    public function shutdown(): void
    {
        foreach ($this->clients as $client) {
            if (is_resource($client)) {
                @fclose($client);
            }
        }
        $this->clients = [];
        $this->buffers = [];

        if (is_resource($this->server)) {
            @fclose($this->server);
        }
        $this->server = null;

        clearstatcache(true, $this->socketPath);

        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
    }

    private function acceptClients(): void
    {
        while (true) {
            $read = [$this->server];
            $write = null;
            $except = null;

            $pending = @stream_select($read, $write, $except, 0, 0);

            if ($pending !== 1) {
                return;
            }

            $client = @stream_socket_accept($this->server, 0);

            if ($client === false) {
                return;
            }

            stream_set_blocking($client, false);
            $id = (int) $client;
            $this->clients[$id] = $client;
            $this->buffers[$id] = '';
        }
    }

    private function processClients(): void
    {
        foreach ($this->clients as $id => $client) {
            $chunk = @fread($client, 8192);

            if ($chunk !== false && $chunk !== '') {
                $this->buffers[$id] .= $chunk;
            }

            while (($pos = strpos($this->buffers[$id], "\n")) !== false) {
                $line = substr($this->buffers[$id], 0, $pos);
                $this->buffers[$id] = substr($this->buffers[$id], $pos + 1);

                if ($line === '') {
                    continue;
                }

                $request = json_decode($line, true);

                if (! is_array($request) || ! isset($request['op'], $request['id'])) {
                    continue;
                }

                $response = $this->dispatch($request);

                @fwrite($client, json_encode($response, JSON_UNESCAPED_SLASHES) . "\n");
            }

            if (feof($client)) {
                @fclose($client);
                unset($this->clients[$id], $this->buffers[$id]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function dispatch(array $request): array
    {
        return match ($request['op']) {
            'get' => $this->handleGet($request),
            'set' => $this->handleSet($request),
            'cas' => $this->handleCas($request),
            'advance' => $this->handleAdvance($request),
            default => ['id' => $request['id'], 'error' => 'unknown op'],
        };
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function handleGet(array $request): array
    {
        return [
            'id' => $request['id'],
            'value' => $this->readValue((string) $request['key'], $request['queue'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function handleSet(array $request): array
    {
        $key = (string) $request['key'];
        $queue = $request['queue'] ?? null;
        $value = $request['value'] ?? null;

        $current = $this->readValue($key, $queue);

        if ($current !== $value) {
            $this->writeValue($key, $value, $queue);
            $this->changed = true;
        }

        return ['id' => $request['id'], 'ok' => true];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function handleCas(array $request): array
    {
        $key = (string) $request['key'];
        $queue = $request['queue'] ?? null;
        $old = $request['old'] ?? null;
        $new = $request['new'] ?? null;

        $current = $this->readValue($key, $queue);

        if ($current !== $old) {
            return [
                'id' => $request['id'],
                'ok' => false,
                'value' => $current,
            ];
        }

        if ($new !== $current) {
            $this->writeValue($key, $new, $queue);
            $this->changed = true;
        }

        return [
            'id' => $request['id'],
            'ok' => true,
            'value' => $new,
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function handleAdvance(array $request): array
    {
        $queue = (string) ($request['queue'] ?? '');
        $steps = (int) ($request['steps'] ?? 1);

        if ($queue === '' || ! isset($this->rows[$queue]) || $steps === 0) {
            return ['id' => $request['id'], 'ok' => true, 'value' => $this->rows[$queue]['processed'] ?? 0];
        }

        $total = (int) ($this->rows[$queue]['total'] ?? 0);
        $current = (int) ($this->rows[$queue]['processed'] ?? 0);
        $next = $total > 0 ? min($current + $steps, $total) : $current + $steps;

        if ($next !== $current) {
            $this->rows[$queue]['processed'] = $next;
            $this->changed = true;
        }

        return ['id' => $request['id'], 'ok' => true, 'value' => $next];
    }

    private function readValue(string $key, ?string $queue): mixed
    {
        if ($queue === null) {
            return $this->global[$key] ?? null;
        }

        return $this->rows[$queue]['meta'][$key] ?? null;
    }

    private function writeValue(string $key, mixed $value, ?string $queue): void
    {
        if ($queue === null) {
            $this->global[$key] = $value;

            return;
        }

        if (isset($this->rows[$queue])) {
            $this->rows[$queue]['meta'][$key] = $value;
        }
    }
}
