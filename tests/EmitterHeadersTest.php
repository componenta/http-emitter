<?php

declare(strict_types=1);

namespace Componenta\Http\Tests;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EmitterHeadersTest extends TestCase
{
    public function testTransferCodingBelongsToTheTransportNotTheResponseProducer(): void
    {
        [$headers, $body] = $this->emit(200, ['Transfer-Encoding' => 'chunked'], 'abc', expectFailure: true);
        self::assertSame('rejected', $body);
        self::assertStringNotContainsString('Transfer-Encoding:', $headers);
    }

    public function testConflictingAndIncorrectLengthsAreRejectedBeforeResponseEmission(): void
    {
        foreach ([
            ['Content-Length' => '2'],
            ['Content-Length' => '4'],
            ['Content-Length' => ['3', '4']],
            ['Content-Length' => '3, 3'],
            ['Content-Length' => '-1'],
            ['Content-Length' => '92233720368547758080'],
            ['Content-Length' => '3', 'Transfer-Encoding' => 'chunked'],
        ] as $fields) {
            [$headers, $body] = $this->emit(200, $fields, 'abc', expectFailure: true);
            self::assertSame('rejected', $body);
            self::assertStringNotContainsString('Transfer-Encoding:', $headers);
        }
    }

    public function testSeekabilityDoesNotAdvertiseUnsupportedByteRanges(): void
    {
        [$headers, $body] = $this->emit(200, [], 'abc');
        self::assertSame('abc', $body);
        self::assertStringNotContainsString('Accept-Ranges:', $headers);
        [$explicit] = $this->emit(200, ['Accept-Ranges' => 'bytes'], 'abc');
        self::assertStringContainsString('Accept-Ranges: bytes', $explicit);
    }

    public function testOffloadedFileDoesNotAcquireAnIncorrectZeroLength(): void
    {
        [$headers] = $this->emit(200, ['X-Accel-Redirect' => '/internal/file'], '');
        self::assertStringContainsString('X-Accel-Redirect: /internal/file', $headers);
        self::assertStringNotContainsString('Content-Length:', $headers);
    }

    public function testStatusCodeIsNotImplicitlyChangedByLocationHeader(): void
    {
        [$headers, $body] = $this->emit(404, ['Location' => '/elsewhere'], 'missing');
        self::assertStringContainsString('Status: 404', $headers);
        self::assertSame('missing', $body);
    }

    public function testBodylessStatusesCannotEmitTransferFraming(): void
    {
        foreach ([103, 204, 205, 304] as $status) {
            [$headers, $body] = $this->emit($status, [
                'Transfer-Encoding' => 'chunked',
                'Trailer' => 'X-Checksum',
            ], 'discarded');
            self::assertSame('', $body);
            self::assertStringNotContainsString('Transfer-Encoding:', $headers);
            self::assertStringNotContainsString('Trailer:', $headers);
            if ($status === 205) {
                self::assertStringContainsString('Content-Length: 0', $headers);
            }
        }
    }

    public function testHeadDoesNotReadBodyAndPreservesRepresentationLength(): void
    {
        [$headers] = $this->emit(200, ['Content-Length' => '42'], '', method: 'HEAD', prohibitRead: true);
        self::assertStringContainsString('Content-Length: 42', $headers);
    }

    public function testNoProgressIsAnEmissionFailure(): void
    {
        [, $body] = $this->emit(200, [], 'abc', nonSeekable: true, expectFailure: true, stall: true);
        self::assertSame('rejected', $body);
    }

    public function testPreservesNotModifiedResponseMetadataWithoutEmittingABody(): void
    {
        [$headers, $body] = $this->emit(304, [
            'Content-Location' => '/documents/one',
            'ETag' => '"one"',
        ], 'discarded');

        self::assertStringContainsString('Content-Location: /documents/one', $headers);
        self::assertStringContainsString('ETag: "one"', $headers);
        self::assertSame('', $body);
    }

    public function testLengthDescribesTheEmittedPartOfANonSeekableStream(): void
    {
        [$headers, $body] = $this->emit(200, [], 'abcdef', nonSeekable: true, consumed: 2);

        self::assertSame('cdef', $body);
        if (preg_match('/^Content-Length:\s*(\d+)/mi', $headers, $match)) {
            self::assertSame('4', $match[1]);
        }
    }

    public function testConsumedSeekableBodyIsRewoundBeforeEmission(): void
    {
        [$headers, $body] = $this->emit(200, [], 'abcdef', consumed: 2);

        self::assertSame('abcdef', $body);
        self::assertStringContainsString('Content-Length: 6', $headers);
    }

    /** @return array{string, string} */
    private function emit(int $status, array $headers, string $body, bool $nonSeekable = false, int $consumed = 0, string $method = 'GET', bool $expectFailure = false, bool $stall = false, bool $prohibitRead = false): array
    {
        $binary = getenv('PHP_CGI_BINARY') ?: dirname(PHP_BINARY) . '/php-cgi' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        if (!is_file($binary)) {
            self::markTestSkipped('Install php-cgi or set PHP_CGI_BINARY to run HTTP header checks.');
        }

        $fixture = __DIR__ . '/Fixture/emit.php';
        $autoload = dirname((new ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $command = [$binary];
        if (is_string($ini = php_ini_loaded_file())) {
            array_push($command, '-c', $ini);
        }
        array_push($command, '-d', 'cgi.force_redirect=0', '-f', $fixture);
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            __DIR__,
            array_replace(getenv(), [
                'REQUEST_METHOD' => $method,
                'REDIRECT_STATUS' => '1',
                'SCRIPT_FILENAME' => $fixture,
                'SCRIPT_NAME' => '/emit.php',
                'DOCUMENT_ROOT' => dirname($fixture),
                'COMPONENTA_TEST_AUTOLOAD' => $autoload,
                'COMPONENTA_TEST_RESPONSE' => json_encode([$status, $headers, $body, $nonSeekable, $consumed, $expectFailure, $stall, $prohibitRead], JSON_THROW_ON_ERROR),
            ]),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $error . $output);
        $response = explode("\r\n\r\n", $output, 2);
        self::assertCount(2, $response);

        return $response;
    }
}
