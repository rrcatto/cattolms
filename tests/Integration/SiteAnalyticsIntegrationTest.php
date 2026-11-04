<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\AdminAttention;
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\OptionRepository;
use CattoLearning\Maintenance\RetentionCleanup;
use CattoLearning\SiteAnalytics\FlagRepository;
use CattoLearning\SiteAnalytics\FormGuard;
use CattoLearning\SiteAnalytics\LockoutGate;
use CattoLearning\SiteAnalytics\LockoutRepository;
use CattoLearning\SiteAnalytics\LockoutService;
use CattoLearning\SiteAnalytics\RefusedEmailRepository;
use CattoLearning\SiteAnalytics\RequestFacts;
use CattoLearning\SiteAnalytics\ServerLink;
use CattoLearning\SiteAnalytics\SiteLogRepository;
use CattoLearning\SiteAnalytics\SitePeriod;
use CattoLearning\SiteAnalytics\SiteProtectionJob;
use CattoLearning\SiteAnalytics\SiteProtectionSettings;
use CattoLearning\SiteAnalytics\VisitorAgent;
use CattoLearning\Tests\Support\{DevelopmentFixture, IntegrationContainer};
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** The Site Log, the rules, lockouts and the forms' checks (owner, 2026-11-04), on documentation addresses, every test in a transaction. */
final class SiteAnalyticsIntegrationTest extends TestCase
{
    private Database $db;
    private SiteLogRepository $log;
    private SiteProtectionSettings $settings;
    private FlagRepository $flags;
    private LockoutRepository $lockouts;
    private SiteProtectionJob $job;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $container = IntegrationContainer::get();
        $this->log = $container->get(SiteLogRepository::class);
        $this->settings = $container->get(SiteProtectionSettings::class);
        $this->flags = $container->get(FlagRepository::class);
        $this->lockouts = $container->get(LockoutRepository::class);
        $this->job = $container->get(SiteProtectionJob::class);
        $this->settings->reset();
        // Whatever a browser check left on these addresses is out of the way, inside this test's transaction.
        foreach (['site_requests', 'site_flags'] as $table) {
            $this->db->executeStatement("DELETE FROM $table WHERE subject <<= '192.0.2.0/24' OR subject <<= '198.51.100.0/24' OR subject <<= '203.0.113.0/24'");
        }
        $this->db->executeStatement("DELETE FROM site_lockouts WHERE network <<= '192.0.2.0/24' OR network <<= '198.51.100.0/24' OR network <<= '203.0.113.0/24'");
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        if ($this->db->isTransactionActive()) $this->db->rollBack();
    }

    /** @param array<string,mixed> $extra */
    private function request(string $address, string $path = '/', int $status = 200, string $method = 'GET', string $agent = 'Go-http-client/1.1', array $extra = []): void
    {
        $reading = VisitorAgent::read($agent);
        $this->log->record($extra + ['address' => $address, 'subject' => RequestFacts::subject($address), 'user_id' => null, 'by_administrator' => false,
            'visitor' => $reading->isAutomated() ? 'automated' : 'person', 'agent_kind' => $reading->kind, 'agent_label' => $reading->label, 'device' => $reading->device, 'user_agent' => $agent,
            'country_code' => 'NL', 'network_kind' => 'hosting', 'network_asn' => 64511, 'network_name' => 'Example Hosting BV', 'method' => $method, 'path' => $path, 'status' => $status,
            'is_page' => $method === 'GET' && $status < 400, 'came_from' => '', 'source' => 'direct', 'form' => null, 'form_signal' => null, 'form_refused' => false, 'form_email' => null, 'form_email_hash' => null]);
    }

    private function missing(string $address, int $count = 20): void
    {
        for ($i = 0; $i < $count; $i++) $this->request($address, '/old/' . $i, 404);
    }

    public function testPagesThatDoNotExistFlagAndTimeOutAnAddress(): void
    {
        $this->missing('198.51.100.23', 19);
        $this->job->run();
        self::assertNull($this->flags->findBySubject('198.51.100.23'), 'one under the limit does nothing');

        $this->request('198.51.100.23', '/old/last', 404);
        $this->job->run();
        $flag = $this->flags->findBySubject('198.51.100.23');
        self::assertNotNull($flag);
        self::assertSame('high', $flag['level']);
        self::assertSame('Asked for 20 pages that do not exist in 10 minutes.', FlagRepository::why($flag)[0]['words']);
        $force = $this->lockouts->inForceFor('198.51.100.23', null);
        self::assertSame('timeout', $force['which'] ?? null);
        self::assertTrue($force['automatic']);
        self::assertEqualsWithDelta(3600, strtotime((string) $force['until_at']) - strtotime((string) $force['started_at']), 5);
        self::assertSame('waiting', $this->stateOf('198.51.100.23'));

        // Running again changes nothing: the same requests are not counted twice.
        $this->job->run();
        self::assertSame(1, $this->inForce('198.51.100.23'));
    }

    public function testEachNewTimeOutDoublesAndThreeLockItOut(): void
    {
        $this->missing('198.51.100.24');
        $this->job->run();
        $this->endEverythingOn('198.51.100.24');
        $this->missing('198.51.100.24');
        $this->job->run();
        $second = $this->lockouts->inForceFor('198.51.100.24', null);
        self::assertSame('timeout', $second['which']);
        self::assertEqualsWithDelta(7200, strtotime((string) $second['until_at']) - strtotime((string) $second['started_at']), 5, 'it came back, so the time doubled');
        self::assertStringContainsString('came back', (string) $second['why']);

        $this->endEverythingOn('198.51.100.24');
        $this->missing('198.51.100.24');
        $this->job->run();
        $third = $this->lockouts->inForceFor('198.51.100.24', null);
        self::assertSame('lockout', $third['which']);
        self::assertTrue($third['automatic']);
        self::assertEqualsWithDelta(30 * 86400, strtotime((string) $third['until_at']) - strtotime((string) $third['started_at']), 5);
        self::assertSame('locked', $this->stateOf('198.51.100.24'));
    }

    public function testTheLongestTimeOutIsNeverPassed(): void
    {
        $values = $this->settings->values();
        $form = $this->formFrom($values);
        $form['longest'] = '90m';
        $form['lockout_count'] = '9';
        $this->settings->save($form);
        for ($round = 0; $round < 3; $round++) {
            $this->missing('198.51.100.25');
            $this->job->run();
            $force = $this->lockouts->inForceFor('198.51.100.25', null);
            self::assertLessThanOrEqual(5400 + 5, strtotime((string) $force['until_at']) - strtotime((string) $force['started_at']));
            $this->endEverythingOn('198.51.100.25');
        }
    }

    public function testASignedInPersonsAddressIsFlaggedAndNeverTimedOut(): void
    {
        $person = (new DevelopmentFixture($this->db))->person();
        $this->request('203.0.113.140', '/account', 200, 'GET', 'Mozilla/5.0 (Windows NT 10.0) Chrome/139.0.0.0 Safari/537.36', ['user_id' => $person['id'], 'visitor' => 'signed_in']);
        $this->missing('203.0.113.140');
        $this->job->run();
        self::assertSame(0, $this->inForce('203.0.113.140'), 'mobile networks share addresses among many people');
        $flag = $this->flags->findBySubject('203.0.113.140');
        self::assertNotNull($flag);
        self::assertStringContainsString('only flagged, not timed out', FlagRepository::why($flag)[0]['words']);
    }

    public function testAdministratorsNeverLockOutAndProvedSearchEnginesAreLeftAlone(): void
    {
        // An administrator's address.
        $this->request('203.0.113.141', '/admin', 200, 'GET', 'Mozilla/5.0 (Windows NT 10.0) Chrome/139.0.0.0 Safari/537.36', ['by_administrator' => true]);
        $this->missing('203.0.113.141');
        // On the Never lock out list, as a range.
        $form = $this->formFrom($this->settings->values());
        $form['never_lock_out'] = "203.0.113.128/28\n2001:db8:10::/48";
        $this->settings->save($form);
        $this->missing('203.0.113.130');
        // A search engine shown to be what it says.
        $this->missing('203.0.113.142');
        $this->db->executeStatement("UPDATE site_requests SET agent_kind = 'search', agent_label = 'Googlebot', agent_proved = TRUE WHERE address = '203.0.113.142'");
        // A private address, as a local test site or a proxy not passing addresses on would give.
        $this->missing('10.0.2.100');
        $this->job->run();
        foreach (['203.0.113.141', '203.0.113.130', '203.0.113.142', '10.0.2.100'] as $address) {
            self::assertSame(0, $this->inForce($address), $address);
            self::assertNull($this->flags->findBySubject($address), $address);
        }
    }

    public function testRulesThatOnlyFlagAndRulesTurnedOff(): void
    {
        for ($i = 0; $i < 3; $i++) $this->request('203.0.113.60', '/register', 302, 'POST', 'Mozilla/5.0 (Windows NT 10.0) Chrome/139.0.0.0 Safari/537.36', ['form' => 'register']);
        $this->job->run();
        self::assertSame(0, $this->inForce('203.0.113.60'));
        self::assertSame('Tried to register 3 times in 1 hour.', FlagRepository::why((array) $this->flags->findBySubject('203.0.113.60'))[0]['words']);
        $this->job->run();
        self::assertCount(1, FlagRepository::why((array) $this->flags->findBySubject('203.0.113.60')), 'a rule still firing inside its window adds nothing more');

        $form = $this->formFrom($this->settings->values());
        unset($form['rule']['missing']['on']);
        $this->settings->save($form);
        $this->missing('203.0.113.61', 40);
        $this->job->run();
        self::assertNull($this->flags->findBySubject('203.0.113.61'));
    }

    public function testSignInLinksForDifferentAddressesAndFormsFromBots(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $who) $this->request('203.0.113.70', '/login', 302, 'POST', 'Mozilla/5.0 (Windows NT 10.0) Chrome/139.0.0.0 Safari/537.36', ['form' => 'sign_in', 'form_email_hash' => hash('sha256', $who)]);
        foreach (['a', 'a', 'a', 'a', 'a', 'a'] as $who) $this->request('203.0.113.71', '/login', 302, 'POST', 'Mozilla/5.0 (Windows NT 10.0) Chrome/139.0.0.0 Safari/537.36', ['form' => 'sign_in', 'form_email_hash' => hash('sha256', $who)]);
        $this->job->run();
        self::assertNull($this->flags->findBySubject('203.0.113.70'), 'four addresses is under the limit');
        self::assertNull($this->flags->findBySubject('203.0.113.71'), 'one address again and again is someone waiting for their email');
        $this->request('203.0.113.70', '/login', 302, 'POST', 'Mozilla/5.0 (Windows NT 10.0) Chrome/139.0.0.0 Safari/537.36', ['form' => 'sign_in', 'form_email_hash' => hash('sha256', 'e')]);
        $this->request('203.0.113.72', '/contact', 302, 'POST', 'Go-http-client/1.1', ['form' => 'contact', 'form_signal' => 'trap', 'form_refused' => true, 'form_email' => 'x@example.net']);
        $this->request('203.0.113.73', '/login', 302, 'POST', 'curl/8.5.0', ['form' => 'sign_in', 'form_signal' => 'script', 'form_refused' => true]);
        $this->job->run();
        self::assertSame(1, $this->inForce('203.0.113.70'));
        self::assertEqualsWithDelta(86400, $this->lengthOf('203.0.113.72'), 5, 'the hidden field: a day');
        self::assertSame(0, $this->inForce('203.0.113.73'), 'a script: flagged only');
        self::assertNotNull($this->flags->findBySubject('203.0.113.73'));
    }

    public function testFalseFormTokensAreCounted(): void
    {
        for ($i = 0; $i < 10; $i++) $this->request('203.0.113.74', '/login', 400, 'POST');
        $this->job->run();
        self::assertSame(1, $this->inForce('203.0.113.74'));
        self::assertStringContainsString('form token', (string) $this->lockouts->inForceFor('203.0.113.74', null)['why']);
    }

    public function testNotAProblemLeavesAnAddressAlone(): void
    {
        $this->missing('198.51.100.30');
        $this->job->run();
        $person = (new DevelopmentFixture($this->db))->person();
        $this->flags->markFine('198.51.100.30', $person['id']);
        self::assertSame(1, $this->lockouts->liftAutomatic('198.51.100.30', $person['id']));
        self::assertSame('fine', $this->stateOf('198.51.100.30'));
        $this->missing('198.51.100.30');
        $this->job->run();
        self::assertSame(0, $this->inForce('198.51.100.30'));
        self::assertTrue($this->flags->isFine('198.51.100.30', SiteProtectionSettings::FINE_DAYS));
        // After the days have passed it can be flagged again.
        $this->db->executeStatement("UPDATE site_flags SET decided_at = NOW() - INTERVAL '31 days' WHERE subject = '198.51.100.30'");
        $this->job->run();
        self::assertSame(1, $this->inForce('198.51.100.30'));
        self::assertNotSame('fine', $this->stateOf('198.51.100.30'));
    }

    public function testTheGateRefusesEveryPageButNeverAWebhookAndCountsWithoutFillingTheLog(): void
    {
        $this->lockouts->add(['kind' => 'address', 'network' => '198.51.100.40', 'asn' => null, 'label' => '198.51.100.40', 'which' => 'lockout', 'why' => 'Testing', 'rule_key' => null, 'automatic' => false, 'created_by_user_id' => null, 'until' => null, 'firewall' => 'no']);
        $range = $this->lockouts->add(['kind' => 'range', 'network' => '203.0.113.0/28', 'asn' => null, 'label' => '203.0.113.0/28', 'which' => 'lockout', 'why' => 'Testing', 'rule_key' => null, 'automatic' => false, 'created_by_user_id' => null, 'until' => null, 'firewall' => 'no']);
        $six = $this->lockouts->add(['kind' => 'address', 'network' => '2001:db8:1:2::/64', 'asn' => null, 'label' => '2001:db8:1:2::/64', 'which' => 'timeout', 'why' => 'Testing', 'rule_key' => 'missing', 'automatic' => true, 'created_by_user_id' => null, 'until' => new DateTimeImmutable('+1 hour'), 'firewall' => 'no']);
        $gate = new LockoutGate($this->db);

        self::assertTrue($gate->refuses('198.51.100.40', '/', 'GET', 'curl/8.5.0'));
        self::assertTrue($gate->refuses('198.51.100.40', '/admin', 'POST', ''));
        self::assertFalse($gate->refuses('198.51.100.41', '/'), 'another address');
        self::assertFalse($gate->refuses('198.51.100.40', '/webhooks/didit', 'POST'), 'a webhook carries its own signature');
        self::assertTrue($gate->refuses('203.0.113.9', '/courses'), 'inside the range');
        self::assertFalse($gate->refuses('203.0.113.16', '/courses'), 'outside the range');
        self::assertTrue($gate->refuses('2001:db8:1:2:aaaa::1', '/'), 'anywhere in the /64');
        self::assertFalse($gate->refuses('2001:db8:1:3::1', '/'));
        self::assertSame('1', (string) $this->db->fetchOne('SELECT refused FROM site_lockouts WHERE id = :id', ['id' => $range]));
        self::assertSame('1', (string) $this->db->fetchOne('SELECT refused FROM site_lockouts WHERE id = :id', ['id' => $six]));
        self::assertSame('403', (string) $this->db->fetchOne("SELECT status FROM site_requests WHERE address = '203.0.113.9' AND refused"));

        // A flood: the counter goes on, the log stops.
        $this->db->executeStatement('UPDATE site_lockouts SET logged_day = CURRENT_DATE, logged_on_day = :limit WHERE id = :id', ['limit' => LockoutGate::LOGGED_A_DAY, 'id' => $range]);
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM site_requests');
        self::assertTrue($gate->refuses('203.0.113.9', '/courses'));
        self::assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM site_requests'));
        self::assertSame('2', (string) $this->db->fetchOne('SELECT refused FROM site_lockouts WHERE id = :id', ['id' => $range]));

        // On Never lock out, not refused whatever is in force.
        $form = $this->formFrom($this->settings->values());
        $form['never_lock_out'] = '198.51.100.40';
        $this->settings->save($form);
        self::assertFalse($gate->refuses('198.51.100.40', '/'));
    }

    public function testAnEndedOrLiftedLockoutNoLongerRefuses(): void
    {
        $gate = new LockoutGate($this->db);
        $ended = ['kind' => 'address', 'network' => '198.51.100.50', 'asn' => null, 'label' => '198.51.100.50', 'which' => 'timeout', 'why' => 'Testing', 'rule_key' => 'missing', 'automatic' => true, 'created_by_user_id' => null, 'until' => new DateTimeImmutable('-1 minute'), 'firewall' => 'no'];
        $this->lockouts->add($ended);
        self::assertFalse($gate->refuses('198.51.100.50', '/'));
        $id = $this->lockouts->add(['until' => null] + $ended);
        self::assertTrue($gate->refuses('198.51.100.50', '/'));
        self::assertTrue($this->lockouts->lift($id, null));
        self::assertFalse($gate->refuses('198.51.100.50', '/'));
        self::assertFalse($this->lockouts->lift($id, null), 'lifted once only');
    }

    public function testYouCannotLockOutYourselfOrYourOwnConnections(): void
    {
        $service = IntegrationContainer::get()->get(LockoutService::class);
        $person = (new DevelopmentFixture($this->db))->person();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.60';
        foreach (['198.51.100.60', '198.51.100.0/24'] as $own) {
            try {
                $service->lockOut($service->target($own), '1d', 'Testing', false, $person['id']);
                self::fail('It locked out the address in use: ' . $own);
            } catch (InvalidArgumentException $refused) {
                self::assertStringContainsString('You are using', $refused->getMessage());
            }
        }
        $form = $this->formFrom($this->settings->values());
        $form['never_lock_out'] = '203.0.113.0/28';
        $this->settings->save($form);
        foreach (['203.0.113.5', '203.0.113.0/24'] as $listed) {
            try {
                $service->lockOut($service->target($listed), '1d', 'Testing', false, $person['id']);
                self::fail('It locked out something on Never lock out: ' . $listed);
            } catch (InvalidArgumentException $refused) {
                self::assertStringContainsString('Never lock out', $refused->getMessage());
            }
        }
        try {
            $service->lockOut($service->target('198.51.101.1'), '1d', ' ', false, $person['id']);
            self::fail('A lockout needs a reason.');
        } catch (InvalidArgumentException $refused) {
            self::assertStringContainsString('why', $refused->getMessage());
        }
    }

    public function testLockingOutByHandAddressRangeAndOwner(): void
    {
        $service = IntegrationContainer::get()->get(LockoutService::class);
        $person = (new DevelopmentFixture($this->db))->person();
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        $service->lockOut($service->target('198.51.100.70'), '1h', 'Probing', false, $person['id']);
        $force = $this->lockouts->inForceFor('198.51.100.70', null);
        self::assertSame('lockout', $force['which']);
        self::assertFalse($force['automatic']);
        self::assertSame('locked', $this->stateOf('198.51.100.70'));

        // A new lockout of the same thing replaces the one in force.
        $service->lockOut($service->target('198.51.100.70'), 'good', 'For good now', false, $person['id']);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM site_lockouts l WHERE l.label = '198.51.100.70' AND " . LockoutRepository::IN_FORCE));
        self::assertNull($this->lockouts->inForceFor('198.51.100.70', null)['until_at']);

        $target = $service->target('198.51.100.77/24');
        self::assertSame(['kind' => 'range', 'network' => '198.51.100.0/24', 'asn' => null, 'label' => '198.51.100.0/24'], $target, 'a range starts at its first address');
        self::assertSame('address', $service->target('2001:db8:1:2::9')['kind']);
        self::assertSame('2001:db8:1:2::/64', $service->target('2001:db8:1:2::9')['network']);
        self::assertSame(['kind' => 'owner', 'network' => null, 'asn' => 64500, 'label' => 'AS64500'], $service->target('as64500'));
        foreach (['not an address', '198.51.100.1/4', 'AS'] as $wrong) {
            try {
                $service->target($wrong);
                self::fail('It took ' . $wrong);
            } catch (InvalidArgumentException $refused) {
                self::assertNotSame('', $refused->getMessage());
            }
        }

        // Who a wider lockout would catch is named first.
        $this->request('198.51.100.90', '/account', 200, 'GET', 'Mozilla/5.0 Chrome/139.0.0.0', ['user_id' => $person['id'], 'visitor' => 'signed_in']);
        self::assertSame([$person['id']], array_column($this->log->peopleOn('198.51.100.0/24', null, 30), 'id'));
        self::assertSame([$person['id']], array_column($this->log->peopleOn(null, 64511, 30), 'id'));
        self::assertSame([], $this->log->peopleOn('203.0.113.0/24', null, 30));
    }

    public function testAFirewallTickWritesARequestAndALiftReleasesIt(): void
    {
        $folder = sys_get_temp_dir() . '/site-firewall-' . bin2hex(random_bytes(4));
        $server = new ServerLink($folder);
        $container = IntegrationContainer::get();
        $service = new LockoutService($this->lockouts, $this->flags, $this->settings, $server, $container->get(\CattoLearning\Auth\Signup\NetworkLookup::class), $container->get(\CattoLearning\Infrastructure\Persistence\AuditRepository::class));
        $person = (new DevelopmentFixture($this->db))->person();
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        $form = $this->formFrom($this->settings->values());
        // Not switched on: the tick does nothing.
        $service->lockOut($service->target('198.51.100.80'), '1h', 'Testing', true, $person['id']);
        self::assertFileDoesNotExist($folder . '/server-firewall/requests.log');
        $form['firewall_link'] = '1';
        $this->settings->save($form);
        $id = $service->lockOut($service->target('198.51.100.81'), '1h', 'Testing', true, $person['id']);
        $service->lockOut($service->target('AS64512'), '1h', 'Testing', true, $person['id']);
        $lines = array_map(static fn(string $line): array => json_decode($line, true), file($folder . '/server-firewall/requests.log', FILE_IGNORE_NEW_LINES) ?: []);
        self::assertSame([['do' => 'ban', 'network' => '198.51.100.81']], array_map(static fn(array $line): array => ['do' => $line['do'], 'network' => $line['network']], $lines), 'a firewall knows nothing of network owners');
        $service->lift($id, $person['id']);
        $lines = array_map(static fn(string $line): array => json_decode($line, true), file($folder . '/server-firewall/requests.log', FILE_IGNORE_NEW_LINES) ?: []);
        self::assertSame('lift', end($lines)['do']);
        self::assertSame('released', $this->db->fetchOne('SELECT firewall FROM site_lockouts WHERE id = :id', ['id' => $id]));

        // What the server says.
        file_put_contents($folder . '/server-firewall/view.json', json_encode(['written_at' => gmdate(DATE_ATOM), 'probes_today' => 47, 'bans' => [['address' => '198.51.100.0/24', 'why' => 'Probing', 'since' => '']], 'probes' => [['at' => gmdate(DATE_ATOM), 'address' => '198.51.100.9', 'path' => '/.env?x=1']]]));
        $view = $server->view();
        self::assertTrue($view['available']);
        self::assertFalse($view['stale']);
        self::assertSame(47, $view['probes_today']);
        self::assertSame('/.env', $view['probes'][0]['path']);
        self::assertTrue($server->isBanned('198.51.100.99'));
        self::assertFalse($server->isBanned('198.51.101.1'));
        file_put_contents($folder . '/server-firewall/view.json', '{broken');
        self::assertFalse($server->view()['available'], 'a broken view is no view');
        array_map('unlink', glob($folder . '/server-firewall/*') ?: []);
        rmdir($folder . '/server-firewall');
        rmdir($folder);
    }

    public function testAFormFromABotIsRefusedAndTheListHolds(): void
    {
        $container = IntegrationContainer::get();
        $emails = $container->get(RefusedEmailRepository::class);
        $person = (new DevelopmentFixture($this->db))->person();
        $emails->add('Seo-Offers.Example', 'Selling search engine work', $person['id']);
        $emails->add('someone@example.net', '', $person['id']);
        foreach (['not an address', 'a@'] as $wrong) {
            try {
                $emails->add($wrong, '', $person['id']);
                self::fail('It took ' . $wrong);
            } catch (InvalidArgumentException $refused) {
                self::assertNotSame('', $refused->getMessage());
            }
        }
        $stack = new RequestStack();
        $guard = new FormGuard($stack, $emails, $this->settings);
        $browser = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/139.0.0.0 Safari/537.36';
        $form = function (string $name, array $post, string $email, ?string $agent, string $address = '198.51.100.100') use ($stack, $guard): array {
            $_SERVER['REMOTE_ADDR'] = $address;
            while ($stack->pop() !== null);
            $request = Request::create('/x', 'POST');
            if ($agent === null) $request->headers->remove('User-Agent'); else $request->headers->set('User-Agent', $agent);
            $stack->push($request);
            $refused = $guard->refuses($name, $post, $email);
            return [$refused, $request->attributes->get(FormGuard::ATTRIBUTE)];
        };
        [$refused, $note] = $form(FormGuard::SIGN_IN, [], 'person@example.org', $browser);
        self::assertFalse($refused);
        self::assertNull($note['signal']);
        self::assertSame(hash_hmac('sha256', 'person@example.org', \CattoLearning\Support\Env::string('APP_KEY')), $note['email_hash']);
        foreach ([[['website' => 'http://spam'], $browser, 'trap'], [[], null, 'no_browser'], [[], 'Mozilla/5.0 (Windows NT 6.1) Chrome/61.0.3163.100 Safari/537.36', 'outdated'], [[], 'curl/8.5.0', 'script']] as [$post, $agent, $signal]) {
            [$refused, $note] = $form(FormGuard::SIGN_IN, $post, 'person@example.org', $agent);
            self::assertTrue($refused, $signal);
            self::assertSame($signal, $note['signal']);
            self::assertSame('person@example.org', $note['email']);
        }
        // The refused list, by address and by domain, on every form, and counted.
        foreach ([FormGuard::SIGN_IN, FormGuard::REGISTER, FormGuard::CONTACT] as $name) {
            self::assertTrue($form($name, [], 'anyone@seo-offers.example', $browser)[0], $name);
            self::assertTrue($form($name, [], 'SOMEONE@example.net', $browser)[0], $name);
        }
        self::assertSame(['someone@example.net' => 3, 'seo-offers.example' => 3], array_column(array_map(static fn(array $row): array => ['v' => $row['value'], 'n' => (int) $row['refused']], $emails->all()), 'n', 'v'));
        // Register keeps its own check: a script is noted, not refused here.
        [$refused, $note] = $form(FormGuard::REGISTER, [], 'person@example.org', 'curl/8.5.0');
        self::assertFalse($refused);
        self::assertSame('script', $note['signal']);
        // A private address, and the owner's own list: never refused, so local checks and the owner's tools keep working.
        self::assertFalse($form(FormGuard::SIGN_IN, [], 'person@example.org', 'curl/8.5.0', '10.0.2.100')[0]);
        $post = $this->formFrom($this->settings->values());
        $post['never_lock_out'] = '198.51.100.100';
        $this->settings->save($post);
        self::assertFalse($form(FormGuard::SIGN_IN, [], 'person@example.org', 'curl/8.5.0')[0]);
    }

    public function testSettingsAreCheckedSavedAndReset(): void
    {
        $form = $this->formFrom($this->settings->values());
        foreach ([['log_kept_for', '120 days'], ['longest', 'soon'], ['lockout_count', '1'], ['never_lock_out', 'somewhere']] as [$field, $wrong]) {
            $bad = $form;
            $bad[$field] = $wrong;
            try {
                $this->settings->save($bad);
                self::fail($field . ' took ' . $wrong);
            } catch (InvalidArgumentException) {
                self::assertSame('environment', $this->settings->source());
            }
        }
        $bad = $form;
        $bad['rule']['missing']['within'] = 'soon';
        try {
            $this->settings->save($bad);
            self::fail('A rule took a wrong length.');
        } catch (InvalidArgumentException $refused) {
            self::assertStringContainsString('Asks for pages that do not exist', $refused->getMessage());
        }
        $form['log_kept_for'] = '1y';
        $form['rule']['missing']['count'] = '5';
        $this->settings->save($form);
        self::assertSame('database', $this->settings->source());
        self::assertSame('1y', $this->settings->values()['log_kept_for']);
        self::assertSame(5, $this->settings->values()['rules']['missing']['count']);
        $this->settings->reset();
        self::assertSame('environment', $this->settings->source());
        self::assertSame(20, $this->settings->values()['rules']['missing']['count']);
        // The starting values the owner chose (decisions 3A and 4A).
        $values = SiteProtectionSettings::environment();
        self::assertSame('120d', $values['log_kept_for']);
        self::assertSame('1y', $values['flags_kept_for']);
        self::assertSame('timeout', $values['rules']['flood']['then']);
        self::assertSame('flag', $values['rules']['register']['then']);
    }

    public function testTheCleanUpDeletesOnlyWhatIsPastItsTime(): void
    {
        $this->request('198.51.100.110');
        $this->request('198.51.100.111');
        $this->db->executeStatement("UPDATE site_requests SET at = NOW() - INTERVAL '121 days' WHERE address = '198.51.100.110'");
        $old = $this->lockouts->add(['kind' => 'address', 'network' => '198.51.100.112', 'asn' => null, 'label' => '198.51.100.112', 'which' => 'timeout', 'why' => 'Testing', 'rule_key' => 'missing', 'automatic' => true, 'created_by_user_id' => null, 'until' => new DateTimeImmutable('-400 days'), 'firewall' => 'no']);
        $forGood = $this->lockouts->add(['kind' => 'address', 'network' => '198.51.100.113', 'asn' => null, 'label' => '198.51.100.113', 'which' => 'lockout', 'why' => 'Testing', 'rule_key' => null, 'automatic' => false, 'created_by_user_id' => null, 'until' => null, 'firewall' => 'no']);
        $this->flags->raise('198.51.100.114', 'medium', 'trap', 'Testing', 60);
        $this->db->executeStatement("UPDATE site_flags SET last_flagged_at = NOW() - INTERVAL '400 days' WHERE subject = '198.51.100.114'");
        $deleted = IntegrationContainer::get()->get(RetentionCleanup::class)->run();
        self::assertGreaterThanOrEqual(1, $deleted['site_requests']);
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM site_requests WHERE address = '198.51.100.110'"));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM site_requests WHERE address = '198.51.100.111'"));
        self::assertNull($this->lockouts->find($old));
        self::assertNotNull($this->lockouts->find($forGood), 'in force, so kept');
        self::assertNull($this->flags->findBySubject('198.51.100.114'));
    }

    public function testTheFiguresAddUp(): void
    {
        $period = SitePeriod::of('today', '', '', new DateTimeImmutable('+1 minute'));
        $person = (new DevelopmentFixture($this->db))->person();
        $base = $this->log->figures($period, true);
        $browser = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/139.0.0.0 Safari/537.36';
        $this->request('198.51.100.120', '/', 200, 'GET', $browser, ['country_code' => 'ZA', 'came_from' => 'google.com', 'source' => 'search']);
        $this->request('198.51.100.120', '/courses', 200, 'GET', $browser, ['country_code' => 'ZA', 'source' => 'inside']);
        $this->request('198.51.100.121', '/', 200, 'GET', 'curl/8.5.0', ['country_code' => 'US']);
        $this->request('198.51.100.122', '/account', 200, 'GET', $browser, ['user_id' => $person['id'], 'visitor' => 'signed_in', 'country_code' => 'ZA']);
        $this->request('198.51.100.123', '/admin', 200, 'GET', $browser, ['by_administrator' => true, 'visitor' => 'signed_in']);
        $this->request('198.51.100.124', '/', 403, 'GET', $browser, ['is_page' => false]);
        $this->db->executeStatement("UPDATE site_requests SET refused = TRUE WHERE address = '198.51.100.124'");
        $figures = $this->log->figures($period, true);
        self::assertSame(3, $figures['visitors'] - $base['visitors'], 'the administrator and the refused are left out');
        self::assertSame(2, $figures['people'] - $base['people']);
        self::assertSame(1, $figures['automated'] - $base['automated']);
        self::assertSame(1, $figures['signed_in'] - $base['signed_in']);
        self::assertSame(4, $figures['page_views'] - $base['page_views']);

        $filters = ['q' => '198.51.100.12', 'from' => $period->from, 'to' => $period->to];
        $counts = $this->log->counts($filters);
        self::assertSame(6, $counts['all']);
        self::assertSame(2, $counts['signed']);
        self::assertSame(1, $counts['refused']);
        self::assertSame(1, count($this->log->page($filters + ['kind' => 'refused'], 25, 0)));
        self::assertSame(5, $this->log->counts($filters + ['leave_out_administrators' => true])['all']);
        self::assertSame(3, $this->log->counts($filters + ['country' => 'ZA'])['all']);
        self::assertSame(1, count($this->log->page(['subject' => '198.51.100.121'], 25, 0)));
        self::assertSame(['/m/u/••••'], array_column($this->visit('203.0.113.200', RequestFacts::maskedPath('/m/u/0123456789abcdef0123456789abcdef')), 'path'));
        // Nothing with a question mark or a secret reaches the log's own column through the recorder's function.
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM site_requests WHERE path LIKE '%?%' AND address <<= '198.51.100.0/24'"));
    }

    public function testAFlaggedAddressWaitsOnNeedsYourAttentionUntilDecided(): void
    {
        $fixture = new DevelopmentFixture($this->db);
        $person = $fixture->person();
        $this->missing('198.51.100.130');
        $this->job->run();
        $attention = IntegrationContainer::get()->get(AdminAttention::class);
        $admin = new CurrentUser($person['id'], 'x', 'admin@example.test', 'An Administrator', ['ADMIN'], ['PLATFORM.SITEANALYTICS.VIEW'], 'session');
        $rows = array_values(array_filter($attention->items($admin), static fn(array $item): bool => $item['kind'] === 'site_flag' && str_contains($item['title'], '198.51.100.130')));
        self::assertCount(1, $rows);
        self::assertStringEndsWith('198.51.100.130', urldecode($rows[0]['href']));
        self::assertStringContainsString('Asked for 20 pages', $rows[0]['summary']);
        $this->flags->markFine('198.51.100.130', $person['id']);
        self::assertSame([], array_values(array_filter($attention->items($admin), static fn(array $item): bool => $item['kind'] === 'site_flag' && str_contains($item['title'], '198.51.100.130'))));
    }

    /** @return list<array<string,mixed>> */
    private function visit(string $address, string $path): array
    {
        $this->request($address, $path);

        return $this->log->page(['subject' => $address], 5, 0);
    }

    private function stateOf(string $subject): string
    {
        foreach ($this->flags->all() as $flag) {
            if ($flag['subject'] === $subject) return (string) $flag['state'];
        }

        return '';
    }

    private function inForce(string $subject): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM site_lockouts l WHERE l.network = CAST(:subject AS cidr) AND ' . LockoutRepository::IN_FORCE, ['subject' => $subject]);
    }

    private function lengthOf(string $subject): int
    {
        $force = (array) $this->lockouts->inForceFor($subject, null);

        return (int) strtotime((string) $force['until_at']) - (int) strtotime((string) $force['started_at']);
    }

    /** Ends a time-out as time would: its end passes, and the requests before it are behind it. */
    private function endEverythingOn(string $subject): void
    {
        $this->db->executeStatement("UPDATE site_lockouts SET until_at = started_at - INTERVAL '2 seconds', started_at = started_at - (until_at - started_at) - INTERVAL '2 seconds' WHERE network = CAST(:subject AS cidr) AND lifted_at IS NULL", ['subject' => $subject]);
        $this->db->executeStatement("UPDATE site_requests SET at = at - INTERVAL '3 seconds' WHERE subject = CAST(:subject AS inet)", ['subject' => $subject]);
    }

    /**
     * The settings as the page would send them.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function formFrom(array $values): array
    {
        $rules = [];
        foreach ($values['rules'] as $key => $rule) {
            $rules[$key] = ['count' => (string) $rule['count'], 'within' => $rule['within'], 'then' => $rule['then'], 'length' => $rule['length']] + ($rule['on'] ? ['on' => '1'] : []);
        }

        return ['rule' => $rules, 'longest' => $values['longest'], 'lockout_count' => (string) $values['lockout_count'], 'lockout_within' => $values['lockout_within'], 'lockout_length' => $values['lockout_length'],
            'never_lock_out' => implode("\n", $values['never_lock_out']), 'log_kept_for' => $values['log_kept_for'], 'flags_kept_for' => $values['flags_kept_for']]
            + array_filter(['doubles' => $values['doubles'], 'spare_administrators' => $values['spare_administrators'], 'spare_people' => $values['spare_people'], 'spare_search_engines' => $values['spare_search_engines'], 'leave_out_administrators' => $values['leave_out_administrators'], 'firewall_link' => $values['firewall_link']]);
    }
}
