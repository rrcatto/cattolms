<?php

declare(strict_types=1);

// Development-only fixture for site-analytics.cjs. `create` writes requests from documentation addresses (RFC 5737) the way
// a request is written (SiteLogRepository::record) and runs the real rules (SiteProtectionJob), so the flags and time-outs
// on the pages are what the rules made. `server-view` writes what a server's firewall would tell the site, `server-requests`
// prints what the site asked of it, and `cleanup` removes everything from those addresses and those two files, and only that.
use CattoLearning\Application\CliBootstrap;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\SiteAnalytics\RequestFacts;
use CattoLearning\SiteAnalytics\ServerLink;
use CattoLearning\SiteAnalytics\SiteLogRepository;
use CattoLearning\SiteAnalytics\SiteProtectionJob;
use CattoLearning\SiteAnalytics\VisitorAgent;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$container = CliBootstrap::boot()['container'];
$db = $container->get(Database::class);
$ranges = "(subject <<= CAST('192.0.2.0/24' AS inet) OR subject <<= CAST('198.51.100.0/24' AS inet) OR subject <<= CAST('203.0.113.0/24' AS inet))";
$action = $argv[1] ?? '';
$server = $container->get(ServerLink::class);
if ($action === 'server-view') {
    // What a server's firewall would say (docs/OPERATIONS.md): only ever a documentation address.
    if (!is_dir($server->folder())) mkdir($server->folder(), 0775, true);
    file_put_contents($server->folder() . '/view.json', json_encode(['written_at' => gmdate(DATE_ATOM), 'probes_today' => 47,
        'bans' => [['address' => '198.51.100.200', 'why' => 'Probing for WordPress, .env and the like', 'since' => ''], ['address' => '203.0.113.0/28', 'why' => 'A lockout on this site', 'since' => gmdate(DATE_ATOM)]],
        'probes' => [['at' => gmdate(DATE_ATOM), 'address' => '198.51.100.200', 'path' => '/wp-login.php?x=1'], ['at' => gmdate(DATE_ATOM), 'address' => '2001:db8:1:2::9', 'path' => '/.env']]], JSON_THROW_ON_ERROR));
    exit(0);
}
if ($action === 'server-requests') {
    echo is_file($server->folder() . '/requests.log') ? file_get_contents($server->folder() . '/requests.log') : '';
    exit(0);
}
if ($action === 'cleanup') {
    array_map('unlink', glob($server->folder() . '/*') ?: []);
    if (is_dir($server->folder())) rmdir($server->folder());
    $db->executeStatement("DELETE FROM site_requests WHERE $ranges");
    $db->executeStatement("DELETE FROM site_flags WHERE $ranges");
    $db->executeStatement("DELETE FROM site_lockouts WHERE network <<= CAST('192.0.2.0/24' AS inet) OR network <<= CAST('198.51.100.0/24' AS inet) OR network <<= CAST('203.0.113.0/24' AS inet) OR asn = 64511");
    $db->executeStatement("DELETE FROM site_refused_emails WHERE value LIKE '%.example'");
    exit(0);
}
if ($action !== 'create') throw new RuntimeException('Usage: php tests/Browser/site-analytics-fixture.php create|cleanup');

$log = $container->get(SiteLogRepository::class);
$write = static function (string $address, string $method, string $path, int $status, string $agent, array $extra = []) use ($log): void {
    $reading = VisitorAgent::read($agent);
    $log->record($extra + [
        'address' => $address, 'subject' => RequestFacts::subject($address), 'user_id' => null, 'by_administrator' => false,
        'visitor' => $reading->isAutomated() ? 'automated' : 'person', 'agent_kind' => $reading->kind, 'agent_label' => $reading->label, 'device' => $reading->device, 'user_agent' => $agent,
        'country_code' => 'NL', 'network_kind' => 'hosting', 'network_asn' => 64511, 'network_name' => 'Example Hosting BV', 'method' => $method, 'path' => $path, 'status' => $status,
        'is_page' => $method === 'GET' && $status < 400, 'came_from' => '', 'source' => 'direct', 'form' => null, 'form_signal' => null, 'form_refused' => false, 'form_email' => null, 'form_email_hash' => null,
    ]);
};
$phone = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Mobile Safari/537.36';
$script = 'Go-http-client/1.1';
// A scanner asking for pages that do not exist: over the rule, so it is flagged and timed out.
for ($i = 0; $i < 22; $i++) $write('198.51.100.23', 'GET', '/old/page-' . $i . '/', 404, $script);
// A bot asking for sign-in links for different addresses, its forms refused.
for ($i = 0; $i < 3; $i++) $write('203.0.113.88', 'POST', '/login', 302, 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 Chrome/61.0.3163.100 Safari/537.36', ['country_code' => 'RU', 'network_name' => 'Sample Cloud LLC', 'form' => 'sign_in', 'form_signal' => 'outdated', 'form_refused' => true, 'form_email' => 'someone' . $i . '@example.net', 'form_email_hash' => hash('sha256', 'someone' . $i)]);
// A hidden field filled in, on the contact form.
$write('192.0.2.61', 'POST', '/contact', 302, $script, ['country_code' => 'DE', 'network_kind' => 'tor', 'network_name' => '', 'form' => 'contact', 'form_signal' => 'trap', 'form_refused' => true, 'form_email' => 'seo-offers@example.com', 'form_email_hash' => hash('sha256', 'seo')]);
// People looking around, from search engines and links.
foreach (['/', '/courses', '/register'] as $path) {
    $write('203.0.113.14', 'GET', $path, 200, $phone, ['country_code' => 'ZA', 'network_kind' => 'home', 'network_name' => 'Example Mobile', 'came_from' => $path === '/' ? 'google.com' : '', 'source' => $path === '/' ? 'search' : 'inside']);
    $write('203.0.113.57', 'GET', $path, 200, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', ['country_code' => 'ZA', 'network_kind' => 'home', 'network_name' => 'Example Fibre', 'came_from' => $path === '/' ? 'facebook.com' : '', 'source' => $path === '/' ? 'social' : 'inside']);
}
// A search engine, which proves nothing here, and a secret never kept.
$write('192.0.2.144', 'GET', '/courses', 200, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', ['country_code' => 'US']);
$write('203.0.113.57', 'GET', RequestFacts::maskedPath('/m/u/0123456789abcdef0123456789abcdef?x=1'), 200, $phone, ['country_code' => 'ZA', 'network_kind' => 'home']);
echo $container->get(SiteProtectionJob::class)->run(), "\n";
