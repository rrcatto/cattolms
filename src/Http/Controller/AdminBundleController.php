<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Course\CourseAddress;
use CattoLearning\Auth\AuthService;
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Bundle\BundleRepository;
use CattoLearning\Bundle\BundleService;
use CattoLearning\Infrastructure\Persistence\AddressKeyRepository;
use CattoLearning\Support\Address\CatalogueAddress;
use CattoLearning\Support\{AccessPeriod, ListView, Money, SortOrder, WasPrice};
use CattoPHP\Ui\Pagination;
use CattoLearning\View\ThemeRenderer;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Course bundles in Administration: the list, the editor (details with the code and the status, ordered courses, offer)
 * and each bundle's sales. Every change is one ordinary form post, so the editor works without JavaScript; only the
 * course search uses the shared lookup. A failed details save keeps what was entered in the session draft.
 *
 * The list is a table or a grid of cards, as its reader chooses (Support\ListView), and each row or card is edited in
 * place as the course list's are (owner, 2026-11-06: "In the bundle list, it must be editable, just like courses are"):
 * its code, title, status, price and was price, the start and end of its sale and its access period. With JavaScript
 * only the row or card changes; without it the list comes back with the row open (edit=) and, after Save, a message.
 *
 * A bundle is named by its code in every address (owner, 2026-11-06: "a recogniseable code is better"):
 * /admin/bundles/BDL-GARDEN and the forms beneath it, edit=BDL-GARDEN and #bundle-BDL-GARDEN on the list. The code is
 * turned into the row id once (AddressKeyRepository::bundle), and a save that changes the code comes back to the new one.
 */
