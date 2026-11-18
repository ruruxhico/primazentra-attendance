<?php

namespace App\Libraries;

use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;

/**
 * Pay periods whose payroll is finalized (collection `period_locks`, owned
 * by this app). Set and cleared only by events from hris-payroll. While a
 * period is locked its DTR cannot be created, edited or approved, so a
 * finalized payroll always matches the attendance it was computed from.
 */
class PeriodLockRepository
{
    public const COLLECTION = 'period_locks';

    /** @var array<string, array|null> per-request cache */
    private array $cache = [];

    public function __construct(private ?FirestoreClient $db = null)
    {
        $this->db ??= FirestoreService::db();
    }

    public function lockFor(string $periodId): ?array
    {
        if (! array_key_exists($periodId, $this->cache)) {
            $snap                    = $this->db->collection(self::COLLECTION)->document($periodId)->snapshot();
            $this->cache[$periodId] = $snap->exists() ? $snap->data() : null;
        }

        return $this->cache[$periodId];
    }

    public function isLocked(string $date): bool
    {
        return $this->lockFor(PayPeriod::containing($date)->id) !== null;
    }

    public function lock(string $periodId, string $byName): void
    {
        $this->db->collection(self::COLLECTION)->document($periodId)->set([
            'period_id'   => $periodId,
            'locked_by'   => $byName,
            'locked_at'   => new Timestamp(new \DateTime()),
        ]);
        unset($this->cache[$periodId]);
    }

    public function unlock(string $periodId): void
    {
        $this->db->collection(self::COLLECTION)->document($periodId)->delete();
        unset($this->cache[$periodId]);
    }
}
