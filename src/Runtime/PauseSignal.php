<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

/**
 * A run halted, waiting for a person — the PHP twin of fancy-flow's
 * `PauseSignal`.
 *
 * Not an error, despite arriving down the same channel as one. See
 * {@see Pause} for why the encoding is a public contract rather than the two
 * private prefixes this replaces.
 */
final class PauseSignal
{
    /**
     * @param string $nodeId   the node that paused — where a submission is
     *                         injected on resume.
     * @param string $awaiting what is being waited for. `approval` and `input`
     *                         are what the builtins emit, but the value is open:
     *                         a marketplace node may define its own (signature,
     *                         payment), and a runner that does not recognise one
     *                         should surface it rather than guess.
     * @param mixed  $detail   kind-supplied context for whoever renders the wait
     *                         — a form schema, the question, a diff to approve.
     *                         Must be JSON-serialisable: it crosses a queue
     *                         boundary and a database column.
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $awaiting,
        public readonly mixed $detail = null,
    ) {}

    public function isApproval(): bool
    {
        return $this->awaiting === 'approval';
    }

    public function isInput(): bool
    {
        return $this->awaiting === 'input';
    }
}
