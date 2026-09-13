<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Domain;

/** A transient workflow subject; repositories persist the resulting state in the same transaction. */
final class Lifecycle
{
    public function __construct(private string $state) {}
    public function getState(): string { return $this->state; }
    public function setState(string $state): void { $this->state = $state; }
}
