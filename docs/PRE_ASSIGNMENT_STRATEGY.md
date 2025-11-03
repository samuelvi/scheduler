# Estrategia de Pre-Asignación de Tareas

## Problema

Cuando hay miles de tareas programadas para el mismo instante (ej: 10:00:00), queremos:
1. **Pre-asignar** tareas a workers 5 minutos antes (09:55:00)
2. **Manejar tareas nuevas** que lleguen entre la pre-asignación y la ejecución
3. **Garantizar** que todas las tareas se ejecuten sin duplicados

## Solución 1: Estado "scheduled" + Re-balanceo (RECOMENDADA)

### Arquitectura

```
Estado de tareas:
- pending       → No asignada, esperando pre-asignación
- scheduled     → Pre-asignada a un worker, esperando hora de ejecución
- processing    → En ejecución ahora mismo
- completed     → Terminada exitosamente
- failed        → Fallida después de todos los intentos
```

### Flujo

```
09:55:00 - PRE-ASIGNACIÓN (Comando separado)
         ↓
         SELECT tasks WHERE scheduled_at BETWEEN '10:00:00' AND '10:00:59'
         AND status = 'pending'
         ↓
         500 tareas encontradas / 5 workers = 100 cada uno
         ↓
         UPDATE: status='scheduled', worker_id=1..5

09:57:00 - Nuevas tareas creadas (200 tareas)
         ↓
         Quedan en status='pending' (no fueron pre-asignadas)

10:00:00 - EJECUCIÓN
         ↓
         Worker 1-5: SELECT WHERE worker_id=X AND status='scheduled'
         ↓
         Procesan sus 100 tareas pre-asignadas
         ↓
         Worker 1-5: SELECT WHERE status='pending' (round-robin)
         ↓
         Distribuyen las 200 tareas nuevas on-the-fly
```

### Implementación

#### 1. Actualizar Entity con estado "scheduled"

```php
class ScheduledTask
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SCHEDULED = 'scheduled';  // ← NUEVO
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
}
```

#### 2. Comando de pre-asignación

```php
bin/console app:pre-assign-tasks --window=5
```

Ejecutar 5 minutos antes (cron):
```
55 * * * * cd /var/www/html && bin/console app:pre-assign-tasks --window=5
```

#### 3. Repository method

```php
/**
 * Pre-assign tasks for future execution window
 *
 * @param int $minutesAhead Look-ahead window (e.g., 5 minutes)
 * @param int $totalWorkers Number of workers
 */
public function preAssignTasksForWindow(int $minutesAhead, int $totalWorkers): int
{
    $conn = $this->getEntityManager()->getConnection();

    // Calculate time window
    $windowStart = new \DateTime("+{$minutesAhead} minutes");
    $windowEnd = (clone $windowStart)->modify('+1 minute');

    // Count tasks in window
    $totalPending = (int) $conn->executeQuery("
        SELECT COUNT(*)
        FROM scheduled_tasks
        WHERE scheduled_at >= :start
          AND scheduled_at < :end
          AND status = 'pending'
    ", [
        'start' => $windowStart->format('Y-m-d H:i:s'),
        'end' => $windowEnd->format('Y-m-d H:i:s')
    ])->fetchOne();

    if ($totalPending === 0) {
        return 0;
    }

    // Distribute fairly using round-robin
    $tasksPerWorker = (int) floor($totalPending / $totalWorkers);
    $remainder = $totalPending % $totalWorkers;

    $assigned = 0;
    for ($workerId = 1; $workerId <= $totalWorkers; $workerId++) {
        $myTaskCount = $tasksPerWorker + ($workerId <= $remainder ? 1 : 0);
        $offset = $assigned;

        $conn->executeStatement("
            UPDATE scheduled_tasks
            SET status = 'scheduled',
                worker_id = :worker_id
            WHERE id IN (
                SELECT id FROM (
                    SELECT id
                    FROM scheduled_tasks
                    WHERE scheduled_at >= :start
                      AND scheduled_at < :end
                      AND status = 'pending'
                    ORDER BY scheduled_at ASC, id ASC
                    LIMIT :limit OFFSET :offset
                ) AS subquery
            )
        ", [
            'worker_id' => $workerId,
            'start' => $windowStart->format('Y-m-d H:i:s'),
            'end' => $windowEnd->format('Y-m-d H:i:s'),
            'limit' => $myTaskCount,
            'offset' => $offset
        ]);

        $assigned += $myTaskCount;
    }

    return $assigned;
}
```

#### 4. Modificar assignTasksFairly para manejar ambos estados

