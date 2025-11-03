<?php

namespace App\Entity;

use App\Repository\ScheduledTaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScheduledTaskRepository::class)]
#[ORM\Table(name: 'scheduled_tasks')]
#[ORM\Index(columns: ['scheduled_at', 'status'], name: 'idx_scheduled_status')]
#[ORM\Index(columns: ['status', 'attempts'], name: 'idx_status_attempts')]
#[ORM\Index(columns: ['worker_id', 'status'], name: 'idx_worker_status')]
#[ORM\HasLifecycleCallbacks]
class ScheduledTask
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $useCase = null;

    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $scheduledAt = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(nullable: true)]
    private ?int $workerId = null;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column]
    private int $maxAttempts = 3;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $processedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUseCase(): ?string
    {
        return $this->useCase;
    }

    public function setUseCase(string $useCase): static
    {
        $this->useCase = $useCase;

        return $this;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setPayload(array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    public function getScheduledAt(): ?\DateTimeInterface
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(\DateTimeInterface $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): static
    {
        $this->attempts = $attempts;

        return $this;
    }

    public function incrementAttempts(): static
    {
        $this->attempts++;

        return $this;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $maxAttempts): static
    {
        $this->maxAttempts = $maxAttempts;

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): static
    {
        $this->lastError = $lastError;

        return $this;
    }

    public function getProcessedAt(): ?\DateTimeInterface
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?\DateTimeInterface $processedAt): static
    {
        $this->processedAt = $processedAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function markAsProcessing(): static
    {
        $this->status = self::STATUS_PROCESSING;
        $this->incrementAttempts();
        $this->updatedAt = new \DateTime();

        return $this;
    }

    public function markAsCompleted(): static
    {
        $this->status = self::STATUS_COMPLETED;
        $this->processedAt = new \DateTime();
        $this->updatedAt = new \DateTime();

        return $this;
    }

    public function markAsFailed(string $error): static
    {
        $this->status = self::STATUS_FAILED;
        $this->lastError = $error;
        $this->processedAt = new \DateTime();
        $this->updatedAt = new \DateTime();

        return $this;
    }

    public function canRetry(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }

    public function getWorkerId(): ?int
    {
        return $this->workerId;
    }

    public function setWorkerId(?int $workerId): static
    {
        $this->workerId = $workerId;

        return $this;
    }
}
