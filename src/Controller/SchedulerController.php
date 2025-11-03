<?php

namespace App\Controller;

use App\Entity\ScheduledTask;
use App\Repository\ScheduledTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/scheduler', name: 'scheduler_')]
class SchedulerController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ScheduledTaskRepository $taskRepository
    ) {
    }

    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $stats = $this->taskRepository->getStatistics();
        $overdueCount = $this->taskRepository->getOverdueCount();

        return $this->json([
            'stats' => $stats,
            'overdue_count' => $overdueCount,
            'timestamp' => time()
        ]);
    }

    #[Route('/tasks', name: 'create_task', methods: ['POST'])]
    public function createTask(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Validate required fields
        if (!isset($data['use_case']) || !isset($data['payload']) || !isset($data['scheduled_at'])) {
            return $this->json([
                'error' => 'Missing required fields: use_case, payload, scheduled_at'
            ], 400);
        }

        try {
            $scheduledAt = new \DateTime($data['scheduled_at']);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Invalid scheduled_at format. Use ISO 8601 format (e.g., 2025-01-03T10:00:00)'
            ], 400);
        }

        $task = new ScheduledTask();
        $task->setUseCase($data['use_case']);
        $task->setPayload($data['payload']);
        $task->setScheduledAt($scheduledAt);

        if (isset($data['max_attempts'])) {
            $task->setMaxAttempts((int) $data['max_attempts']);
        }

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Task created successfully',
            'task' => [
                'id' => $task->getId(),
                'use_case' => $task->getUseCase(),
                'scheduled_at' => $task->getScheduledAt()->format('c'),
                'status' => $task->getStatus()
            ]
        ], 201);
    }

    #[Route('/tasks/{id}', name: 'get_task', methods: ['GET'])]
    public function getTask(int $id): JsonResponse
    {
        $task = $this->taskRepository->find($id);

        if (!$task) {
            return $this->json(['error' => 'Task not found'], 404);
        }

        return $this->json([
            'id' => $task->getId(),
            'use_case' => $task->getUseCase(),
            'payload' => $task->getPayload(),
            'scheduled_at' => $task->getScheduledAt()->format('c'),
            'status' => $task->getStatus(),
            'attempts' => $task->getAttempts(),
            'max_attempts' => $task->getMaxAttempts(),
            'last_error' => $task->getLastError(),
            'processed_at' => $task->getProcessedAt()?->format('c'),
            'created_at' => $task->getCreatedAt()->format('c'),
            'updated_at' => $task->getUpdatedAt()->format('c')
        ]);
    }

    #[Route('/tasks', name: 'list_tasks', methods: ['GET'])]
    public function listTasks(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        $limit = min((int) $request->query->get('limit', 20), 100);
        $offset = (int) $request->query->get('offset', 0);

        $qb = $this->taskRepository->createQueryBuilder('t')
            ->orderBy('t.scheduledAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($status) {
            $qb->where('t.status = :status')
                ->setParameter('status', $status);
        }

        $tasks = $qb->getQuery()->getResult();

        $result = array_map(fn(ScheduledTask $task) => [
            'id' => $task->getId(),
            'use_case' => $task->getUseCase(),
            'scheduled_at' => $task->getScheduledAt()->format('c'),
            'status' => $task->getStatus(),
            'attempts' => $task->getAttempts(),
        ], $tasks);

        return $this->json([
            'tasks' => $result,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }
}
