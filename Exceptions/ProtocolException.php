<?php
namespace Cpehub\Telnet\Exceptions;

/**
 * Thrown when the telnet byte stream is malformed and cannot be parsed
 * (for example a dangling IAC or an unterminated subnegotiation).
 */
class ProtocolException extends TelnetException
{
}
