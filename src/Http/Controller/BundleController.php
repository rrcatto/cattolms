<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Auth\AuthService;
use CattoLearning\Bundle\BundleRepository;
use CattoLearning\Bundle\BundleService;
use CattoLearning\Support\Pagination;
use CattoLearning\View\ThemeRenderer;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public bundle catalogue. A bundle page shows its courses with the catalogue's own course cards
 * and is bought through the ordinary cart and checkout.
 */
final class BundleController extends BaseController
{
    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests,
        private readonly BundleService $bundles, private readonly BundleRepository $records)
    {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/bundles', name: 'bundles', methods: ['GET'])]
    public function index(): Response
    {
        $pagination = Pagination::create($this->request()->query->get('bundles_page'), $this->request()->query->get('bundles_page_size'), $this->records->publishedCount(), 24);
        return $this->render('bundles', [
            'title' => 'Course bundles', 'active_nav' => 'catalogue',
            'bundles' => $this->bundles->catalogue($pagination->pageSize, $pagination->offset),
            'bundles_pagination' => PlatformAdministrationService::paginationPayload('bundles', $pagination, '/bundles', 'Course bundles'),
        ]);
    }

    #[Route('/bundles/{slug}', name: 'bundle_detail', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function show(): Response
    {
        $bundle = $this->bundles->detail((string) $this->param('slug'), $this->currentUser()) ?? throw $this->notFound('This bundle does not exist.');
        return $this->render('bundle-detail', ['title' => (string) $bundle['title'], 'active_nav' => 'catalogue', 'bundle' => $bundle]);
    }
}
