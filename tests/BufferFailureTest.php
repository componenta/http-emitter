<?php

declare(strict_types=1);

namespace Componenta\Http\Tests;

use Componenta\Http\Emitter;
use Componenta\Http\EmitterException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class BufferFailureTest extends TestCase
{
    public function testRejectsAnOutputBufferThatCannotBeRemoved(): void
    {
        $code = 'ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);'
            . 'try { (new \\' . Emitter::class . '())->emit(new \\' . Response::class . '(200, [], "payload"));'
            . 'fwrite(STDERR, "Expected an emission error"); exit(1); }'
            . 'catch (\\' . EmitterException::class . ' $error) { fwrite(STDOUT, "rejected"); }';
        $this->runScenario($code);
    }

    public function testPreservesCauseWhenAnOutputBufferHandlerThrows(): void
    {
        $code = <<<'PHP'
            $cause = new \RuntimeException('buffer callback failed');
            ob_start(static function (string $buffer, int $phase) use ($cause): string {
                throw $cause;
            });
            echo 'discarded';
            try {
                (new \Componenta\Http\Emitter())->emit(new \Nyholm\Psr7\Response(200, [], 'payload'));
                fwrite(STDERR, 'Expected an emission error');
                exit(1);
            } catch (\Componenta\Http\EmitterException $error) {
                if ($error->getPrevious() !== $cause) {
                    fwrite(STDERR, 'Original buffer failure was lost');
                    exit(2);
                }
                fwrite(STDOUT, 'rejected');
            }
            PHP;
        $this->runScenario($code);
    }

    public function testRejectsNonRemovableLowerBufferBeforeDiscardingUpperBuffer(): void
    {
        $code = <<<'PHP'
            ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);
            ob_start();
            $level = ob_get_level();
            echo 'upper-buffer';
            try {
                (new \Componenta\Http\Emitter())->emit(new \Nyholm\Psr7\Response(200, [], 'payload'));
                fwrite(STDERR, 'Expected an emission error');
                exit(1);
            } catch (\Componenta\Http\EmitterException $error) {
                if (ob_get_level() !== $level || ob_get_contents() !== 'upper-buffer') {
                    fwrite(STDERR, 'Upper buffer was discarded before validation');
                    exit(2);
                }
                ob_end_clean();
                fwrite(STDOUT, 'rejected');
            }
            PHP;
        $this->runScenario($code);
    }

    private function runScenario(string $code): void
    {
        $loader = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
        $autoload = dirname($loader->getFileName(), 2) . '/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';' . $code;
        $command = [PHP_BINARY];
        if (is_string($ini = php_ini_loaded_file())) {
            array_push($command, '-c', $ini);
        }
        array_push($command, '-r', $code);
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $deadline = microtime(true) + 5;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        if ($status['running']) {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            self::fail('Emitter did not complete within five seconds.');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame(0, $status['exitcode'], $error);
        self::assertSame('', $error);
        self::assertSame('rejected', $output);
    }
}
