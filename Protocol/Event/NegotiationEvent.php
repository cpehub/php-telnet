<?php
namespace Cpehub\Telnet\Protocol\Event;

/**
 * An option negotiation command: IAC followed by WILL/WONT/DO/DONT and an option code.
 */
final class NegotiationEvent implements TelnetEvent
{
    /**
     * @param int $command One of Command::WILL, Command::WONT, Command::DO, Command::DONT.
     * @param int $option  The telnet option code the command refers to.
     */
    public function __construct(
        public readonly int $command,
        public readonly int $option
    ) {
    }
}