final class AdminBundleController extends BaseController
{
    private const BASE = '/admin/bundles';
    private const DATASET = 'bundles';
    private const STATUSES = ['' => 'All bundles', 'draft' => 'Draft', 'published' => 'On sale', 'off_sale' => 'Published, off sale', 'retired' => 'Retired'];

    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests,
        private readonly BundleService $bundles, private readonly BundleRepository $records, private readonly \CattoLearning\Course\CourseService $courses,
        private readonly AddressKeyRepository $keys, private readonly \CattoLearning\Bundle\BundlePositions $positions)
    {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/admin/bundles', name: 'admin_bundles', methods: ['GET'])]
    public function index(): Response
    {
        $actor = $this->requirePermission('BUNDLE.MANAGEMENT.VIEW');
        $search = PlatformAdministrationService::searchTerm(self::DATASET, $_GET);
        $status = is_string($_GET['status'] ?? null) && array_key_exists($_GET['status'], self::STATUSES) ? $_GET['status'] : '';
        $filters = ['search' => $search, 'status' => $status];
        // A table or a grid of cards, as the reader chose, remembered (Support\ListView); a grid pages in whole rows.
        $view = ListView::chosen(self::DATASET, $_GET, $_COOKIE);
        ListView::rememberAsked(self::DATASET, $_GET);
        $total = $this->records->count($filters);
        $pagination = $view === ListView::GRID
            ? Pagination::within($_GET[PlatformAdministrationService::pageParam(self::DATASET)] ?? null, $_GET[PlatformAdministrationService::sizeParam(self::DATASET)] ?? null, $total, ListView::pageSizes(self::DATASET, ListView::GRID))
            : Pagination::create($_GET[PlatformAdministrationService::pageParam(self::DATASET)] ?? null, $_GET[PlatformAdministrationService::sizeParam(self::DATASET)] ?? null, $total, 25);
        // In the order bundles are listed in by default (owner, 2026-11-12), or by any of its columns; a grid has the same
        // sorts as a Sort (UX rule 2.6g).
        $sort = SortOrder::create($_GET[self::DATASET . '_sort'] ?? null, $_GET[self::DATASET . '_dir'] ?? null, array_keys(BundleRepository::LIST_SORTS), 'position', SortOrder::ASCENDING);
        $rows = array_map(self::listed(...), $this->records->list($filters, $pagination->pageSize, $pagination->offset, $sort));
        $kept = array_filter([PlatformAdministrationService::searchParam(self::DATASET) => $search, 'status' => $status]);
        $sorted = $sort->key === 'position' && $sort->direction === SortOrder::ASCENDING ? [] : [self::DATASET . '_sort' => $sort->key, self::DATASET . '_dir' => $sort->direction];
        $chips = [];
        foreach (self::STATUSES as $value => $label) {
            $query = array_filter([PlatformAdministrationService::searchParam(self::DATASET) => $search, 'status' => $value]);
            $chips[] = ['label' => $label, 'href' => self::BASE . ($query === [] ? '' : '?' . http_build_query($query)), 'active' => $status === $value];
        }
        // A row opened as its form by the address (edit=), for whoever manages bundles: the plain way, without JavaScript.
        $canManage = $actor->hasPermission('BUNDLE.MANAGE');
        $editing = $canManage && is_string($_GET['edit'] ?? null) ? ($this->keys->bundle($_GET['edit']) ?? 0) : 0;
        $edit = null;
        foreach ($rows as $row) {
            if ((int) $row['id'] === $editing) $edit = $this->bundles->form($row);
        }
        // Dragged into order, as courses are (owner, 2026-11-12), by whoever manages bundles while the list is in that order;
        // a bundle dropped first on the page goes straight after the one before the page's first row.
        $arrange = $canManage && $view !== ListView::GRID && $sort->key === 'position' && $sort->direction === SortOrder::ASCENDING;
        // The owner's columns where they fit (2026-11-06), each with its share of the table (UX rule 2.1); the position first (2026-11-12).
        $columns = ['position' => 'Position|7', 'code' => 'Code|10', 'title' => 'Bundle|19', 'status' => 'Status|10', 'price' => 'Price|11', 'from' => 'On sale from|10', 'until' => 'On sale until|10',
            'access' => 'Access|8', 'courses' => 'Courses|5', 'sales' => 'Sales|4', 'actions' => 'Actions|6'];
        return $this->render('admin-bundles', PlatformAdministrationService::sortViewFor(self::DATASET, $sort, self::BASE, $columns, array_keys(BundleRepository::LIST_SORTS), $kept,
            ['from' => SortOrder::DESCENDING, 'until' => SortOrder::DESCENDING, 'price' => SortOrder::DESCENDING, 'sales' => SortOrder::DESCENDING, 'updated' => SortOrder::DESCENDING]) + [
            'title' => 'Bundles', 'bundles' => $rows, 'bundles_search' => $search, 'status_filters' => $chips,
            'bundles_pagination' => PlatformAdministrationService::paginationPayload(self::DATASET, $pagination, self::BASE, 'Bundles', $kept + $sorted, null, null, $view === ListView::GRID ? ListView::pageSizes(self::DATASET, ListView::GRID) : null),
            'bundles_view' => $view, 'bundles_view_switch' => ListView::switcher(self::DATASET, $view, self::BASE, $kept + $sorted),
            'bundles_editing' => $edit === null ? 0 : $editing, 'bundle_edit' => $edit, 'bundle_error' => '',
            'bundles_list_href' => self::listHref((string) ($_SERVER['REQUEST_URI'] ?? '')),
            'can_manage' => $canManage, 'units' => array_keys(AccessPeriod::UNITS), 'currency' => Money::platformCurrency(),
            'arrange' => $arrange, 'bundles_before_page' => $arrange && $rows !== [] ? $this->records->codeBefore((int) $rows[0]['id']) : '',
            // Renumber in the toolbar while the list is in its order, as a table or as cards.
            'bundles_can_renumber' => $canManage && $sort->key === 'position' && $sort->direction === SortOrder::ASCENDING,
        ]);
    }

    /**
     * Moves a bundle in the list's order (owner, 2026-11-12: "drag and drop them into the positions they display in"): after
     * the bundle whose code is in `after` (empty for first), from a drag; or up, down or to the top in `direction`, from the
     * ⋯ menu without JavaScript. A drag asks for JSON and is answered with each bundle whose number changed, by its code.
     */
    #[Route('/admin/bundles/{code}/move', name: 'admin_bundle_move', requirements: ['code' => CatalogueAddress::BUNDLE], methods: ['POST'])]
    public function move(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = $this->bundleId();
        $wantsJson = str_contains((string) $this->request()->headers->get('Accept', ''), 'application/json');
        $move = function () use ($id, $actor): array {
            if (array_key_exists('after', $_POST)) {
                $after = trim((string) $_POST['after']);
                $afterId = $after === '' ? null : ($this->keys->bundle($after) ?? throw new \InvalidArgumentException('Choose a valid position.'));
                return $this->positions->arrange($id, $afterId, $actor->id);
            }
            return $this->positions->move($id, trim((string) ($_POST['direction'] ?? '')), $actor->id);
        };
        if ($wantsJson) {
            try {
                $positions = [];
                foreach ($move() as $movedId => $position) $positions[$this->code((int) $movedId)] = $position;
                return new \Symfony\Component\HttpFoundation\JsonResponse(['positions' => (object) $positions]);
            } catch (\InvalidArgumentException $refused) {
                return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => $refused->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        return $this->handle(function () use ($move): void {
            $move();
            $this->flash('success', 'The bundle was moved.');
            $this->redirect(self::BASE);
        }, self::BASE);
    }

    #[Route('/admin/bundles/new', name: 'admin_bundle_new', methods: ['GET'])]
    public function create(): Response
    {
        $this->requirePermission('BUNDLE.MANAGE');
        return $this->editor(null);
    }

    #[Route('/admin/bundles', name: 'admin_bundle_store', methods: ['POST'])]
    public function store(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $refused = null;
        try {
            $id = $this->bundles->create($actor, $_POST, $refused);
        } catch (RuntimeException $failed) {
            return $this->keepDraft('new', $failed, self::BASE . '/new');
        }
        // The status chosen on the form is set by the same checks as ever: one they refuse leaves the new bundle a draft.
        if ($refused !== null) {
            $this->flash('info', 'The bundle was created as a draft. ' . $refused);
        } else {
            $this->flash('success', match ((string) ($_POST['status'] ?? 'draft')) {
                'retired' => 'The bundle was created, retired.',
                default => 'The bundle was created as a draft. Add its courses and price, then publish it.',
            });
        }
        $this->redirect($this->address($id));
    }

    #[Route('/admin/bundles/{code}', name: 'admin_bundle', requirements: ['code' => CatalogueAddress::BUNDLE], methods: ['GET'])]
    public function show(): Response
    {
        $this->requirePermission('BUNDLE.MANAGEMENT.VIEW');
        return $this->editor($this->records->find($this->bundleId()) ?? throw $this->notFound('This bundle does not exist.'));
    }

    #[Route('/admin/bundles/{code}', name: 'admin_bundle_update', requirements: ['code' => CatalogueAddress::BUNDLE], methods: ['POST'])]
    public function update(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = $this->bundleId();
        $before = $this->records->find($id);
        try {
            $this->bundles->updateDetails($actor, $id, $_POST);
        } catch (RuntimeException $failed) {
            return $this->keepDraft((string) $id, $failed, $this->address($id));
        }
        $status = (string) ($_POST['status'] ?? '');
        $this->flash('success', $before === null || $status === '' || $status === $before['status'] ? 'The bundle details were saved.' : match ($status) {
            'published' => 'The bundle details were saved, and the bundle is published.',
            'retired' => 'The bundle details were saved, and the bundle is retired and no longer sold. Courses already bought through it are not affected.',
            default => 'The bundle details were saved, and the bundle was returned to draft and is no longer shown.',
        });
        // The code may have changed: back to the bundle at its code now.
        $this->redirect($this->address($id));
    }

    /**
     * A bundle's row (or, with view=grid, its card) in the list (owner, 2026-11-06): mode=edit draws it as its form,
     * otherwise as it is (Cancel). For htmx only that row or card is sent; an ordinary request (no JavaScript) goes back to
     * the list, with the row open for mode=edit.
     */
    #[Route('/admin/bundles/{code}/row', name: 'admin_bundle_row', requirements: ['code' => CatalogueAddress::BUNDLE], methods: ['GET'])]
    public function row(): Response
    {
        $edit = ($_GET['mode'] ?? '') === 'edit';
        $actor = $this->requirePermission($edit ? 'BUNDLE.MANAGE' : 'BUNDLE.MANAGEMENT.VIEW');
        $id = $this->bundleId();
        if (!$this->isHtmxRequest()) {
            $code = $this->param('code');
            $this->redirect(self::BASE . ($edit ? '?edit=' . rawurlencode($code) : '') . '#' . CatalogueAddress::bundleAnchor($code));
        }
        return $this->rowFragment($actor, $id, $edit, (string) ($_GET['view'] ?? ListView::LIST), self::listHref((string) ($_SERVER['HTTP_HX_CURRENT_URL'] ?? '')), arrange: ($_GET['arrange'] ?? '') === '1');
    }

    /**
     * Saves a row edited in place (BundleService::saveRow). For htmx the row or card comes back as it now is, or as it was
     * typed with why it was refused (status 422); without JavaScript the list comes back with a message.
     */
    #[Route('/admin/bundles/{code}/row', name: 'admin_bundle_row_save', requirements: ['code' => CatalogueAddress::BUNDLE], methods: ['POST'])]
    public function saveRow(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = $this->bundleId();
        $list = self::listHref((string) ($_POST['return'] ?? ''));
        $view = (string) ($_POST['view'] ?? ListView::LIST);
        try {
            $this->bundles->saveRow($actor, $id, $_POST);
        } catch (RuntimeException $refused) {
            if (!$this->isHtmxRequest()) {
                $this->flash('danger', $refused->getMessage());
                $code = $this->param('code');
                $this->redirect($list . 'edit=' . rawurlencode($code) . '#' . CatalogueAddress::bundleAnchor($code));
            }
            return $this->rowFragment($actor, $id, true, $view, $list, $refused->getMessage(), $_POST, 422, arrange: ($_POST['arrange'] ?? '') === '1');
        }
        if (!$this->isHtmxRequest()) {
            $this->flash('success', 'The bundle was saved.');
            // The code may have changed: the anchor is the code it has now.
            $this->redirect(rtrim($list, '?&') . '#' . CatalogueAddress::bundleAnchor($this->code($id)));
        }
        return $this->rowFragment($actor, $id, false, $view, $list, saved: true, arrange: ($_POST['arrange'] ?? '') === '1');
    }

    #[Route('/admin/bundles/{code}/courses', name: 'admin_bundle_course_add', requirements: ['code' => CatalogueAddress::BUNDLE], methods: ['POST'])]
    public function addCourse(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = $this->bundleId();
        return $this->handle(function () use ($actor, $id): void {
            $title = $this->bundles->addCourse($actor, $id, (int) $this->posted('add_course_id'));
            $this->flash('success', $title . ' was added to the bundle.');
        }, $this->address($id, '#bundle-courses'));
    }

    #[Route('/admin/bundles/{code}/courses/{course_code}/remove', name: 'admin_bundle_course_remove', requirements: ['code' => CatalogueAddress::BUNDLE, 'course_code' => CourseAddress::REQUIREMENT], methods: ['POST'])]
    public function removeCourse(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = $this->bundleId();
        return $this->handle(function () use ($actor, $id): void {
            $this->bundles->removeCourse($actor, $id, $this->courseIdFromAddress($this->courses, 'course_code'));
            $this->flash('success', 'The course was removed from the bundle. Earlier purchases keep the courses they included.');
        }, $this->address($id, '#bundle-courses'));
    }

    #[Route('/admin/bundles/{code}/courses/{course_code}/move', name: 'admin_bundle_course_move', requirements: ['code' => CatalogueAddress::BUNDLE, 'course_code' => CourseAddress::REQUIREMENT], methods: ['POST'])]
    public function moveCourse(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = $this->bundleId();
        return $this->handle(function () use ($actor, $id): void {
            $this->bundles->moveCourse($actor, $id, $this->courseIdFromAddress($this->courses, 'course_code'), $this->posted('direction'));
        }, $this->address($id, '#bundle-courses'));
    }

    #[Route('/admin/bundles/{code}/offer', name: 'admin_bundle_offer', requirements: ['code' => CatalogueAddress::BUNDLE], methods: ['POST'])]
    public function offer(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = $this->bundleId();
        return $this->handle(function () use ($actor, $id): void {
            $this->bundles->saveOffer($actor, $id, $_POST);
            $this->flash('success', 'The bundle price was saved. It applies to purchases from now on; orders already placed keep the price they were placed at.');
        }, $this->address($id, '#bundle-offer'));
    }

    #[Route('/admin/bundles/{code}/delete', name: 'admin_bundle_delete', requirements: ['code' => CatalogueAddress::BUNDLE], methods: ['POST'])]
    public function delete(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = $this->bundleId();
        $back = $this->address($id);
        return $this->handle(function () use ($actor, $id): void {
            $this->bundles->delete($actor, $id);
            $this->flash('success', 'The bundle was deleted.');
            $this->redirect(self::BASE);
        }, $back);
    }

    /** The bundle the address names by its code, as its row id; a code no bundle has is not found. */
    private function bundleId(): int
    {
        return $this->keys->bundle($this->param('code')) ?? throw $this->notFound('This bundle does not exist.');
    }

    /** The bundle's code as it is now. */
    private function code(int $id): string
    {
        return (string) (($this->records->find($id) ?? throw $this->notFound('This bundle does not exist.'))['code']);
    }

    /** The bundle's page, at its code as it is now, with what follows it. */
    private function address(int $id, string $rest = ''): string
    {
        return CatalogueAddress::bundle($this->code($id), $rest);
    }

    /** @param array<string,mixed>|null $bundle */
    private function editor(?array $bundle): Response
    {
        $actor = $this->currentUser();
        $key = $bundle === null ? 'new' : (string) $bundle['id'];
        $draft = $_SESSION['bundle_draft'][$key] ?? null;
        unset($_SESSION['bundle_draft'][$key]);
        $form = $this->bundles->form($bundle);
        if (is_array($draft)) $form = array_merge($form, $draft);
        $data = ['title' => $bundle === null ? 'New bundle' : 'Bundle: ' . $bundle['title'], 'bundle' => null, 'form' => $form,
            'can_manage' => $actor !== null && $actor->hasPermission('BUNDLE.MANAGE'), 'currency' => Money::platformCurrency(), 'timezone' => date_default_timezone_get(),
            'units' => array_keys(AccessPeriod::UNITS)];
        if ($bundle !== null) {
            $presented = $this->bundles->present($bundle);
            $pagination = Pagination::create($_GET[PlatformAdministrationService::pageParam('sales')] ?? null, $_GET[PlatformAdministrationService::sizeParam('sales')] ?? null, $this->records->orderCount((int) $bundle['id']), 25);
            $presented['sales'] = array_map(static fn(array $sale): array => $sale + [
                'number' => \CattoLearning\Commerce\Domain\OrderNumber::format((int) $sale['order_id']),
                'price_label' => Money::strictMinorUnits((int) $sale['amount_minor'], (string) $sale['currency'])->format(),
                'paid_label' => Money::strictMinorUnits((int) $sale['amount_minor'] - (int) $sale['discount_minor'], (string) $sale['currency'])->format(),
            ], $this->records->sales((int) $bundle['id'], $pagination->pageSize, $pagination->offset));
            $presented['sales_pagination'] = PlatformAdministrationService::paginationPayload('sales', $pagination, CatalogueAddress::bundle($bundle), 'Bundle sales');
            $presented['sold'] = $this->records->sold((int) $bundle['id']);
            $data['bundle'] = $presented;
        }
        return $this->render('admin-bundle', $data);
    }

    /**
     * The row or card, shown or as its form, with what was typed and why it was refused after a refused save.
     *
     * @param array<string,mixed> $typed
     */
    private function rowFragment(CurrentUser $actor, int $id, bool $edit, string $view, string $list, string $error = '', array $typed = [], int $status = 200, bool $saved = false, bool $arrange = false): Response
    {
        $row = $this->records->listRow($id) ?? throw $this->notFound('This bundle does not exist.');
        $row = self::listed($row);
        $form = $edit ? $this->bundles->form($row) : null;
        if ($form !== null) {
            // What was typed stays in the form, so a refused save loses nothing.
            foreach (['code', 'title', 'status', 'price', 'was_price', 'available_from', 'available_until', 'access_period_value', 'access_period_unit'] as $field) {
                if (array_key_exists($field, $typed) && is_scalar($typed[$field])) $form[$field] = (string) $typed[$field];
            }
            if (is_scalar($typed['catalogue_position'] ?? null)) $form['position'] = (string) $typed['catalogue_position'];
        }
        $grid = $view === ListView::GRID;
        return $this->renderFragment('partials/admin/bundle-' . ($grid ? 'card' : 'row') . ($edit ? '-edit' : ''), [
            'bundle' => $row, 'bundle_edit' => $form, 'bundle_error' => $error, 'bundle_saved' => $saved,
            'can_manage' => $actor->hasPermission('BUNDLE.MANAGE'), 'bundles_list_href' => $list, 'units' => array_keys(AccessPeriod::UNITS), 'currency' => Money::platformCurrency(),
            'arrange' => $arrange && $actor->hasPermission('BUNDLE.MANAGE'),
        ], $status);
    }

    /**
     * A list row with what its cells say: the price with any was price, the access period, and the start and end of its
     * sale in the site's time zone (the time only when it is not midnight).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function listed(array $row): array
    {
        $currency = (string) ($row['currency_code'] ?? Money::platformCurrency());
        $marked = $row['price_minor_units'] === null ? ['was_label' => null] : WasPrice::labels((int) $row['price_minor_units'], $row['was_price_minor_units'] ?? null, $currency);
        $when = static function (mixed $at): ?string {
            if ($at === null || $at === '') return null;
            $local = (new DateTimeImmutable((string) $at))->setTimezone(new DateTimeZone(date_default_timezone_get()));
            return $local->format('j M Y') . ($local->format('H:i') === '00:00' ? '' : ' ' . $local->format('H:i'));
        };
        return $row + [
            'price_label' => $row['price_minor_units'] === null ? null : Money::strictMinorUnits((int) $row['price_minor_units'], $currency)->format(),
            'was_label' => $marked['was_label'],
            'access_label' => $row['access_period_seconds'] === null ? null : AccessPeriod::label((int) $row['access_period_seconds']),
            'from_label' => $when($row['available_from'] ?? null),
            'until_label' => $when($row['available_until'] ?? null),
        ];
    }

    /**
     * The bundle list's address to come back to, ready for another parameter: an address on this site's bundle list only
     * (the page the row was on, its page, search, filter and view kept), else the list itself; never with edit= in it.
     */
    private static function listHref(string $address): string
    {
        $path = (string) parse_url($address, PHP_URL_PATH);
        if ($path !== self::BASE) return self::BASE . '?';
        parse_str((string) parse_url($address, PHP_URL_QUERY), $query);
        unset($query['edit']);
        return self::BASE . '?' . ($query === [] ? '' : http_build_query($query) . '&');
    }

    private function keepDraft(string $key, RuntimeException $failed, string $back): Response
    {
        $draft = [];
        // The status is shown as it is, not as it was asked for: a refused one is easily chosen again.
        foreach (['code', 'title', 'slug', 'short_description', 'description_html', 'cover_svg', 'available_from', 'available_until'] as $field) {
            if (is_scalar($_POST[$field] ?? null)) $draft[$field] = (string) $_POST[$field];
        }
        if (is_scalar($_POST['catalogue_position'] ?? null)) $draft['position'] = (string) $_POST['catalogue_position'];
        $_SESSION['bundle_draft'][$key] = $draft;
        $this->flash('danger', $failed->getMessage());
        $this->redirect($back);
    }
}
