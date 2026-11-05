<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Configuration\SiteNotification;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\OptionRepository;
use CattoLearning\Tests\Support\DevelopmentFixture;
use CattoLearning\Tests\Support\IntegrationContainer;
use CattoLearning\View\ThemeRenderer;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** The site notification (owner, 2026-11-05): set at Settings, under the demo notice on every page, demo or live. */
final class SiteNotificationIntegrationTest extends TestCase
{
    private Database $db;
    private OptionRepository $options;
    private SiteNotification $notification;
    private int $actor;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->options = new OptionRepository($this->db);
        $this->options->delete('site_notification');
        $this->notification = new SiteNotification($this->options);
        $this->actor = (new DevelopmentFixture($this->db))->createUser('Site notification fixture');
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
    }

    /** @param array<string,mixed> $changes */
    private function save(array $changes = [], ?int $now = null): void
    {
        $this->notification->save($changes + ['show' => true, 'kind' => 'promotion', 'heading' => 'Winter sale', 'message' => 'Every course is reduced.', 'countdown_words' => '', 'ends_at' => ''], $this->actor, $now);
    }

    public function testTheCoursePagesShowItOnlyWhenTicked(): void
    {
        $this->save();
        self::assertFalse($this->notification->saved()['on_course_pages'], 'Off unless ticked.');
        self::assertFalse($this->notification->forPage()['on_course_pages'] ?? true);
        $this->save(['on_course_pages' => true]);
        self::assertTrue($this->notification->forPage()['on_course_pages'] ?? false);
    }

    public function testNothingShowsUntilOneIsSaved(): void
    {
        self::assertNull($this->notification->forPage());
        self::assertFalse($this->notification->settingsView()['exists']);
    }

    public function testEachKindIsDrawnInItsOwnTone(): void
    {
        foreach (['promotion' => 'success', 'information' => 'info', 'warning' => 'warning'] as $kind => $tone) {
            $this->save(['kind' => $kind]);
            $page = $this->notification->forPage();
            self::assertNotNull($page);
            self::assertSame($tone, $page['tone']);
            self::assertSame($kind, $page['kind'], 'It is drawn with its own icon.');
            self::assertSame('Winter sale', $page['heading']);
            self::assertSame('Every course is reduced.', $page['message']);
            self::assertNull($page['countdown']);
        }
    }

    public function testWhatCannotShowIsRefusedAndSavesNothing(): void
    {
        foreach ([['message' => '   '], ['kind' => 'shouting'], ['message' => str_repeat('x', 301)], ['heading' => str_repeat('x', 61)], ['countdown_words' => str_repeat('x', 61)], ['ends_at' => 'next Tuesday'], ['ends_at' => '2020-01-01T10:00']] as $wrong) {
            try {
                $this->save($wrong);
                self::fail('Refused: ' . json_encode($wrong));
            } catch (InvalidArgumentException) {
                self::assertFalse($this->notification->settingsView()['exists']);
            }
        }
    }

    public function testACountdownRunsToTheDateTypedInTheSitesTimeAndEndsItself(): void
    {
        $ends = new DateTimeImmutable('2030-06-15 10:00', new \DateTimeZone(date_default_timezone_get()));
        $this->save(['ends_at' => '2030-06-15T10:00', 'countdown_words' => 'Offer ends in'], $ends->getTimestamp() - 3700);
        $countdown = $this->notification->forPage($ends->getTimestamp() - 3700)['countdown'] ?? null;
        self::assertNotNull($countdown);
        self::assertSame($ends->getTimestamp(), $countdown['ends_at']);
        self::assertSame('Offer ends in', $countdown['words']);
        self::assertStringContainsString('15 June 2030 at 10:00', $countdown['until']);
        self::assertSame('2030-06-15T10:00', $this->notification->settingsView($ends->getTimestamp() - 3700)['ends_at_local']);

        self::assertNull($this->notification->forPage($ends->getTimestamp()), 'At zero it is gone.');
        $settings = $this->notification->settingsView($ends->getTimestamp());
        self::assertFalse($settings['showing']);
        self::assertTrue($settings['ended'], 'Settings says why.');
    }

    public function testACountdownWithNoWordsOfItsOwnSaysTheStandardOnes(): void
    {
        $this->save(['ends_at' => '2030-06-15T10:00'], 1000);
        self::assertSame(SiteNotification::COUNTDOWN_WORDS, $this->notification->forPage(1000)['countdown']['words'] ?? null);
    }

    public function testSwitchedOffItKeepsItsWordsAndRemoveClearsThem(): void
    {
        $this->save(['show' => false, 'message' => 'Kept  for   later', 'ends_at' => '2020-01-01T10:00']);
        self::assertNull($this->notification->forPage());
        self::assertSame('Kept for later', $this->notification->saved()['message'], 'Spaces tidied, words kept, an old date allowed while it is off.');
        $this->notification->remove();
        self::assertSame('', $this->notification->saved()['message']);
    }

    public function testAWrongSavedValueShowsNothingRatherThanFailing(): void
    {
        $this->options->set('site_notification', 'not what we wrote');
        self::assertNull($this->notification->forPage());
        self::assertSame('promotion', $this->notification->saved()['kind']);
    }

    public function testEveryChangeIsInTheActivityLogWithItsOldAndNewWords(): void
    {
        $service = IntegrationContainer::get()->get(PlatformAdministrationService::class);
        $service->saveSiteNotification(['show' => true, 'kind' => 'warning', 'message' => 'Going offline tonight.'], $this->actor);
        $service->removeSiteNotification($this->actor);
        $rows = $this->db->fetchFirstColumn("SELECT metadata::text FROM audit_log WHERE user_id = :user AND event_key = 'platform.site_notification_changed' ORDER BY id", ['user' => $this->actor]);
        self::assertCount(2, $rows);
        self::assertStringContainsString('Going offline tonight.', (string) $rows[0]);
        self::assertStringContainsString('Going offline tonight.', (string) $rows[1], 'Removing says what it removed.');
    }

    public function testItShowsUnderTheDemoNoticeOnADemoSiteAndAloneOnALiveOne(): void
    {
        $this->options->set('site_notification', (string) json_encode(['show' => true, 'kind' => 'warning', 'heading' => '', 'message' => 'Going offline tonight.', 'countdown_words' => '', 'ends_at' => null]));
        $renderer = IntegrationContainer::get()->get(ThemeRenderer::class);
        $this->options->set('site_mode', 'demo');
        $demo = $renderer->renderFragment('partials/flash-messages', ['flash_messages' => []]);
        self::assertGreaterThan(strpos($demo, 'data-demo-site'), strpos($demo, 'data-site-notification'));
        self::assertStringContainsString('Going offline tonight.', $demo);
        $this->options->set('site_mode', 'live');
        $live = $renderer->renderFragment('partials/flash-messages', ['flash_messages' => []]);
        self::assertStringNotContainsString('data-demo-site', $live);
        self::assertStringContainsString('Going offline tonight.', $live);
    }

    public function testATypedWordCannotBecomeMarkup(): void
    {
        $this->options->set('site_notification', (string) json_encode(['show' => true, 'kind' => 'warning', 'heading' => '<b>H</b>', 'message' => '<script>x</script>', 'countdown_words' => '', 'ends_at' => null]));
        $html = IntegrationContainer::get()->get(ThemeRenderer::class)->renderFragment('partials/flash-messages', ['flash_messages' => []]);
        self::assertStringNotContainsString('<script>x', $html);
        self::assertStringNotContainsString('<b>H', $html);
    }
}
