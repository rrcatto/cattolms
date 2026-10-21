<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HelpController extends BaseController
{
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
        return $this->render('faq', ['title' => 'Frequently asked questions']);
    }
}
