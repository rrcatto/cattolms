<?php

declare(strict_types=1);
namespace CattoLearning\Tests\Support;
use CattoLearning\View\Ui\ProjectComponents;

/** Renders a component through its real registry contract (CattoPHP's, as cattolms configures it) and the strict runtime Twig factory. */
final class ComponentHarness
{
    /** @param array<string,mixed> $properties */
    public static function render(string $component, array $properties = []): string
    {
        $ui = ProjectComponents::registry();
        return RenderHarness::render($ui->template($component), ['props' => $ui->properties($component, $properties)]);
    }
}
