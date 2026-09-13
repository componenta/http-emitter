<?php

declare(strict_types=1);

namespace Componenta\Http\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmitterTest extends TestCase
{
    public static function responses(): iterable
    {
        yield 'complete response' => [200, [], 'abc', 'abc'];
        yield 'selected range' => [206, ['Content-Range' => 'bytes 10-12/20'], 'abc', 'abc'];
        yield 'range error explanation' => [416, ['Content-Range' => 'bytes */20'], 'range unavailable', 'range unavailable'];
        yield 'no content' => [204, [], 'discarded', ''];
        yield 'reset content' => [205, [], 'discarded', ''];
        yield 'not modified' => [304, [], 'discarded', ''];
    }

    #[DataProvider('responses')]
    public function testEmitsThePreparedResponseBody(int $status, array $headers, string $body, string $expected): void
    {
        $loader = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
        $autoload = dirname($loader->getFileName(), 2) . '/autoload.php';
        $source = '<?php require ' . var_export($autoload, true) . ';'
            . '$response = new \Nyholm\Psr7\Response(' . var_export($status, true) . ','
            . var_export($headers, true) . ',' . var_export($body, true) . ');'
            . '(new \Componenta\Http\Emitter(chunkSize: 2))->emit($response);';
        $pipes = [];
        $command = [PHP_BINARY];
        if (is_string($ini = php_ini_loaded_file())) {
            array_push($command, '-c', $ini);
        }
        array_push($command, '-r', substr($source, 6));
        $process = proc_open($command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame($expected, $output);
    }
}
