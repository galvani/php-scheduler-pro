<?php declare(strict_types=1);

namespace Scheduling\Capability;

/** One engine's answer, per Capability, to "who does this here: the engine, the package, or nobody?". */
final readonly class CapabilityReport
{
    /** @param array<value-of<Capability>, Fulfilment> $fulfilments a capability left out is unavailable */
    public function __construct(private array $fulfilments) {}

    public function of(Capability $capability): Fulfilment
    {
        return $this->fulfilments[$capability->value] ?? Fulfilment::Unavailable;
    }
}
