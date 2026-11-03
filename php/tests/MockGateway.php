<?php

declare(strict_types=1);

namespace WorldText\Tests;

/**
 * Starts the shared mock gateway as a child process on port 0, reads the real
 * port from its first stdout line, and owns its lifetime.
 *
 * Port 0 means the OS picks a free port, so parallel runs cannot collide and no
 * test ever touches the network or a real account.
 */
final class MockGateway
{
    /** @var resource|null */
    private $process = null;

    /** @var list<resource> */
    private array $pipes = [];

    private string $baseUrl = '';

    /**
     * @param string $script Absolute path to mock_gateway.py.
     * @param string $python Interpreter to run it with.
     */
    public function __construct(
        private readonly string $script,
        private readonly string $python,
    ) {
    }

    /**
     * Launch the gateway and block until it reports its port.
     *
     * @throws \RuntimeException when it exits or prints something unexpected.
     */
    public function start(float $waitSeconds = 20.0): void
    {
        $command = [$this->python, $this->script, '0'];
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open($command, $spec, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the mock gateway');
        }

        $this->process = $process;
        $this->pipes = $pipes;
        fclose($this->pipes[0]);

        stream_set_blocking($this->pipes[1], false);

        $line = $this->readLine($waitSeconds);
        if (!str_starts_with($line, 'PORT ')) {
            $this->stop();
            throw new \RuntimeException('mock gateway did not report a port: ' . trim($line));
        }

        $this->baseUrl = 'http://127.0.0.1:' . trim(substr($line, strlen('PORT ')));
    }

    /**
     * Base URL of the running gateway, e.g. http://127.0.0.1:43210
     */
    public function baseUrl(): string
    {
        if ($this->baseUrl === '') {
            throw new \LogicException('the mock gateway has not been started');
        }

        return $this->baseUrl;
    }

    /**
     * Terminate the gateway and reap it.
     */
    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }

    /**
     * Read one line of stdout without blocking forever on a child that died.
     */
    private function readLine(float $waitSeconds): string
    {
        $buffer = '';
        $deadline = microtime(true) + $waitSeconds;

        while (microtime(true) < $deadline) {
            $chunk = fgets($this->pipes[1]);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                if (str_ends_with($buffer, "\n")) {
                    return $buffer;
                }
            }

            $status = proc_get_status($this->process);
            if (!$status['running'] && $buffer === '') {
                return '';
            }

            usleep(10_000);
        }

        return $buffer;
    }
}