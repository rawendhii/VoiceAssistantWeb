<?php

namespace App\Controller\Admin;

use App\Service\AdminDashboardStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AdminDashboardController extends AbstractController
{
    #[Route('/admin/dashboard', name: 'admin_dashboard')]
    public function index(AdminDashboardStatsService $statsService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/dashboard/index.html.twig', $statsService->getStats());
    }
}