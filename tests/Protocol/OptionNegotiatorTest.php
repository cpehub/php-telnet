<?php

namespace Cpehub\Telnet\Tests\Protocol;

use Cpehub\Telnet\Components\Command;
use Cpehub\Telnet\Components\Option;
use Cpehub\Telnet\Protocol\NegotiationPolicy;
use Cpehub\Telnet\Protocol\OptionNegotiator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OptionNegotiatorTest extends TestCase
{
    // ---- Inbound WILL --------------------------------------------------

    #[Test]
    public function willForAgreedOptionAnswersDoAndEnablesHim(): void
    {
        $n = new OptionNegotiator(); // SGA is in the default enable list

        $this->assertSame(Command::DO, $n->receiveWill(Option::SUPPRESS_GO_AHEAD));
        $this->assertTrue($n->isEnabledHim(Option::SUPPRESS_GO_AHEAD));
    }

    #[Test]
    public function willForRefusedOptionAnswersDontAndLeavesHimDisabled(): void
    {
        $n = new OptionNegotiator(new NegotiationPolicy(enableForHim: [], enableForUs: []));

        $this->assertSame(Command::DONT, $n->receiveWill(Option::TERMINAL_TYPE));
        $this->assertFalse($n->isEnabledHim(Option::TERMINAL_TYPE));
    }

    #[Test]
    public function willWhenAlreadyEnabledIsIgnored(): void
    {
        $n = new OptionNegotiator();
        $n->receiveWill(Option::SUPPRESS_GO_AHEAD); // him = YES

        $this->assertNull($n->receiveWill(Option::SUPPRESS_GO_AHEAD));
        $this->assertTrue($n->isEnabledHim(Option::SUPPRESS_GO_AHEAD));
    }

    // ---- Standard local-initiated enable, acked by peer ----------------

    #[Test]
    public function askEnableHimThenWillCompletesWithoutExtraTraffic(): void
    {
        $n = new OptionNegotiator();

        $this->assertSame(Command::DO, $n->askEnableHim(Option::SUPPRESS_GO_AHEAD));
        $this->assertFalse($n->isEnabledHim(Option::SUPPRESS_GO_AHEAD)); // still negotiating
        $this->assertNull($n->receiveWill(Option::SUPPRESS_GO_AHEAD));   // ack, no reply
        $this->assertTrue($n->isEnabledHim(Option::SUPPRESS_GO_AHEAD));
    }

    #[Test]
    public function askEnableHimRefusedByWontEndsDisabled(): void
    {
        $n = new OptionNegotiator();

        $this->assertSame(Command::DO, $n->askEnableHim(Option::SUPPRESS_GO_AHEAD));
        $this->assertNull($n->receiveWont(Option::SUPPRESS_GO_AHEAD)); // refusal, no reply
        $this->assertFalse($n->isEnabledHim(Option::SUPPRESS_GO_AHEAD));
    }

    // ---- Loop-prevention: no duplicate request while negotiating -------

    #[Test]
    public function askEnableHimTwiceDoesNotEmitASecondRequest(): void
    {
        $n = new OptionNegotiator();

        $this->assertSame(Command::DO, $n->askEnableHim(Option::SUPPRESS_GO_AHEAD));
        // WANTYES EMPTY: second request must be suppressed (Loop Example 2).
        $this->assertNull($n->askEnableHim(Option::SUPPRESS_GO_AHEAD));
    }

    #[Test]
    public function queuedOppositeRequestIsIssuedAfterNegotiationResolves(): void
    {
        $n = new OptionNegotiator();

        // Enable him.
        $n->receiveWill(Option::SUPPRESS_GO_AHEAD); // him = YES
        // Ask to disable -> WANTNO, send DONT.
        $this->assertSame(Command::DONT, $n->askDisableHim(Option::SUPPRESS_GO_AHEAD));
        // Change our mind: queue an enable while disable is in flight (WANTNO EMPTY -> himq OPPOSITE).
        $this->assertNull($n->askEnableHim(Option::SUPPRESS_GO_AHEAD));
        // Peer confirms the disable with WONT: because himq is OPPOSITE we now
        // flip to WANTYES and send DO.
        $this->assertSame(Command::DO, $n->receiveWont(Option::SUPPRESS_GO_AHEAD));
    }

    // ---- Our side (DO/DONT -> WILL/WONT) -------------------------------

    #[Test]
    public function doForAgreedOptionAnswersWillAndEnablesUs(): void
    {
        $n = new OptionNegotiator();

        $this->assertSame(Command::WILL, $n->receiveDo(Option::SUPPRESS_GO_AHEAD));
        $this->assertTrue($n->isEnabledUs(Option::SUPPRESS_GO_AHEAD));
    }

    #[Test]
    public function doForRefusedOptionAnswersWont(): void
    {
        $n = new OptionNegotiator(new NegotiationPolicy(enableForHim: [], enableForUs: []));

        $this->assertSame(Command::WONT, $n->receiveDo(Option::WINDOW_SIZE));
        $this->assertFalse($n->isEnabledUs(Option::WINDOW_SIZE));
    }

    #[Test]
    public function dontWhenEnabledDisablesUsAndAnswersWont(): void
    {
        $n = new OptionNegotiator();
        $n->receiveDo(Option::SUPPRESS_GO_AHEAD); // us = YES

        $this->assertSame(Command::WONT, $n->receiveDont(Option::SUPPRESS_GO_AHEAD));
        $this->assertFalse($n->isEnabledUs(Option::SUPPRESS_GO_AHEAD));
    }

    #[Test]
    public function dontWhenAlreadyDisabledIsIgnored(): void
    {
        $n = new OptionNegotiator();

        $this->assertNull($n->receiveDont(Option::SUPPRESS_GO_AHEAD));
    }

    // ---- Error transitions still converge (never throw) ----------------

    #[Test]
    public function dontAnsweredByWillRecoversToDisabled(): void
    {
        $n = new OptionNegotiator();
        $n->receiveWill(Option::SUPPRESS_GO_AHEAD);   // him = YES
        $n->askDisableHim(Option::SUPPRESS_GO_AHEAD); // him = WANTNO, sent DONT

        // Non-compliant peer answers DONT with WILL: WANTNO EMPTY -> him = NO.
        $this->assertNull($n->receiveWill(Option::SUPPRESS_GO_AHEAD));
        $this->assertFalse($n->isEnabledHim(Option::SUPPRESS_GO_AHEAD));
    }
}
