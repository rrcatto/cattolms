<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Http\Controller\SetupController;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Setup\SetupChecklist;
use CattoLearning\Tests\Support\{DevelopmentFixture, InProcessPage, IntegrationContainer};
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The setup wizard (owner, 2026-10-20: "a setup wizard inside the cattolms installation to take me through the
 * settings and everything that needs setting up … importing courses will be part of the checklist"): every step
 * in order, done by what the site holds or marked done by ADMIN, and the catalogue file loaded from it.
 */
final class SetupChecklistIntegrationTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
    }

    public function testTheStepsComeInOrderAndOnlyJudgementStepsAreMarked(): void
    {
        $setup = IntegrationContainer::get()->get(SetupChecklist::class);
        $steps = $setup->steps();
        self::assertSame(['site-owner', 'site-mode', 'mail', 'payments', 'fees', 'identity', 'theme', 'catalogue', 'courses', 'course-details', 'publish',
            'certificate', 'documents', 'legal', 'scheduler', 'launch'], array_column($steps, 'key'));
        foreach ($steps as $step) {
            self::assertNotSame('', $step['summary'], $step['key']);
            self::assertStringStartsWith('/', $step['href'], $step['key'] . ' opens a page of the site.');
        }
        $admin = (new DevelopmentFixture($this->db))->createUser('Setup administrator');
        $this->db->executeStatement("DELETE FROM app_options WHERE option_key = 'setup.done.theme'");
        self::assertFalse($this->step($setup, 'theme')['done']);
        $setup->mark('theme', true, $admin);
        self::assertTrue($this->step($setup, 'theme')['done']);
        $setup->mark('theme', false, $admin);
        self::assertFalse($this->step($setup, 'theme')['done']);
        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM audit_log WHERE event_key = 'setup.step_marked' AND user_id = :id", ['id' => $admin]));
        try {
            $setup->mark('courses', true, $admin);
            self::fail('A step the site can tell is not marked by hand.');
        } catch (InvalidArgumentException $refused) {
            self::assertSame('That step is done by what the site holds, not by marking it.', $refused->getMessage());
        }
        $progress = $setup->progress();
        self::assertSame(count($steps), $progress['total']);
        self::assertSame($progress['next'] === null ? $progress['total'] : count(array_filter($setup->steps(), static fn(array $s): bool => $s['done'])), $progress['done']);
    }

    public function testTheCatalogueStepLoadsTheFilePublishedWithTheCode(): void
    {
        $catalogue = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/catalogue/catalogue.json'), true);
        self::assertSame('cattolms-catalogue', $catalogue['format']);
        self::assertGreaterThan(0, count($catalogue['categories']));
        self::assertGreaterThan(0, count($catalogue['tags']));
        $this->db->executeStatement('DELETE FROM course_tags');
        $this->db->executeStatement('DELETE FROM tags');
        $setup = IntegrationContainer::get()->get(SetupChecklist::class);
        // The catalogue is loaded as ADMIN, as on a site where ADMIN has signed in.
        $roles = new \CattoLearning\Infrastructure\Persistence\RoleRepository($this->db);
        $admin = $roles->firstUserWithRole('ADMIN') ?? (function () use ($roles): int {
            $id = (new DevelopmentFixture($this->db))->createUser('Catalogue administrator');
            $roles->assign($id, 'ADMIN');
            return $id;
        })();
        $report = $setup->loadCatalogue($admin);
        self::assertSame(count($catalogue['tags']), $report['tags_created'], 'Every tag of the file, each with its icon.');
        self::assertSame(count($catalogue['tags']), (int) $this->db->fetchOne('SELECT COUNT(*) FROM tags WHERE icon_svg IS NOT NULL'));
        self::assertTrue($this->step($setup, 'catalogue')['done']);
    }

    public function testThePageListsTheStepsWithTheNextOneOpen(): void
    {
        $fixture = new DevelopmentFixture($this->db);
        $email = 'setup-admin-' . $fixture->suffix() . '@example.test';
        $admin = $fixture->createUser('Setup page administrator', $email);
        $user = new \CattoLearning\Auth\CurrentUser($admin, \CattoLearning\Support\Uuid::v4(), $email, 'Setup administrator', ['ADMIN'],
            (new \CattoLearning\Auth\PermissionCatalog())->keys(), \CattoLearning\Support\Uuid::v4());
        $page = InProcessPage::run($user, SetupController::class, 'index');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Set up your site', $page['body']);
        self::assertSame(16, substr_count($page['body'], '<details class="cl-ui-accordion-section"'));
        self::assertLessThanOrEqual(1, preg_match_all('#<details class="cl-ui-accordion-section"[^>]*\bopen\b#', $page['body']), 'At most the next step is open.');
    }

    /** @return array<string,mixed> */
    private function step(SetupChecklist $setup, string $key): array
    {
        foreach ($setup->steps() as $step) if ($step['key'] === $key) return $step;
        self::fail('No step ' . $key);
    }
}
