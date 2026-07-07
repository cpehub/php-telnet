<?php
namespace Cpehub\Telnet\Protocol\Event;

/**
 * A run of in-band application data, already IAC-unescaped.
 */
final class DataEvent implements TelnetEvent
{
    public function __construct(public readonly string $data)
    {
    }
}
