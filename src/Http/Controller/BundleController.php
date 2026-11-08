<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Auth\AuthService;
use CattoLearning\Bundle\BundleRepository;
use CattoLearning\Bundle\BundleService;
use CattoLearning\Commerce\Application\OrderService;
use CattoLearning\Commerce\Application\PromotionService;
use CattoLearning\Course\CourseFavouriteService;
use CattoLearning\Support\ListView;
use CattoLearning\Support\Pagination;
use RuntimeException;
use CattoLearning\View\ThemeRenderer;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public bundle catalogue. A bundle page shows its courses with the catalogue's own course cards
 * and is bought through the ordinary cart and checkout. A signed-in reader stars a bundle as a course is starred
 * (owner, 2026-11-06), on its card and on its page, and finds it on Account > Favourites.
 */
final class BundleController extends BaseController
{
    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests,
        private readonly BundleService $bundles, private readonly BundleRepository $records, private readonly OrderService $orders,
        private readonly PromotionService $promotions, private readonly \CattoLearning\Search\PageMetadata $metadata,
        private readonly CourseFavouriteService $courseFavourites)
    {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/bundles', name: 'bundles', methods: ['GET'])]
    public function index(): Response
    {
        $pagination = Pagination::create($this->request()->query->get('bundles_page'), $this->request()->query->get('bundles_page_size'), $this->records->publishedCount(), 24);
        // The bundles' own cards or their list, as the reader chose for the catalogue's bundles too (owner, 2026-11-07).
        $query = $this->request()->query->all();
        $view = ListView::chosen('catalogue_bundles', $query, $this->request()->cookies->all());
        ListView::rememberAsked('catalogue_bundles', $query);
        return $this->render('bundles', [
            'title' => 'Course bundles', 'active_nav' => 'catalogue',
            'bundles' => $this->bundles->catalogue($pagination->pageSize, $pagination->offset, $this->currentUser()),
            'bundles_view' => $view,
            'bundles_view_switch' => ListView::switcher('catalogue_bundles', $view, '/bundles'),
            'bundles_pagination' => PlatformAdministrationService::paginationPayload('bundles', $pagination, '/bundles', 'Course bundles'),
            // Each card's star (owner, 2026-11-06).
            'can_favourite' => $this->currentUser() !== null,
            'favourite_return' => $this->requests->getCurrentRequest()?->getRequestUri() ?? '/bundles',
        ]);
    }

    /**
     * A bundle's own drawing as a picture, the strip across the top of its card (owner, 2026-11-07, the approved mockup).
     * Its address carries the drawing's version, so it may be kept for good.
     */
    #[Route('/bundles/{slug}/cover.svg', name: 'bundle_cover_picture', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function cover(): Response
    {
        $cover = $this->records->publicCover((string) $this->param('slug')) ?? throw $this->notFound('There is no such picture.');
        return \CattoLearning\Http\PictureResponse::of($cover, 'image/svg+xml', true);
    }

    #[Route('/bundles/{slug}', name: 'bundle_detail', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function show(): Response
    {
        $bundle = $this->bundles->detail((string) $this->param('slug'), $this->currentUser()) ?? throw $this->notFound('This bundle does not exist.');
        // Its courses' stars and its own, for a signed-in reader (owner, 2026-11-06).
        $bundle['course_cards'] = $this->courseFavourites->markFavourites($this->currentUser()?->id, $bundle['course_cards']);
        // A company account never buys (OrderService::COMPANY_ACCOUNT): it reads why instead of the buttons.
        $mayBuy = $this->orders->mayBuy($this->currentUser());
        // The automatic promotions that cover the bundle are announced beside its price (owner, 2026-10-20).
        $promotions = $mayBuy && $bundle['offer_id'] !== null && $bundle['unavailable'] === null
            ? $this->promotions->announcements('bundle', (int) $bundle['id'], (int) $bundle['price_minor_units'], (string) $bundle['currency_code'], $this->currentUser()?->id) : [];
        // What its head tells search engines, AI assistants and the sites it is shared on (owner, 2026-11-06).
        return $this->render('bundle-detail', ['title' => (string) $bundle['title'], 'active_nav' => 'catalogue', 'bundle' => $bundle, 'page_meta' => $this->metadata->bundle($bundle),
            'company_note' => $mayBuy ? '' : OrderService::COMPANY_ACCOUNT, 'promotions' => $promotions,
            'can_favourite' => $this->currentUser() !== null, 'favourite_return' => '/bundles/' . (string) $bundle['slug']]);
    }

    /**
     * Stars or unstars a bundle (owner, 2026-11-06: "Allow bundles to be added to favourites - it must appear in course
     * favourites"), as a course's star does: the form asks for a state, so a repeated request changes nothing; htmx gets
     * the star back in place, and without JavaScript the page it was on comes back.
     */
    #[Route('/bundles/favourite', name: 'bundle_toggle_favourite', methods: ['POST'])]
    public function toggleFavourite(): Response
    {
        $this->requireCsrf();
        $user = $this->requirePermission('CATALOGUE.COURSE.FAVOURITE');
        $bundleId = max(1, (int) ($_POST['bundle_id'] ?? 0));
        $wanted = (string) ($_POST['favourite'] ?? '');
        $return = (string) ($_POST['return'] ?? '/bundles');
        $return = str_starts_with($return, '/') && !str_starts_with($return, '//') ? $return : '/bundles';
        $failed = !in_array($wanted, ['0', '1'], true);
        if (!$failed) {
            try {
                $this->bundles->setFavourite($user->id, $bundleId, $wanted === '1');
            } catch (RuntimeException) {
                $failed = true;
            }
        }
        if ($this->isHtmxRequest() && !$failed) {
            return $this->renderFragment('partials/course-favourite', ['course' => ['bundle_id' => $bundleId, 'is_favourite' => $wanted === '1'], 'favourite_kind' => 'bundle', 'favourite_return' => $return]);
        }
        if ($failed) $this->flash('error', 'That bundle could not be added to your favourites.');
        $this->redirect($return);
    }
}
