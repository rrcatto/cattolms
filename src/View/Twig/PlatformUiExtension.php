<?php

declare(strict_types=1);

namespace CattoLearning\View\Twig;

use CattoLearning\View\Artwork\IconLibrary;
use CattoLearning\View\Ui\PlatformUi;
use InvalidArgumentException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Exposes the bounded platform component API to runtime Twig and the strict render harness. */
final class PlatformUiExtension extends AbstractExtension
{
    public function __construct(private readonly PlatformUi $ui, private readonly IconLibrary $icons = new IconLibrary())
    {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [new TwigFunction('ui_template', $this->ui->template(...)),
            new TwigFunction('ui_field_attrs', $this->ui->fieldAttributes(...)),
            new TwigFunction('ui_props', $this->ui->properties(...)),
            // An icon from the library by its name, as a feature card's icon (form.picture-choice).
            new TwigFunction('lucide', fn(string $name): string => $this->icons->svg($name) ?? throw new InvalidArgumentException('The icon library has no ' . $name . '.')),
            new TwigFunction('ui', $this->ui->render(...), [
            'needs_environment' => true,
            'needs_context' => true,
        ])];
    }
}
