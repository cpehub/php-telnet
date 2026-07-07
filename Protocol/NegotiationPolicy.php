<?php
namespace Cpehub\Telnet\Protocol;

use Cpehub\Telnet\Components\Option;

/**
 * Decides how the client answers option negotiation requests from the server.
 *
 * Defaults follow RFC 1123 §3.3.3: the client agrees to SUPPRESS-GO-AHEAD and
 * TRANSMIT-BINARY (both MUST-support), and refuses (WONT/DONT) everything it is
 * not configured for, falling back to NVT. The two sets are independent because
 * telnet options are negotiated per direction.
 */
class NegotiationPolicy
{
    /**
     * @param list<int> $enableForHim Options we will let the server perform (answer DO to his WILL).
     * @param list<int> $enableForUs  Options we are willing to perform (answer WILL to his DO).
     */
    public function __construct(
        private array $enableForHim = [
            Option::SUPPRESS_GO_AHEAD,
            Option::TRANSMIT_BINARY,
            Option::ECHO,
        ],
        private array $enableForUs = [
            Option::SUPPRESS_GO_AHEAD,
            Option::TRANSMIT_BINARY,
        ]
    ) {
    }

    /**
     * Should we agree to the server performing this option (i.e. answer DO to WILL)?
     */
    public function shouldEnableHim(int $option): bool
    {
        return in_array($option, $this->enableForHim, true);
    }

    /**
     * Should we agree to perform this option ourselves (i.e. answer WILL to DO)?
     */
    public function shouldEnableUs(int $option): bool
    {
        return in_array($option, $this->enableForUs, true);
    }
}
