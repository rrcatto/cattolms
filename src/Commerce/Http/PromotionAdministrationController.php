<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Http;

use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Auth\AuthService;
use CattoLearning\Commerce\Application\PromotionAdministrationService;
use CattoLearning\Commerce\Domain\Promotion;
use CattoLearning\Commerce\Domain\OrderTotals;
use CattoLearning\Commerce\Infrastructure\PromotionRepository;
use CattoLearning\Http\Controller\BaseController;
use CattoLearning\Support\Money;
use CattoLearning\Support\Pagination;
use CattoLearning\View\ThemeRenderer;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Promotions in Administration: the list with its search and status filter, the editor, usage, and
 * activation. A save that fails keeps what was entered: the submitted fields wait in the session
 * draft for the editor to show them again with the reason.
 */
final class PromotionAdministrationController extends BaseController
{
    private const BASE = '/admin/promotions';
    private const STATUSES = ['' => 'All promotions', 'active' => 'Active now', 'scheduled' => 'Scheduled', 'inactive' => 'Deactivated', 'ended' => 'Ended'];

    public function __construct(
        AuthService $auth,
        ThemeRenderer $view,
        RequestStack $requests,
        private readonly PromotionAdministrationService $administration,
        private readonly PromotionRepository $promotions,
    ) {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/admin/promotions', name: 'admin_promotions', methods: ['GET'])]
    public function index(): Response
    {
        $actor = $this->requirePermission('PLATFORM.PROMOTION.VIEW');
        $search = PlatformAdministrationService::searchTerm('promotions', $_GET);
        $status = is_string($_GET['status'] ?? null) && array_key_exists($_GET['status'], self::STATUSES) ? $_GET['status'] : '';
        $now = $this->administration->now();
        $filters = ['search' => $search, 'status' => $status];
        $pagination = Pagination::create($_GET[PlatformAdministrationService::pageParam('promotions')] ?? null, $_GET[PlatformAdministrationService::sizeParam('promotions')] ?? null,
            $this->promotions->count($filters, $now->format(DATE_ATOM)), 25);
        $rows = [];
        foreach ($this->promotions->list($filters, $now->format(DATE_ATOM), $pagination->pageSize, $pagination->offset) as $row) {
            $promotion = Promotion::fromRow($row);
            $rows[] = ['scope' => Promotion::describeScope($promotion->courseScope, $promotion->bundleScope, (int) $row['course_count'], (int) $row['bundle_count'])]
                + self::row($promotion, $now) + ['redeemed' => (int) $row['redeemed'], 'pending' => (int) $row['pending']];
        }
        $query = array_filter([PlatformAdministrationService::searchParam('promotions') => $search, 'status' => $status], static fn(string $value): bool => $value !== '');
        return $this->render('admin-promotions', [
            'title' => 'Promotions',
            'promotions' => $rows,
            'promotions_search' => $search,
            'status_filters' => self::statusFilters($status, array_filter([PlatformAdministrationService::searchParam('promotions') => $search])),
            'promotions_pagination' => PlatformAdministrationService::paginationPayload('promotions', $pagination, self::BASE, 'Promotions', $query),
            'can_manage' => $actor->hasPermission('PLATFORM.PROMOTION.MANAGE'),
        ]);
    }

    #[Route('/admin/promotions/new', name: 'admin_promotion_new', methods: ['GET'])]
    public function create(): Response
    {
        $this->requirePermission('PLATFORM.PROMOTION.MANAGE');
        return $this->editor(null);
    }

    #[Route('/admin/promotions', name: 'admin_promotion_store', methods: ['POST'])]
    public function store(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('PLATFORM.PROMOTION.MANAGE');
        try {
            $id = $this->administration->create($actor, $_POST);
        } catch (RuntimeException $failed) {
            return $this->keepDraft('new', $failed, self::BASE . '/new');
        }
        $this->flash('success', 'The promotion was created.');
        $this->redirect(self::BASE . '/' . $id);
    }

    #[Route('/admin/promotions/{id}', name: 'admin_promotion', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(): Response
    {
        $this->requirePermission('PLATFORM.PROMOTION.VIEW');
        $promotion = $this->promotions->find((int) $this->param('id')) ?? throw $this->notFound('This promotion does not exist.');
        return $this->editor($promotion);
    }

    #[Route('/admin/promotions/{id}', name: 'admin_promotion_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('PLATFORM.PROMOTION.MANAGE');
        $id = (int) $this->param('id');
        try {
            $this->administration->update($actor, $id, $_POST);
        } catch (RuntimeException $failed) {
            return $this->keepDraft((string) $id, $failed, self::BASE . '/' . $id);
        }
        $this->flash('success', 'The promotion was saved. Orders already placed keep the promotion as it was when they were placed.');
        $this->redirect(self::BASE . '/' . $id);
    }

