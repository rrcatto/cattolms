<?php

declare(strict_types=1);

// Local browser QA only: select each theme for anonymous requests, always restore afterwards.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$container = CattoLearning\Application\CliBootstrap::boot()['container'];
$themes = $container->get(CattoLearning\View\ThemeManager::class);
$statePath = '/tmp/catto-canonical-theme-state.json';
$identity = json_decode((string) file_get_contents('/tmp/catto-ui-stabilisation-state.json'), true, 512, JSON_THROW_ON_ERROR);
if (!str_starts_with($identity['email'], 'ui-stabilisation-')) throw new RuntimeException('Expected isolated browser identity.');
if (($argv[1] ?? '') === 'begin') {
    if (is_file($statePath)) throw new RuntimeException('Restore the previous theme QA state first.');
    file_put_contents($statePath, json_encode(['theme' => $themes->activeTheme()], JSON_THROW_ON_ERROR));
} elseif (($argv[1] ?? '') === 'select') {
    if (!is_file($statePath)) throw new RuntimeException('Begin the QA session first.');
    $themes->activate($argv[2], (int) $identity['user']);
} elseif (($argv[1] ?? '') === 'restore') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $themes->activate($state['theme'], (int) $identity['user']);
    unlink($statePath);
} else {
    throw new RuntimeException('Usage: begin|select key|restore');
}
echo "Theme QA state updated.\n";
