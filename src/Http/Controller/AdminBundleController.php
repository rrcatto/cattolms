<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Auth\AuthService;
use CattoLearning\Bundle\BundleRepository;
use CattoLearning\Bundle\BundleService;
use CattoLearning\Support\{AccessPeriod, Money, Pagination};
use CattoLearning\View\ThemeRenderer;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Course bundles in Administration: the list, the editor (details, ordered courses, offer, status)
 * and each bundle's sales. Every change is one ordinary form post, so the editor works without
 * JavaScript; only the course search uses the shared lookup. A failed details save keeps what was
 * entered in the session draft.
 */
final class AdminBundleController extends BaseController
{
    private const BASE = '/admin/bundles';
    private const STATUSES = ['' => 'All bundles', 'draft' => 'Draft', 'published' => 'On sale', 'off_sale' => 'Published, off sale', 'retired' => 'Retired'];

    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests,
        private readonly BundleService $bundles, private readonly BundleRepository $records)
    {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/admin/bundles', name: 'admin_bundles', methods: ['GET'])]
    public function index(): Response
    {
        $actor = $this->requirePermission('BUNDLE.MANAGEMENT.VIEW');
        $search = PlatformAdministrationService::searchTerm('bundles', $_GET);
        $status = is_string($_GET['status'] ?? null) && array_key_exists($_GET['status'], self::STATUSES) ? $_GET['status'] : '';
        $filters = ['search' => $search, 'status' => $status];
        $pagination = Pagination::create($_GET[PlatformAdministrationService::pageParam('bundles')] ?? null, $_GET[PlatformAdministrationService::sizeParam('bundles')] ?? null, $this->records->count($filters), 25);
        $rows = array_map(static fn(array $row): array => $row + [
            'price_label' => $row['price_minor_units'] === null ? null : Money::strictMinorUnits((int) $row['price_minor_units'], (string) $row['currency_code'])->format(),
            'access_label' => $row['access_period_seconds'] === null ? null : AccessPeriod::label((int) $row['access_period_seconds']),
        ], $this->records->list($filters, $pagination->pageSize, $pagination->offset));
        $chips = [];
        foreach (self::STATUSES as $value => $label) {
            $query = array_filter([PlatformAdministrationService::searchParam('bundles') => $search, 'status' => $value]);
            $chips[] = ['label' => $label, 'href' => self::BASE . ($query === [] ? '' : '?' . http_build_query($query)), 'active' => $status === $value];
        }
        return $this->render('admin-bundles', [
            'title' => 'Bundles', 'bundles' => $rows, 'bundles_search' => $search, 'status_filters' => $chips,
            'bundles_pagination' => PlatformAdministrationService::paginationPayload('bundles', $pagination, self::BASE, 'Bundles', array_filter([PlatformAdministrationService::searchParam('bundles') => $search, 'status' => $status])),
            'can_manage' => $actor->hasPermission('BUNDLE.MANAGE'),
        ]);
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
        try {
            $id = $this->bundles->create($actor, $_POST);
        } catch (RuntimeException $failed) {
            return $this->keepDraft('new', $failed, self::BASE . '/new');
        }
        $this->flash('success', 'The bundle was created as a draft. Add its courses and price, then publish it.');
        $this->redirect(self::BASE . '/' . $id);
    }

    #[Route('/admin/bundles/{id}', name: 'admin_bundle', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(): Response
    {
        $this->requirePermission('BUNDLE.MANAGEMENT.VIEW');
        return $this->editor($this->records->find((int) $this->param('id')) ?? throw $this->notFound('This bundle does not exist.'));
    }

    #[Route('/admin/bundles/{id}', name: 'admin_bundle_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = (int) $this->param('id');
        try {
            $this->bundles->updateDetails($actor, $id, $_POST);
        } catch (RuntimeException $failed) {
            return $this->keepDraft((string) $id, $failed, self::BASE . '/' . $id);
        }
        $this->flash('success', 'The bundle details were saved.');
        $this->redirect(self::BASE . '/' . $id);
    }

    #[Route('/admin/bundles/{id}/courses', name: 'admin_bundle_course_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addCourse(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = (int) $this->param('id');
        return $this->handle(function () use ($actor, $id): void {
            $title = $this->bundles->addCourse($actor, $id, (int) $this->posted('add_course_id'));
            $this->flash('success', $title . ' was added to the bundle.');
        }, self::BASE . '/' . $id . '#bundle-courses');
    }

    #[Route('/admin/bundles/{id}/courses/{course}/remove', name: 'admin_bundle_course_remove', requirements: ['id' => '\d+', 'course' => '\d+'], methods: ['POST'])]
    public function removeCourse(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = (int) $this->param('id');
        return $this->handle(function () use ($actor, $id): void {
            $this->bundles->removeCourse($actor, $id, (int) $this->param('course'));
            $this->flash('success', 'The course was removed from the bundle. Earlier purchases keep the courses they included.');
        }, self::BASE . '/' . $id . '#bundle-courses');
    }

    #[Route('/admin/bundles/{id}/courses/{course}/move', name: 'admin_bundle_course_move', requirements: ['id' => '\d+', 'course' => '\d+'], methods: ['POST'])]
    public function moveCourse(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = (int) $this->param('id');
        return $this->handle(function () use ($actor, $id): void {
            $this->bundles->moveCourse($actor, $id, (int) $this->param('course'), $this->posted('direction'));
        }, self::BASE . '/' . $id . '#bundle-courses');
    }

    #[Route('/admin/bundles/{id}/offer', name: 'admin_bundle_offer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function offer(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = (int) $this->param('id');
        return $this->handle(function () use ($actor, $id): void {
            $this->bundles->saveOffer($actor, $id, $_POST);
            $this->flash('success', 'The bundle price was saved. It applies to purchases from now on; orders already placed keep the price they were placed at.');
        }, self::BASE . '/' . $id . '#bundle-offer');
    }

    #[Route('/admin/bundles/{id}/status', name: 'admin_bundle_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function status(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = (int) $this->param('id');
        return $this->handle(function () use ($actor, $id): void {
            $status = $this->posted('status');
            $changed = $this->bundles->setStatus($actor, $id, $status);
            $this->flash('success', !$changed ? 'Nothing changed.' : match ($status) {
                'published' => 'The bundle is published.',
                'retired' => 'The bundle is retired and no longer sold. Courses already bought through it are not affected.',
                default => 'The bundle was returned to draft and is no longer shown.',
            });
        }, self::BASE . '/' . $id);
    }

    #[Route('/admin/bundles/{id}/delete', name: 'admin_bundle_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('BUNDLE.MANAGE');
        $id = (int) $this->param('id');
        return $this->handle(function () use ($actor, $id): void {
            $this->bundles->delete($actor, $id);
            $this->flash('success', 'The bundle was deleted.');
            $this->redirect(self::BASE);
        }, self::BASE . '/' . $id);
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
                'number' => 'CL-' . str_pad((string) $sale['order_id'], 8, '0', STR_PAD_LEFT),
                'price_label' => Money::strictMinorUnits((int) $sale['amount_minor'], (string) $sale['currency'])->format(),
                'paid_label' => Money::strictMinorUnits((int) $sale['amount_minor'] - (int) $sale['discount_minor'], (string) $sale['currency'])->format(),
            ], $this->records->sales((int) $bundle['id'], $pagination->pageSize, $pagination->offset));
            $presented['sales_pagination'] = PlatformAdministrationService::paginationPayload('sales', $pagination, self::BASE . '/' . $bundle['id'], 'Bundle sales');
            $presented['sold'] = $this->records->sold((int) $bundle['id']);
            $data['bundle'] = $presented;
        }
        return $this->render('admin-bundle', $data);
    }

    private function keepDraft(string $key, RuntimeException $failed, string $back): Response
    {
        $draft = [];
        foreach (['title', 'slug', 'short_description', 'description_html', 'cover_svg', 'available_from', 'available_until'] as $field) {
            if (is_scalar($_POST[$field] ?? null)) $draft[$field] = (string) $_POST[$field];
        }
        $_SESSION['bundle_draft'][$key] = $draft;
        $this->flash('danger', $failed->getMessage());
        $this->redirect($back);
    }
}