```php
public function assignTasksFairly(int $workerId, int $totalWorkers): array
{
    $conn = $this->getEntityManager()->getConnection();

    // PRIORITY 1: Pre-assigned tasks (scheduled)
    $preAssigned = $conn->executeQuery("
        SELECT *
        FROM scheduled_tasks
        WHERE worker_id = :worker_id
          AND status = 'scheduled'
          AND scheduled_at <= NOW()
        ORDER BY scheduled_at ASC
    ", ['worker_id' => $workerId])->fetchAllAssociative();

    if (!empty($preAssigned)) {
        // Mark as processing
        $ids = array_column($preAssigned, 'id');
        $conn->executeStatement("
            UPDATE scheduled_tasks
            SET status = 'processing',
                attempts = attempts + 1,
                updated_at = NOW()
            WHERE id IN (:ids)
        ", [
            'ids' => $ids
        ], [
            'ids' => Connection::PARAM_INT_ARRAY
        ]);

        return $preAssigned;
    }

    // PRIORITY 2: New tasks (pending) - dynamic distribution
    // ... (código actual de assignTasksFairly)
}
```

### Ventajas

✅ Pre-carga en memoria antes del pico
✅ Tareas nuevas se manejan dinámicamente
✅ Sin overhead si no hay tareas nuevas
✅ Predicción de carga (sabes cuántas tareas vendrán)
✅ Escalado automático de workers si es necesario

### Desventajas

⚠️ Complejidad: Un estado adicional
⚠️ Comando extra en cron

---

## Solución 2: Pre-fetch con caché in-memory (Alternativa)

### Arquitectura

```
09:55:00 - Workers cargan tareas en memoria
         ↓
         Cada worker guarda en Redis/Memcached sus IDs
         ↓
         No actualizan el estado en DB todavía

10:00:00 - Workers procesan desde memoria
         ↓
         Marcan como processing en DB cuando empiezan
         ↓
         Tareas nuevas se asignan on-the-fly por otros workers
```

### Ventajas

✅ Sin estado adicional
✅ Más flexible

### Desventajas

❌ Necesita Redis/Memcached
❌ Más complejo de implementar
❌ Race conditions si worker muere

---

## Solución 3: Ventana deslizante sin pre-asignación

### Arquitectura

```
NO hacer pre-asignación

10:00:00 - Workers empiezan procesamiento
         ↓
         Distribución on-the-fly usando assignTasksFairly
         ↓
         Procesa TODO: pre-existentes + nuevas
         ↓
         Puede tardar 30-60 segundos en procesar el pico
```

### Ventajas

✅ Más simple
✅ Sin comando adicional
✅ Tareas nuevas se manejan automáticamente

### Desventajas

❌ Pico de carga en DB a las 10:00:00
❌ Retraso en procesamiento (30-60 seg)

---

## Comparación

| Solución | Complejidad | Retraso máximo | Race conditions | Nuevas tareas |
|----------|-------------|----------------|-----------------|---------------|
| **1. Estado scheduled** | Media | 0-5 seg | ✅ Ninguna | ✅ Se manejan |
| **2. Caché in-memory** | Alta | 0-5 seg | ⚠️ Si worker muere | ✅ Se manejan |
| **3. Sin pre-asignación** | Baja | 30-60 seg | ✅ Ninguna | ✅ Se manejan |

---

## Recomendación Final

### Para < 1,000 tareas/minuto:
**Solución 3** (sin pre-asignación) - Es suficiente

### Para 1,000 - 10,000 tareas/minuto:
**Solución 1** (estado scheduled) - Balance perfecto

### Para > 10,000 tareas/minuto:
**Solución 1 + 2** (scheduled + caché) - Máxima performance

---

## Ejemplo de implementación completa

### Crontab

```bash
# Pre-asignación 5 minutos antes
55 * * * * cd /app && bin/console app:pre-assign-tasks --window=5

# Workers procesando cada minuto
* * * * * cd /app && bin/console app:process-scheduled-tasks --worker-id=1 --total-workers=5
* * * * * cd /app && bin/console app:process-scheduled-tasks --worker-id=2 --total-workers=5
* * * * * cd /app && bin/console app:process-scheduled-tasks --worker-id=3 --total-workers=5
* * * * * cd /app && bin/console app:process-scheduled-tasks --worker-id=4 --total-workers=5
* * * * * cd /app && bin/console app:process-scheduled-tasks --worker-id=5 --total-workers=5
```

### O con Supervisor (mejor)

```ini
[program:scheduler-pre-assign]
command=/app/bin/console app:pre-assign-tasks --daemon --window=5 --sleep=60
numprocs=1
autostart=true
autorestart=true

[program:scheduler-worker]
command=/app/bin/console app:process-scheduled-tasks --daemon --worker-id=%(process_num)d --total-workers=5
numprocs=5
autostart=true
autorestart=true
```

---

## Métricas a monitorear

```
- overdue_count: Tareas que deberían haberse ejecutado
- pre_assigned_count: Tareas en estado 'scheduled'
- assignment_duration: Tiempo que tarda assignTasksFairly
- processing_lag: Diferencia entre scheduled_at y processed_at
```

---

## Conclusión

La **Solución 1 (estado scheduled)** es la más profesional y escalable. Combina:
- Pre-asignación eficiente
- Manejo automático de tareas nuevas
- Sin race conditions
- Monitoreable y debuggeable
