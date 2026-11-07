<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\SiteAnalytics\LockoutGate;
use CattoLearning\SiteAnalytics\RequestFacts;
use CattoLearning\SiteAnalytics\SiteProtectionSettings;
use CattoLearning\SiteAnalytics\VisitorAgent;
use PHPUnit\Framework\TestCase;

/** What the Site Log keeps of a request and how it reads a browser (owner, 2026-11-04). */
final class SiteAnalyticsFactsTest extends TestCase
{
    public function testASecretInAnAddressIsNeverKept(): void
    {
        $hex = '0123456789abcdef0123456789abcdef';
        self::assertSame('/m/u/••••', RequestFacts::maskedPath('/m/u/' . $hex));
        self::assertSame('/m/c/••••/12', RequestFacts::maskedPath('/m/c/' . $hex . '/12'));
        self::assertSame('/m/o/••••.gif', RequestFacts::maskedPath('/m/o/' . $hex . '.gif'));
        self::assertSame('/join/••••', RequestFacts::maskedPath('/join/' . $hex));
        self::assertSame('/leaving/••••/private', RequestFacts::maskedPath('/leaving/abcDEF123/private'));
        self::assertSame('/certificates/••••/certificate.pdf', RequestFacts::maskedPath('/certificates/' . $hex . '/certificate.pdf'));
        self::assertSame('/transcripts/••••', RequestFacts::maskedPath('/transcripts/' . $hex));
        self::assertSame('/auth/consume', RequestFacts::maskedPath('/auth/consume?token=' . $hex), 'nothing after a question mark is kept');
        self::assertSame('/courses/growing-vegetables', RequestFacts::maskedPath('/courses/growing-vegetables?utm_source=x'));
        self::assertSame('/whatever/••••/x', RequestFacts::maskedPath('/whatever/' . $hex . '/x'), 'anything shaped like a key, wherever it comes');
        self::assertSame('/courses/a-very-long-course-name-with-no-digits-in-it-at-all', RequestFacts::maskedPath('/courses/a-very-long-course-name-with-no-digits-in-it-at-all'));
        // A random id and a URL-safe token stay hidden wherever they come (2026-11-07).
        self::assertSame('/learn/x/assessment/session/••••', RequestFacts::maskedPath('/learn/x/assessment/session/550e8400-e29b-41d4-a716-446655440000'));
        self::assertSame('/x/••••', RequestFacts::maskedPath('/x/Ab3dE_fG-hIjK1mNoPqRsTuVwXyZ0123456789abcde'));
        self::assertSame('/x/••••', RequestFacts::maskedPath('/x/k7m2x9qa3f9a2c1b7d4e0f1a2b3c4d5e6f'));
    }

    /**
     * The keys that name records in addresses are kept, so the Site Log says which page was asked for (owner,
     * 2026-11-06: no row id in an address, a recognisable code instead): account references, order and document numbers,
     * certificate numbers in Administration, contact message references, Course Item slugs and part names, long ones with
     * digits in them too.
     */
    public function testTheKeysThatNameRecordsAreKept(): void
    {
        foreach ([
            '/admin/people/CL2026011510423Z',
            '/company/people/CL2026011510423Z/status',
            '/admin/companies/CL2026011510418T',
            '/account/orders/CL-00000689/documents/INV-00000044',
            '/admin/commerce/orders/CL-00000689',
            '/admin/certificates/CL-2026-3F9A2C1B7D4E',
            '/admin/messages/MSG-000043',
            '/admin/course-items/growing-vegetables-in-small-spaces-m1-presentation',
            '/admin/course-items/gdn-101-module-12-practical-work-and-model-answers/slides/s1a2b3',
            '/learn/growing-vegetables-in-small-spaces/module-12-practical-work-and-model-answers-2',
            '/courses/growing-vegetables/preview/module-1-presentation/pdf',
            '/admin/courses/categories/garden-design',
        ] as $path) {
            self::assertSame($path, RequestFacts::maskedPath($path));
        }
    }

