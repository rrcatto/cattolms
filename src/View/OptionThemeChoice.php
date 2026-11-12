<?php

declare(strict_types=1);

namespace CattoLearning\View;

use CattoLearning\Infrastructure\Persistence\OptionRepository;
use CattoPHP\Activity\Actor;
use CattoPHP\Theme\ThemeChoice;

/**
 * Which theme the site is drawn in, kept where it always was: the option active_theme, with who chose it (CattoPHP
 * Phase 3, step 9). A person is an account number as an actor; the system chooses for nobody.
 */
final class OptionThemeChoice implements ThemeChoice
{
    public function __construct(private readonly OptionRepository $options)
    {
    }

    public function chosen(): string
    {
        return $this->options->get('active_theme', '');
    }

    public function choose(string $key, ?Actor $by): void
    {
        $id = $by !== null && $by->id !== null && ctype_digit($by->id) ? (int) $by->id : null;
        $this->options->set('active_theme', $key, $id);
    }
}
