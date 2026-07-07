<?php
namespace Cpehub\Telnet\Protocol;

use Cpehub\Telnet\Components\Command;

/**
 * Encodes and decodes the IAC (0xFF) byte-doubling required by RFC 854/855.
 *
 * A literal data byte equal to IAC (255) must be sent as two IAC bytes so that
 * the receiver does not mistake it for the start of a telnet command. The same
 * doubling applies to parameter bytes inside a subnegotiation (IAC SB ... IAC SE).
 */
final class IacCodec
{
    private const IAC = Command::IAC;

    /**
     * Double every IAC byte in a run of application data so it travels as literal data.
     */
    public static function escape(string $data): string
    {
        if (!str_contains($data, chr(self::IAC))) {
            return $data;
        }

        return str_replace(chr(self::IAC), chr(self::IAC) . chr(self::IAC), $data);
    }

    /**
     * Reverse {@see escape()}: collapse every doubled IAC back into a single data byte.
     */
    public static function unescape(string $data): string
    {
        if (!str_contains($data, chr(self::IAC))) {
            return $data;
        }

        return str_replace(chr(self::IAC) . chr(self::IAC), chr(self::IAC), $data);
    }

    /**
     * Escape subnegotiation parameters. IAC doubling inside an SB block follows the
     * same rule as data (RFC 855), so this is an alias for {@see escape()} that names
     * the intent at the call site.
     */
    public static function escapeSubParams(string $params): string
    {
        return self::escape($params);
    }
}
