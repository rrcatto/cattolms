<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HelpController extends BaseController
{
    public function __construct(\CattoLearning\Auth\AuthService $auth, \CattoLearning\View\ThemeRenderer $view, \Symfony\Component\HttpFoundation\RequestStack $requests,
        private readonly \CattoLearning\Help\Faq $faq, private readonly \CattoLearning\Search\PageMetadata $metadata)
    {
        parent::__construct($auth, $view, $requests);
    }

    private const FAQ_DESCRIPTION = 'Short answers about buying, paying, access, grading, certificates and refunds.';

    #[Route('/help', name: 'help_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('help', [
            'title' => 'Help',
            'page_kicker' => 'How to find, buy and use courses',
        ]);
    }

    /** Frequently asked questions, each answer closed until its question is chosen (owner, 2026-10-20). */
    #[Route('/faq', name: 'help_faq', methods: ['GET'])]
    public function faq(): Response
    {
        // The questions are Help\Faq's, which the page draws and its FAQPage structured data marks up (owner, 2026-11-06).
        return $this->render('faq', ['title' => 'Frequently asked questions', 'faq_questions' => $this->faq->questions(),
            'page_meta' => $this->metadata->faq($this->faq->answered((string) \CattoLearning\Search\PageMetadata::base()), self::FAQ_DESCRIPTION)]);
    }
}
