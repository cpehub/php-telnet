<?php

namespace Cpehub\Telnet\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that records every message it receives, for assertions in tests.
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = (string) $message;
    }

    public function contains(string $needle): bool
    {
        return str_contains(implode("\n", $this->lines), $needle);
    }

    public function all(): string
    {
        return implode("\n", $this->lines);
    }
}
