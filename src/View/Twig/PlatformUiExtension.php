<?php

declare(strict_types=1);

namespace CattoLearning\View\Twig;

use CattoLearning\View\Ui\PlatformUi;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Exposes the bounded platform component API to runtime Twig and the strict render harness. */
final class PlatformUiExtension extends AbstractExtension
{
    public function __construct(private readonly PlatformUi $ui)
    {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [new TwigFunction('ui_template', $this->ui->template(...)),
            new TwigFunction('ui_field_attrs', $this->ui->fieldAttributes(...)),
            new TwigFunction('ui_props', $this->ui->properties(...)),
            new TwigFunction('ui', $this->ui->render(...), [
            'needs_environment' => true,
            'needs_context' => true,
        ])];
    }
}