    public function testAnIpv6MachineCountsAsItsWholeSlash64(): void
    {
        self::assertSame('198.51.100.23', RequestFacts::subject('198.51.100.23'));
        self::assertSame('2001:db8:1:2::/64', RequestFacts::subject('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        self::assertSame(RequestFacts::subject('2001:db8:1:2::1'), RequestFacts::subject('2001:db8:1:2:ffff::9'));
    }

    public function testRangesHoldTheirAddresses(): void
    {
        self::assertTrue(SiteProtectionSettings::contains('198.51.100.0/24', '198.51.100.200'));
        self::assertFalse(SiteProtectionSettings::contains('198.51.100.0/24', '198.51.101.1'));
        self::assertTrue(SiteProtectionSettings::contains('198.51.100.128/25', '198.51.100.200'));
        self::assertFalse(SiteProtectionSettings::contains('198.51.100.128/25', '198.51.100.20'));
        self::assertTrue(SiteProtectionSettings::contains('2001:db8::/32', '2001:db8:1:2::9'));
        self::assertFalse(SiteProtectionSettings::contains('2001:db8::/32', '198.51.100.1'));
        self::assertTrue(SiteProtectionSettings::contains('198.51.100.23', '198.51.100.23'));
    }

    public function testBrowsersAreToldApartFromProgramsByWhatTheySay(): void
    {
        $cases = [
            ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Mobile Safari/537.36', VisitorAgent::BROWSER, 'Chrome on Android', 'phone'],
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', VisitorAgent::BROWSER, 'Safari on iPhone', 'phone'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0', VisitorAgent::BROWSER, 'Firefox on Windows', 'computer'],
            ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/139.0.0.0 Safari/537.36 Edg/139.0.0.0', VisitorAgent::BROWSER, 'Edge on Windows', 'computer'],
            ['Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Mobile/15E148 Safari/604.1', VisitorAgent::BROWSER, 'Safari on iPad', 'tablet'],
            ['Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 Chrome/61.0.3163.100 Safari/537.36', VisitorAgent::OUTDATED, 'Outdated: Chrome 61', 'computer'],
            ['Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)', VisitorAgent::OUTDATED, 'Outdated: Internet Explorer', 'computer'],
            ['Go-http-client/1.1', VisitorAgent::SCRIPT, 'Script: Go-http-client', ''],
            ['python-requests/2.32.3', VisitorAgent::SCRIPT, 'Script: python-requests', ''],
            ['curl/8.5.0', VisitorAgent::SCRIPT, 'Script: curl', ''],
            ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 HeadlessChrome/139.0.0.0 Safari/537.36', VisitorAgent::SCRIPT, 'Script: HeadlessChrome', ''],
            ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', VisitorAgent::SEARCH, 'Googlebot', ''],
            ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', VisitorAgent::SEARCH, 'bingbot', ''],
            ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.1; +https://openai.com/gptbot)', VisitorAgent::CRAWLER, 'AI crawler: GPTBot', ''],
            ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', VisitorAgent::CRAWLER, 'Crawler: AhrefsBot', ''],
            ['', VisitorAgent::NONE, 'No browser named', ''],
        ];
        foreach ($cases as [$agent, $kind, $label, $device]) {
            $read = VisitorAgent::read($agent);
            self::assertSame($kind, $read->kind, $agent);
            self::assertSame($label, $read->label, $agent);
            self::assertSame($device, $read->device, $agent);
        }
        self::assertTrue(VisitorAgent::read('curl/8.5.0')->isAutomated());
        self::assertFalse(VisitorAgent::read($cases[0][0])->isAutomated());
        self::assertFalse(VisitorAgent::read($cases[5][0])->isAutomated(), 'an old browser is still taken for a person');
    }

    public function testWhereAVisitorCameFromIsTheSiteAlone(): void
    {
        self::assertSame(['google.com', 'search'], RequestFacts::cameFrom('https://www.google.com/search?q=secret', 'example.test'));
        self::assertSame(['facebook.com', 'social'], RequestFacts::cameFrom('https://www.facebook.com/some/page', 'example.test'));
        self::assertSame(['', 'inside'], RequestFacts::cameFrom('https://example.test/courses', 'example.test'));
        self::assertSame(['', 'direct'], RequestFacts::cameFrom('', 'example.test'));
    }

    public function testALocalOrProxyAddressIsPrivateAndADocumentationOneIsNot(): void
    {
        foreach (['127.0.0.1', '10.0.2.100', '192.168.1.1', '172.16.0.1', '::1', 'fd00::1'] as $address) self::assertTrue(RequestFacts::isPrivate($address), $address);
        foreach (['198.51.100.23', '203.0.113.1', '8.8.8.8', '2001:db8::1'] as $address) self::assertFalse(RequestFacts::isPrivate($address), $address);
    }

    public function testTheRefusalSaysNothingAtAll(): void
    {
        self::assertStringContainsString('This site is not available.', LockoutGate::PAGE);
        self::assertSame(1, substr_count(strip_tags(LockoutGate::PAGE), 'not available') + substr_count(strip_tags(LockoutGate::PAGE), 'locked'), 'only one sentence, no reason');
        self::assertStringNotContainsString('<link', LockoutGate::PAGE);
        self::assertStringNotContainsString('<script', LockoutGate::PAGE);
    }
}
