<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    #[Route('/health', name: 'health_check', methods: ['GET'])]
    public function check(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'timestamp' => time(),
            'service' => 'Scheduler API'
        ]);
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): JsonResponse
    {
        return $this->json([
            'message' => 'Welcome to Scheduler API',
            'version' => '1.0.0'
        ]);
    }
}
