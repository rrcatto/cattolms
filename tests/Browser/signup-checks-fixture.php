<?php

declare(strict_types=1);

// Development-only fixture for signup-checks.cjs, which registers people through the Register page as anyone would.
// `names <count>` gives it that many different real names nobody on the site has, with an address each at a domain that
// receives no mail (owner, 2026-10-27: never the same name twice), and remembers the addresses; `cleanup` removes those
// accounts with everything they made, their sign-up attempts and any sign-in links still waiting (CheckScrub).
use CattoLearning\Application\CliBootstrap;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Tests\Support\CheckNames;
use CattoLearning\Tests\Support\CheckScrub;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$db = CliBootstrap::boot()['container']->get(Database::class);
$action = $argv[1] ?? '';
$statePath = '/tmp/catto-signup-checks-state.json';
if ($action === 'names') {
    $people = [];
    foreach ((new CheckNames($db))->people(max(1, (int) ($argv[2] ?? 3))) as $person) {
        // A valid South African ID number no account holds, for a date of birth between 1970 and 2000 (owner, 2026-10-27).
        do {
            $id = \CattoLearning\Auth\SouthAfricanIdNumber::make((new DateTimeImmutable('1970-01-01'))->modify('+' . random_int(0, 10950) . ' days'), random_int(0, 9999));
            $taken = $db->fetchOne('SELECT 1 FROM users WHERE id_number_hash = :h', ['h' => \CattoLearning\Auth\SouthAfricanIdNumber::fromInput($id, new DateTimeImmutable())->fingerprint()]) !== false;
        } while ($taken);
        $people[] = $person + ['name' => $person['first'] . ' ' . $person['last'], 'email' => strtolower(str_replace(' ', '', $person['first']) . '.' . str_replace(' ', '', $person['last'])) . '@inbox.test', 'id_number' => $id];
    }
    $known = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR) : [];
    file_put_contents($statePath, json_encode([...$known, ...array_column($people, 'email')], JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo json_encode($people, JSON_THROW_ON_ERROR), "\n";
} elseif ($action === 'cleanup') {
    if (!is_file($statePath)) exit(0);
    $emails = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $removed = (new CheckScrub($db))->remove(['emails' => $emails]);
    $list = json_encode($emails, JSON_THROW_ON_ERROR);
    $attempts = $db->executeStatement('DELETE FROM signup_attempts WHERE lower(email) IN (SELECT lower(jsonb_array_elements_text(CAST(:e AS jsonb))))', ['e' => $list]);
    $db->executeStatement('DELETE FROM auth_login_tokens WHERE lower(email) IN (SELECT lower(jsonb_array_elements_text(CAST(:e AS jsonb))))', ['e' => $list]);
    unlink($statePath);
    echo 'Removed ' . ($removed['users'] ?? 0) . ' sign-up check accounts and ' . $attempts . " sign-up attempts.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/signup-checks-fixture.php names <count>|cleanup');
}
