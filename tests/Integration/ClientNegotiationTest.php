<?php
namespace Cpehub\Telnet\Tests\Integration;

use Cpehub\Telnet\Client;
use Cpehub\Telnet\Components\Command;
use Cpehub\Telnet\Components\Option;
use Cpehub\Telnet\Transport\InMemoryTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ClientNegotiationTest extends TestCase
{
    #[Test]
    public function itAnswersServerNegotiationWhileWaitingForAPrompt(): void
    {
        // Server sends: WILL SGA, DO TERMINAL_TYPE, then the prompt text.
        $inbound = chr(Command::IAC) . chr(Command::WILL) . chr(Option::SUPPRESS_GO_AHEAD)
            . chr(Command::IAC) . chr(Command::DO) . chr(Option::TERMINAL_TYPE)
            . "Router> ";

        $transport = new InMemoryTransport($inbound);
        $client = new Client('memory', 23, 500, transport: $transport);

        $result = $client->awaitPrompt('~> $~', 500);

        $this->assertSame('Router> ', $result->getText());

        // Client must have answered: DO SGA (agreed) and WONT TERMINAL_TYPE (refused).
        $written = $transport->takeWritten();
        $this->assertStringContainsString(
            chr(Command::IAC) . chr(Command::DO) . chr(Option::SUPPRESS_GO_AHEAD),
            $written
        );
        $this->assertStringContainsString(
            chr(Command::IAC) . chr(Command::WONT) . chr(Option::TERMINAL_TYPE),
            $written
        );
    }

    #[Test]
    public function negotiationBytesDoNotLeakIntoMatchedText(): void
    {
        // Interleave IAC commands inside the data run.
        $inbound = "abc"
            . chr(Command::IAC) . chr(Command::WILL) . chr(Option::ECHO)
            . "def#";

        $transport = new InMemoryTransport($inbound);
        $client = new Client('memory', 23, 500, transport: $transport);

        $result = $client->awaitPrompt('~#$~', 500);

        // The matched text is clean data only — no 0xFF/command bytes.
        $this->assertSame('abcdef#', $result->getText());
    }

    #[Test]
    public function sendMessageReturnsResponseText(): void
    {
        $transport = new InMemoryTransport("interface list\nGi0/1 up\nRouter#");
        $client = new Client('memory', 23, 500, transport: $transport);

        $response = $client->sendMessage('show int', '~#$~', 500);

        $this->assertStringContainsString('Gi0/1 up', $response);
        // The client must have written the command line with a CR terminator.
        $written = $transport->takeWritten();
        $this->assertStringContainsString("show int\r", $written);
    }

    #[Test]
    public function timeoutThrowsWithoutLeakingBufferContents(): void
    {
        $transport = new InMemoryTransport('partial output without a prompt');
        $client = new Client('memory', 23, 50, transport: $transport);

        $this->expectException(\Cpehub\Telnet\Exceptions\TelnetException::class);
        $this->expectExceptionMessage('Telnet prompt waiting time exceeded.');

        $client->awaitPrompt('~NEVER-MATCHES$~', 50);
    }
}
