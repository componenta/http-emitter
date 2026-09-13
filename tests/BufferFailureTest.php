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
        $loader = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
        $autoload = dirname($loader->getFileName(), 2) . '/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';'
            . 'ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);'
            . 'try { (new \\' . Emitter::class . '())->emit(new \\' . Response::class . '(200, [], "payload"));'
            . 'fwrite(STDERR, "Expected an emission error"); exit(1); }'
            . 'catch (\\' . EmitterException::class . ' $error) { fwrite(STDOUT, "rejected"); }';
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
