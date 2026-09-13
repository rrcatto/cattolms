<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Workflow;

use CattoLearning\Commerce\Domain\Lifecycle;
use Symfony\Component\Workflow\Registry;

/** Validates lifecycle topology; application services enforce business guards before persistence. */
final class TransitionService
{
    public function __construct(private readonly Registry $workflows) {}
    public function apply(string $workflow, string $state, string $transition): string
    {
        $subject = new Lifecycle($state);
        $this->workflows->get($subject, 'commerce_' . $workflow)->apply($subject, $transition);
        return $subject->getState();
    }
}
