<?php
namespace Cpehub\Telnet\Tests\Security;

use Cpehub\Telnet\Client;
use Cpehub\Telnet\Components\CommandSequence;
use Cpehub\Telnet\Components\Printer;
use Cpehub\Telnet\Exceptions\ConnectionException;
use Cpehub\Telnet\Exceptions\TelnetException;
use Cpehub\Telnet\Transport\InMemoryTransport;
use Cpehub\Telnet\Transport\SocketTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

class SecurityHardeningTest extends TestCase
{
    #[Test]
    public function sensitiveSequencesAreRedactedInLogs(): void
    {
        $transport = new InMemoryTransport();
        $logger = $this->spyLogger();
        $client = new Client('memory', 23, 100, $logger, $transport);

        $client->sendSequence(
            (new CommandSequence())->addText('topsecret', chr(Printer::CR)),
            sensitive: true
        );

        $joined = implode("\n", $logger->lines);
        $this->assertStringNotContainsString('topsecret', $joined);
        $this->assertStringContainsString('<redacted>', $joined);
    }

    #[Test]
    public function timeoutExceptionDoesNotIncludeBufferContents(): void
    {
        $transport = new InMemoryTransport('SENSITIVE-BANNER-TEXT no prompt here');
        $client = new Client('memory', 23, 30, transport: $transport);

        try {
            $client->awaitPrompt('~NOPE$~', 30);
            $this->fail('Expected a TelnetException.');
        } catch (TelnetException $e) {
            $this->assertStringNotContainsString('SENSITIVE-BANNER-TEXT', $e->getMessage());
        }
    }

    #[Test]
    public function bufferGrowthIsCapped(): void
    {
        // Server sends far more unmatched data than the configured cap allows.
        $transport = new InMemoryTransport(str_repeat('y', 5000));
        $client = new Client('memory', 23, 200, transport: $transport, maxBuffer: 1024);

        $this->expectException(TelnetException::class);
        $this->expectExceptionMessage('maximum buffer size');

        $client->awaitPrompt('~ZZZ$~', 200);
    }

    #[Test]
    public function invalidPromptPatternIsRejected(): void
    {
        $transport = new InMemoryTransport('data');
        $client = new Client('memory', 23, 100, transport: $transport);

        $this->expectException(TelnetException::class);
        $this->expectExceptionMessage('Invalid prompt pattern');

        $client->awaitPrompt('~(unclosed', 100);
    }

    #[Test]
    public function emptyHostIsRejected(): void
    {
        $this->expectException(ConnectionException::class);
        new SocketTransport('', 23);
    }

    #[Test]
    public function invalidPortIsRejected(): void
    {
        $this->expectException(ConnectionException::class);
        new SocketTransport('host', 70000);
    }

    private function spyLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };
    }
}
