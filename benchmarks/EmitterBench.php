<?php

declare(strict_types=1);

namespace Componenta\Http\Benchmarks;

use PhpBench\Attributes as Bench;

/**
 * End-to-end SAPI scenarios. A fresh CGI process per revolution is necessary
 * because the emitter commits headers and owns output buffers.
 * Results include process startup and pipe transfer.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\Revs(10)]
final class EmitterBench
{
    private string $binary;
    private string $script;
    private array $environment;

    public function setUp(): void
    {
        $this->binary = getenv('PHP_CGI_BINARY') ?: dirname(PHP_BINARY) . '/php-cgi';
        if (!is_file($this->binary)) {
            throw new \RuntimeException('PHP CGI is required for emitter benchmarks.');
        }
        $this->script = __DIR__ . '/fixtures/emit.php';
        $this->environment = array_replace(getenv(), [
            'REQUEST_METHOD' => 'GET',
            'REDIRECT_STATUS' => '1',
            'SCRIPT_FILENAME' => $this->script,
            'SCRIPT_NAME' => '/emit.php',
            'DOCUMENT_ROOT' => dirname($this->script),
        ]);
    }

    public function benchSmallResponse(): void { $this->emit('small', 1024); }
    public function benchOneMiB(): void { $this->emit('large', 1048576); }
    public function benchNonSeekable(): void { $this->emit('non-seekable', 65536); }
    public function benchPartial(): void { $this->emit('partial', 65536); }
    public function benchNoContent(): void { $this->emit('no-content', 0); }
    public function benchHead(): void { $this->emit('head', 0); }

    private function emit(string $scenario, int $length): void
    {
        $env = $this->environment;
        $env['COMPONENTA_BENCH_SCENARIO'] = $scenario;
        if ($scenario === 'head') {
            $env['REQUEST_METHOD'] = 'HEAD';
        }
        $process = proc_open([$this->binary, '-d', 'cgi.force_redirect=0', '-f', $this->script], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, dirname($this->script), $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot launch PHP CGI.');
        }
        fclose($pipes[0]);
        $response = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || $errors !== '' || $response === false) {
            throw new \RuntimeException('CGI emission failed: ' . $errors);
        }
        $parts = explode("\r\n\r\n", $response, 2);
        if (count($parts) !== 2 || strlen($parts[1]) !== $length
            || ($length !== 0 && $parts[1] !== str_repeat('x', $length))) {
            throw new \RuntimeException('CGI body mismatch.');
        }
    }
}