    #[Route('/admin/promotions/{id}/status', name: 'admin_promotion_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function status(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('PLATFORM.PROMOTION.MANAGE');
        $id = (int) $this->param('id');
        $return = (string) ($_POST['return'] ?? '');
        $return = str_starts_with($return, self::BASE) ? $return : self::BASE . '/' . $id;
        return $this->handle(function () use ($actor, $id): void {
            $active = ($_POST['active'] ?? '') === '1';
            $changed = $this->administration->setActive($actor, $id, $active);
            $this->flash('success', !$changed ? 'Nothing changed: the promotion was already ' . ($active ? 'active.' : 'deactivated.')
                : ($active ? 'The promotion is active. Its code can be used within its dates and limits.' : 'The promotion is deactivated. Its code can no longer be applied, and an order that has not been placed yet cannot use it.'));
        }, $return);
    }

    #[Route('/admin/promotions/{id}/delete', name: 'admin_promotion_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('PLATFORM.PROMOTION.MANAGE');
        $id = (int) $this->param('id');
        return $this->handle(function () use ($actor, $id): void {
            $this->administration->delete($actor, $id);
            $this->flash('success', 'The promotion was deleted.');
            $this->redirect(self::BASE);
        }, self::BASE . '/' . $id);
    }

    private function editor(?Promotion $promotion): Response
    {
        $actor = $this->currentUser();
        $key = $promotion === null ? 'new' : (string) $promotion->id;
        $draft = $_SESSION['promotion_draft'][$key] ?? null;
        unset($_SESSION['promotion_draft'][$key]);
        $form = is_array($draft) ? $this->administration->formFromInput($draft) : $this->administration->form($promotion);
        $now = $this->administration->now();
        $data = [
            'title' => $promotion === null ? 'New promotion' : 'Promotion ' . $promotion->code,
            'promotion' => $promotion === null ? null : self::row($promotion, $now),
            'form' => $form,
            'can_manage' => $actor !== null && $actor->hasPermission('PLATFORM.PROMOTION.MANAGE'),
            'currency' => Money::platformCurrency(),
            'timezone' => date_default_timezone_get(),
            'usage' => null,
        ];
        if ($promotion !== null) {
            $summary = $this->promotions->usageSummary($promotion->id);
            $pagination = Pagination::create($_GET[PlatformAdministrationService::pageParam('usage')] ?? null, $_GET[PlatformAdministrationService::sizeParam('usage')] ?? null, $this->promotions->orderCount($promotion->id), 25);
            $orders = [];
            foreach ($this->promotions->orders($promotion->id, $pagination->pageSize, $pagination->offset) as $order) {
                $orders[] = $order + [
                    'number' => 'CL-' . str_pad((string) $order['id'], 8, '0', STR_PAD_LEFT),
                    'total_label' => Money::strictMinorUnits((int) $order['total_minor'], (string) $order['currency'])->format(),
                    'discount_label' => OrderTotals::negative((int) $order['discount_minor'], (string) $order['currency']),
                ];
            }
            $data['usage'] = $summary + [
                'discount_label' => Money::strictMinorUnits($summary['discount_minor'], Money::platformCurrency())->format(),
                'orders' => $orders,
                'pagination' => PlatformAdministrationService::paginationPayload('usage', $pagination, self::BASE . '/' . $promotion->id, 'Orders with this promotion'),
                'deletable' => $this->promotions->orderCount($promotion->id) === 0,
            ];
        }
        return $this->render('admin-promotion', $data);
    }

    private function keepDraft(string $key, RuntimeException $failed, string $back): Response
    {
        $draft = [];
        foreach (['code','name','description','active','discount_type','discount_value','minimum_order','maximum_total_uses','maximum_uses_per_customer','starts_at','ends_at','course_scope','bundle_scope','add_course_id','add_bundle_id'] as $field) {
            if (is_scalar($_POST[$field] ?? null)) $draft[$field] = mb_substr((string) $_POST[$field], 0, 2000);
        }
        $draft['course_ids'] = array_values(array_filter((array) ($_POST['course_ids'] ?? []), 'is_scalar'));
        $draft['bundle_ids'] = array_values(array_filter((array) ($_POST['bundle_ids'] ?? []), 'is_scalar'));
        $_SESSION['promotion_draft'][$key] = $draft;
        $this->flash('danger', $failed->getMessage());
        $this->redirect($back);
    }

    /**
     * What the list and the editor show about a promotion's definition.
     *
     * @return array<string,mixed>
     */
    private static function row(Promotion $promotion, \DateTimeImmutable $now): array
    {
        $time = static fn(?\DateTimeImmutable $value): ?string => $value?->setTimezone(PromotionAdministrationService::timezone())->format('j M Y H:i');
        $money = static fn(?int $minor): ?string => $minor === null ? null : Money::strictMinorUnits($minor, (string) ($promotion->currency ?? Money::platformCurrency()))->format();
        $from = $time($promotion->startsAt);
        $until = $time($promotion->endsAt);
        return [
            'id' => $promotion->id, 'code' => $promotion->code, 'name' => $promotion->name, 'description' => $promotion->description,
            'discount' => $promotion->discountLabel(), 'active' => $promotion->active, 'status' => $promotion->status($now),
            'validity' => match (true) {
                $from !== null && $until !== null => $from . ' until ' . $until,
                $from !== null => 'From ' . $from,
                $until !== null => 'Until ' . $until,
                default => 'No dates',
            },
            'minimum' => $money($promotion->minimumOrderMinor),
            'maximum_total_uses' => $promotion->maximumTotalUses, 'maximum_uses_per_customer' => $promotion->maximumUsesPerCustomer,
            'scope' => $promotion->scopeLabel(),
        ];
    }

    /**
     * @param array<string,string> $carried
     * @return list<array{label:string,href:string,active:bool}>
     */
    private static function statusFilters(string $selected, array $carried): array
    {
        $chips = [];
        foreach (self::STATUSES as $status => $label) {
            $query = $carried + ($status === '' ? [] : ['status' => $status]);
            $chips[] = ['label' => $label, 'href' => self::BASE . ($query === [] ? '' : '?' . http_build_query($query)), 'active' => $selected === $status];
        }
        return $chips;
    }
}
