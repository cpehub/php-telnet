<?php

namespace Cpehub\Telnet\Tests\Integration;

use Cpehub\Telnet\Client;
use Cpehub\Telnet\Tests\Support\ScriptedTransport;
use Cpehub\Telnet\Tests\Support\SpyLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ClientLoginTest extends TestCase
{
    #[Test]
    public function itDrivesLoginAndReturnsThePostLoginPrompt(): void
    {
        $transport = new ScriptedTransport('Username: ', [
            ['expect' => "admin\r", 'send' => 'Password: '],
            ['expect' => "s3cr3t\r", 'send' => 'Router#'],
        ]);
        $client = new Client('memory', 23, 500, transport: $transport);

        $result = $client->login('admin', 's3cr3t', '~#$~');

        $this->assertSame('Router#', $result->getText());
        $this->assertStringContainsString("admin\r", $transport->allWritten());
        $this->assertStringContainsString("s3cr3t\r", $transport->allWritten());
    }

    #[Test]
    public function itNeverLogsThePassword(): void
    {
        $transport = new ScriptedTransport('login: ', [
            ['expect' => "bob\r", 'send' => 'Password: '],
            ['expect' => "hunter2\r", 'send' => 'host$ '],
        ]);

        $logger = new SpyLogger();

        $client = new Client('memory', 23, 500, $logger, $transport);
        $client->login('bob', 'hunter2', '~\$ $~');

        $joined = implode("\n", $logger->lines);
        $this->assertStringNotContainsString('hunter2', $joined);
        $this->assertStringContainsString('<redacted>', $joined);
    }
}
