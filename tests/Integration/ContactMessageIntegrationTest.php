<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Contact\ContactMessageRepository;
use CattoLearning\Contact\ContactReason;
use CattoLearning\Contact\ContactService;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Infrastructure\Persistence\UserRepository;
use CattoPHP\Foundation\AccountReference;
use CattoLearning\Support\Uuid;
use CattoLearning\Http\Controller\InboxController;
use CattoLearning\Inbox\InboxService;
use CattoLearning\Tests\Support\{DevelopmentFixture, FakeMailer, InProcessPage, IntegrationContainer};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The contact form (owner, 2026-10-20): one form whose fields follow the reason chosen, the account
 * reference from a signed-in account and checked when typed, an order and a course, every message kept,
 * emailed to the site and acknowledged to its sender with fixed words, and the Inbox's list and page (2026-11-11).
 */
final class ContactMessageIntegrationTest extends TestCase
{
    private Database $db;
    private DevelopmentFixture $fixture;
    private FakeMailer $mailer;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->fixture = new DevelopmentFixture($this->db);
        $this->mailer = new FakeMailer();
        // As a person's browser sends the form (the spam check reads it).
        $this->agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0';
    }

    private ?string $agent = null;

    protected function tearDown(): void
    {
        if ($this->agent === null) unset($_SERVER['HTTP_USER_AGENT']); else $_SERVER['HTTP_USER_AGENT'] = $this->agent;
        if ($this->db->isTransactionActive()) $this->db->rollBack();
    }

    public function testSomeoneNotSignedInWritesAboutAnEftPaymentAndIsAcknowledgedWithFixedWords(): void
    {
        $service = $this->service();
        $body = 'CL202610170001';
        $typed = strtolower($body . AccountReference::checkCharacter($body));
        $message = ['reason' => ContactReason::ORDER, 'name' => 'Sipho Dlamini', 'email' => 'sipho@example.test', 'account_reference' => $typed,
            'order_topic' => 'eft', 'order_number' => 'cl00000012', 'eft_paid_on' => '2026-10-19', 'eft_amount' => 'R1 200.00', 'eft_reference' => 'CL-20261017-0001',
            'company_name' => 'Read only for its own reason', 'message' => 'I paid on Monday but my course has not started. Visit https://spam.example.test'];
        $reference = $service->submit(null, $message);
        self::assertMatchesRegularExpression('/^MSG-\d{6}$/', $reference);

        $row = $this->db->fetchAssociative('SELECT m.*, CASE WHEN m.account ~ \'^[0-9]+$\' THEN CAST(m.account AS BIGINT) END AS user_id, s.order_number, s.order_id, s.course_id FROM cattophp_inbox_conversations m LEFT JOIN contact_message_subjects s ON s.message_id = m.id WHERE m.reference = :r', ['r' => $reference]);
        self::assertSame(['order', null, AccountReference::format(strtoupper($typed)), 'CL-00000012', null], [$row['reason'], $row['user_id'], $row['account_reference'], $row['order_number'], $row['order_id']]);
        self::assertEquals(['topic' => 'eft', 'eft_paid_on' => '2026-10-19', 'eft_amount' => 'R1 200.00', 'eft_reference' => 'CL-20261017-0001'], json_decode((string) $row['details'], true), 'Only the order reason\'s own answers are kept.');
        self::assertNotNull($row['emailed_at']);
        self::assertNotNull($row['acknowledged_at']);

        [$toSite, $toSender] = $this->mailer->messages;
        self::assertSame(['contact', $reference, 'Accounting issue', false], [$toSite['type'], $toSite['reference'], $toSite['reason'], $toSite['signed_in']]);
        self::assertContains(['label' => 'EFT amount', 'value' => 'R1 200.00'], $toSite['details']);
        // The notice opens the message at its reference, never its row id (owner, 2026-11-06).
        self::assertStringEndsWith('/inbox/' . $reference, (string) $toSite['admin_url']);
        self::assertSame(['type' => 'contact_acknowledgement', 'email' => 'sipho@example.test', 'reference' => $reference, 'reason' => 'Accounting issue'], $toSender,
            'The acknowledgement carries nothing the sender typed but the address it goes to.');
    }

    public function testEachReasonChecksItsOwnFieldsAndATypedReferenceMustBeRight(): void
    {
        $service = $this->service();
        $base = ['name' => 'Ann Lee', 'email' => 'ann@example.test', 'message' => 'A question.'];
        $refusals = [
            [['reason' => 'pre_sales'] + $base, 'Choose what your message is about.'],
            [['reason' => ContactReason::OTHER, 'account_reference' => 'CL-20261017-0001-Z'] + $base, 'That account reference has a mistake in it. Check each character.'],
            [['reason' => ContactReason::ACCOUNT] + $base, 'Choose what your account question is about.'],
            [['reason' => ContactReason::ORDER, 'order_topic' => 'refund', 'order_number' => '12'] + $base, 'An order number looks like CL-00000012.'],
            [['reason' => ContactReason::ORDER, 'order_topic' => 'eft', 'eft_paid_on' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d')] + $base, 'Enter the day you paid as a real date, not in the future.'],
            [['reason' => ContactReason::BEFORE_BUYING, 'for_company' => 'yes'] + $base, 'Enter your company\'s name, in at most 160 characters.'],
            [['reason' => ContactReason::COURSE, 'course_id' => '999999999'] + $base, 'Choose a course from the list.'],
            [['reason' => ContactReason::OTHER, 'message' => ' '] + $base, 'Enter your message, in at most 5,000 characters.'],
        ];
        foreach ($refusals as [$input, $expected]) {
            try {
                $service->submit(null, $input);
                self::fail('Refused: ' . $expected);
            } catch (InvalidArgumentException $refused) {
                self::assertSame($expected, $refused->getMessage());
            }
        }
        self::assertSame([], $this->mailer->messages, 'Nothing is sent for a refused message.');

        $owner = $this->fixture->createUser('Course owner', 'contact-owner-' . $this->fixture->suffix() . '@example.test');
        $company = $this->fixture->createCompany($owner, 'Contact provider ' . $this->fixture->suffix(), $this->fixture->suffix() . '.contact.example.test');
        $slug = 'contact-course-' . $this->fixture->suffix();
        $course = $this->fixture->createCourse($owner, $company, $slug, 'Contact course', 'published');
        $reference = $service->submit(null, ['reason' => ContactReason::BEFORE_BUYING, 'buying_course_id' => (string) $course, 'course_id' => '', 'for_company' => 'yes', 'company_name' => 'Acme Training', 'learners' => '12'] + $base);
        $row = $this->db->fetchAssociative('SELECT s.course_id, m.details FROM cattophp_inbox_conversations m LEFT JOIN contact_message_subjects s ON s.message_id = m.id WHERE m.reference = :r', ['r' => $reference]);
        self::assertSame($course, (int) $row['course_id'], 'Without JavaScript both course lists arrive: the reason\'s own is read.');
        self::assertEquals(['company_name' => 'Acme Training', 'learners' => '12'], json_decode((string) $row['details'], true));

        $form = $service->form(null, ['reason' => 'course', 'course' => $slug, 'order' => 'CL-00000012']);
        self::assertSame(['course', (string) $course, 'CL-00000012', null], [$form['reason'], $form['course_id'], $form['order_number'], $form['account']], 'A link fills in what the page knows.');
    }

    public function testASignedInSendersReferenceComesFromTheAccountAndOnlyTheirOwnOrdersCount(): void
    {
        $service = $this->service();
        $email = 'contact-member-' . $this->fixture->suffix() . '@example.test';
        $id = $this->fixture->createUser('Thandi Member', $email);
        $user = new CurrentUser($id, Uuid::v4(), $email, 'Thandi Member', ['STUDENT'], ['ACCOUNT.VIEW'], Uuid::v4());
        $stored = (string) $this->db->fetchOne('SELECT account_reference FROM users WHERE id = :id', ['id' => $id]);

        $form = $service->form($user, ['reason' => 'order']);
        self::assertSame(['Thandi Member', $email, AccountReference::format($stored)], [$form['account']['name'], $form['account']['email'], $form['account']['reference']]);
        try {
            $service->submit($user, ['reason' => ContactReason::ORDER, 'order_topic' => 'refund', 'order_id' => '999999999', 'name' => 'Thandi', 'email' => $email, 'message' => 'Refund please.']);
            self::fail('Someone else\'s order is refused.');
        } catch (InvalidArgumentException $refused) {
            self::assertSame('Choose one of your orders from the list.', $refused->getMessage());
        }
        $reference = $service->submit($user, ['reason' => ContactReason::ACCOUNT, 'account_topic' => 'sign_in', 'account_reference' => 'CL-20261017-0001-U',
            'name' => 'Thandi', 'email' => $email, 'message' => 'The link does not arrive.']);
        $row = $this->db->fetchAssociative('SELECT CASE WHEN m.account ~ \'^[0-9]+$\' THEN CAST(m.account AS BIGINT) END AS user_id, m.account_reference FROM cattophp_inbox_conversations m LEFT JOIN contact_message_subjects s ON s.message_id = m.id WHERE m.reference = :r', ['r' => $reference]);
        self::assertSame([$id, AccountReference::format($stored)], [(int) $row['user_id'], $row['account_reference']], 'The account\'s own reference, never the typed one.');
        self::assertTrue($this->mailer->messages[0]['signed_in']);
    }

    public function testAFailedEmailLosesNothingAndTheInboxListsAndAnswersEachMessage(): void
    {
        $this->mailer = new FakeMailer(true);
        $service = $this->service();
        $tag = 'contact-list-' . $this->fixture->suffix();
        // The failed emails are logged; the log is kept out of the test's output.
        $log = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');
        try {
            $reference = $service->submit(null, ['reason' => ContactReason::OTHER, 'name' => 'Lerato ' . $tag, 'email' => $tag . '@example.test', 'message' => 'Hello.']);
        } finally {
            ini_set('error_log', $log);
        }
        $row = $this->db->fetchAssociative('SELECT m.id, m.emailed_at, m.acknowledged_at FROM cattophp_inbox_conversations m LEFT JOIN contact_message_subjects s ON s.message_id = m.id WHERE m.reference = :r', ['r' => $reference]);
        self::assertSame([null, null], [$row['emailed_at'], $row['acknowledged_at']], 'Kept, though neither email went.');

        $id = (int) $row['id'];
        $inbox = IntegrationContainer::get()->get(InboxService::class);
        $listed = $inbox->folder('inbox', ['search' => $tag], 25, 0)['rows'];
        self::assertSame([$reference, 'Other', 'New', '/inbox/' . $reference], [$listed[0]['reference'], $listed[0]['reason'], $listed[0]['status']['label'], $listed[0]['href']]);
        self::assertSame(0, $inbox->folder('inbox', ['search' => $tag, 'show' => 'answered'], 25, 0)['total']);
        self::assertSame(0, $inbox->folder('inbox', ['search' => $tag, 'reason' => ContactReason::ORDER], 25, 0)['total'], 'Filtered by reason.');

        $admin = $this->fixture->createUser('Contact administrator', 'contact-admin-' . $this->fixture->suffix() . '@example.test');
        $inbox->markAnswered($id, $admin);
        $inbox->markAnswered($id, $admin);
        self::assertSame(1, $inbox->folder('inbox', ['search' => $tag, 'show' => 'answered'], 25, 0)['total']);
        self::assertSame([true, 'Answered'], [$inbox->conversation($id)['answered'] ?? null, $inbox->conversation($id)['status_label'] ?? null]);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM cattophp_activity WHERE action = 'contact.message_answered' AND details->>'message_id' = :id", ['id' => (string) $id]), 'Answered once, recorded once.');
        self::assertSame(0, IntegrationContainer::get()->get(\CattoPHP\Inbox\Inbox::class)->cleanUp()['old'], 'A new message outlives the daily clean-up.');

        // Its page and its forms are at its reference in the Inbox, never its row id; the row id or a near miss names nothing (owner, 2026-11-06).
        $reader = new CurrentUser($admin, Uuid::v4(), 'contact-admin@example.test', 'Contact administrator', ['ADMIN'], ['PLATFORM.INBOX.VIEW', 'PLATFORM.INBOX.MANAGE'], Uuid::v4());
        $page = InProcessPage::run($reader, InboxController::class, 'conversation', ['reference' => $reference]);
        foreach (['action="/inbox/' . $reference . '/act"', 'action="/inbox/' . $reference . '/reply"', $reference, 'Mark not answered'] as $shown) self::assertStringContainsString($shown, $page['body']);
        $reopened = InProcessPage::run($reader, InboxController::class, 'conversationAct', ['reference' => $reference], ['csrf' => $_SESSION['csrf'], 'action' => 'unanswered']);
        self::assertSame('/inbox/' . $reference, $reopened['location']);
        self::assertFalse($inbox->conversation($id)['answered'] ?? true);
        foreach ([(string) $id, strtolower($reference), 'MSG-' . str_pad((string) $id, 7, '0', STR_PAD_LEFT)] as $miss) {
            try {
                InProcessPage::run($reader, InboxController::class, 'conversation', ['reference' => $miss]);
                self::fail($miss . ' names no message.');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function service(): ContactService
    {
        // CattoPHP's Inbox as the site has it, with the test's own domains taken to receive mail (they do not).
        $container = IntegrationContainer::get();
        $inbox = new \CattoPHP\Inbox\Inbox($container->get(\CattoPHP\Inbox\InboxStore::class), $container->get(\CattoPHP\Inbox\InboxSettings::class), $container->get(\CattoPHP\Http\FormStamp::class),
            $container->get(\CattoPHP\Network\NetworkLookup::class), $container->get(\CattoPHP\Network\ReputationLists::class), new class extends \CattoPHP\Signup\MailDomainCheck {
                public function receivesMail(string $domain): ?bool { return true; }
            }, $container->get(\CattoPHP\Signup\SignupCountries::class));
        return new ContactService(new ContactMessageRepository($this->db), new UserRepository($this->db), $this->mailer, new TransactionManager($this->db), $inbox);
    }
}
