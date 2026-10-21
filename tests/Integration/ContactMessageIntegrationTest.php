<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Contact\ContactMessageRepository;
use CattoLearning\Contact\ContactReason;
use CattoLearning\Contact\ContactService;
use CattoLearning\Infrastructure\Persistence\AuditRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Infrastructure\Persistence\UserRepository;
use CattoLearning\Support\AccountReference;
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\{DevelopmentFixture, FakeMailer, IntegrationContainer};
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The contact form (owner, 2026-10-20): one form whose fields follow the reason chosen, the account
 * reference from a signed-in account and checked when typed, an order and a course, every message kept,
 * emailed to the site and acknowledged to its sender with fixed words, and Administration's list.
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
    }

    protected function tearDown(): void
    {
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

        $row = $this->db->fetchAssociative('SELECT * FROM contact_messages WHERE reference = :r', ['r' => $reference]);
        self::assertSame(['order', null, AccountReference::format(strtoupper($typed)), 'CL-00000012', null], [$row['reason'], $row['user_id'], $row['account_reference'], $row['order_number'], $row['order_id']]);
        self::assertEquals(['topic' => 'eft', 'eft_paid_on' => '2026-10-19', 'eft_amount' => 'R1 200.00', 'eft_reference' => 'CL-20261017-0001'], json_decode((string) $row['details'], true), 'Only the order reason\'s own answers are kept.');
        self::assertNotNull($row['emailed_at']);
        self::assertNotNull($row['acknowledged_at']);

        [$toSite, $toSender] = $this->mailer->messages;
        self::assertSame(['contact', $reference, 'Accounting issue', false], [$toSite['type'], $toSite['reference'], $toSite['reason'], $toSite['signed_in']]);
        self::assertContains(['label' => 'EFT amount', 'value' => 'R1 200.00'], $toSite['details']);
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
        $row = $this->db->fetchAssociative('SELECT course_id, details FROM contact_messages WHERE reference = :r', ['r' => $reference]);
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
        $row = $this->db->fetchAssociative('SELECT user_id, account_reference FROM contact_messages WHERE reference = :r', ['r' => $reference]);
        self::assertSame([$id, AccountReference::format($stored)], [(int) $row['user_id'], $row['account_reference']], 'The account\'s own reference, never the typed one.');
        self::assertTrue($this->mailer->messages[0]['signed_in']);
    }

    public function testAFailedEmailLosesNothingAndAdministrationListsAndAnswersEachMessage(): void
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
        $row = $this->db->fetchAssociative('SELECT id, emailed_at, acknowledged_at FROM contact_messages WHERE reference = :r', ['r' => $reference]);
        self::assertSame([null, null], [$row['emailed_at'], $row['acknowledged_at']], 'Kept, though neither email went.');

        $id = (int) $row['id'];
        self::assertSame(1, $service->count('new', '', $tag));
        self::assertSame(0, $service->count('answered', '', $tag));
        self::assertSame(0, $service->count('all', ContactReason::ORDER, $tag), 'Filtered by reason.');
        $listed = $service->page('new', '', $reference, 25, 0);
        self::assertSame([$reference, 'Other', 'New', 'warning', '/admin/messages/' . $id], [$listed[0]['reference'], $listed[0]['reason_label'], $listed[0]['status_label'], $listed[0]['status_tone'], $listed[0]['href']]);

        $admin = $this->fixture->createUser('Contact administrator', 'contact-admin-' . $this->fixture->suffix() . '@example.test');
        $before = $service->unansweredCount();
        $service->markAnswered($id, $admin);
        $service->markAnswered($id, $admin);
        self::assertSame($before - 1, $service->unansweredCount());
        self::assertSame([true, 'Answered'], [$service->find($id)['answered'], $service->find($id)['status_label']]);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM audit_log WHERE event_key = 'contact.message_answered' AND metadata->>'message_id' = :id", ['id' => (string) $id]), 'Answered once, recorded once.');
        self::assertSame(0, (new ContactMessageRepository($this->db))->deleteBefore(new \DateTimeImmutable('-5 years')), 'A new message outlives the daily clean-up.');
    }

    private function service(): ContactService
    {
        return new ContactService(new ContactMessageRepository($this->db), new UserRepository($this->db), $this->mailer,
            new AuditRepository($this->db), new TransactionManager($this->db));
    }
}
