<?php

declare(strict_types=1);
namespace CattoLearning\Tests\Support;
use CattoLearning\View\Ui\PlatformUi;

/** Renders a component through its real registry contract and the strict runtime Twig factory. */
final class ComponentHarness
{
    /** @param array<string,mixed> $properties */
    public static function render(string $component, array $properties = []): string
    {
        $ui = new PlatformUi();
        $template = substr($ui->template($component), strlen('@platform/'), -strlen('.html.twig'));
        return RenderHarness::render($template, ['props' => $ui->properties($component, $properties)]);
    }
}
