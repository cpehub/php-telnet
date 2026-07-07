<?php
namespace Cpehub\Telnet\Tests\Protocol;

use Cpehub\Telnet\Protocol\IacCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IacCodecTest extends TestCase
{
    private const IAC = "\xFF";

    #[Test]
    public function itLeavesDataWithoutIacUntouched(): void
    {
        $this->assertSame('hello world', IacCodec::escape('hello world'));
        $this->assertSame('hello world', IacCodec::unescape('hello world'));
    }

    #[Test]
    public function itDoublesASingleIacByte(): void
    {
        $this->assertSame(self::IAC . self::IAC, IacCodec::escape(self::IAC));
    }

    #[Test]
    public function itDoublesIacInTheMiddleOfData(): void
    {
        $input = 'a' . self::IAC . 'b';
        $this->assertSame('a' . self::IAC . self::IAC . 'b', IacCodec::escape($input));
    }

    #[Test]
    public function itUnescapesDoubledIac(): void
    {
        $this->assertSame(self::IAC, IacCodec::unescape(self::IAC . self::IAC));
    }

    #[Test]
    #[DataProvider('roundTripSamples')]
    public function escapeThenUnescapeIsIdentity(string $sample): void
    {
        $this->assertSame($sample, IacCodec::unescape(IacCodec::escape($sample)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function roundTripSamples(): iterable
    {
        yield 'plain' => ['plain text'];
        yield 'single iac' => ["\xFF"];
        yield 'iac at start' => ["\xFF" . 'tail'];
        yield 'iac at end' => ['head' . "\xFF"];
        yield 'consecutive iac' => ["\xFF\xFF\xFF"];
        yield 'binary' => ["\x00\x01\xFF\x80\xFF"];
    }

    #[Test]
    public function subParamEscapingMatchesDataEscaping(): void
    {
        $params = "cols\xFFrows";
        $this->assertSame(IacCodec::escape($params), IacCodec::escapeSubParams($params));
    }
}
