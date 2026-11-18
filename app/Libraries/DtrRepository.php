<?php

namespace App\Libraries;

use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;

/**
 * Firestore access for `dtr_records`, the collection this app owns.
 */
class DtrRepository
{
    public const COLLECTION     = 'dtr_records';
    public const SCHEMA_VERSION = 1;

    public function __construct(private ?FirestoreClient $db = null)
    {
        $this->db ??= FirestoreService::db();
    }

    /** @return array<string, array> keyed by document id, ordered by date then employee */
    public function between(string $from, string $to): array
    {
        $out   = [];
        $query = $this->db->collection(self::COLLECTION)
            ->where('date', '>=', $from)
            ->where('date', '<=', $to);

        foreach ($query->documents() as $doc) {
            if ($doc->exists()) {
                $out[$doc->id()] = $doc->data();
            }
        }
        uasort($out, static fn ($a, $b) => [$a['date'], $a['employee_id']] <=> [$b['date'], $b['employee_id']]);

        return $out;
    }

    public function forDate(string $date): array
    {
        return $this->between($date, $date);
    }

    /**
     * Creates or replaces a pending record. Approved records are never overwritten.
     *
     * @return bool false when the existing record is already approved
     */
    public function savePending(array $record, string $userId): bool
    {
        $id  = DtrValidator::documentId($record['employee_id'], $record['date']);
        $ref = $this->db->collection(self::COLLECTION)->document($id);

        return $this->db->runTransaction(static function ($tx) use ($ref, $record, $userId) {
            $snap = $tx->snapshot($ref);
            if ($snap->exists() && ! empty($snap->data()['approved'])) {
                return false;
            }
            $now = new Timestamp(new \DateTime());
            $tx->set($ref, $record + [
                'approved'        => false,
                'supervisor_name' => null,
                'schema_version'  => self::SCHEMA_VERSION,
                'created_at'      => $snap->exists() ? ($snap->data()['created_at'] ?? $now) : $now,
                'updated_at'      => $now,
                'encoded_by'      => $userId,
            ]);

            return true;
        });
    }

    /**
     * Approves the given pending records.
     *
     * @param list<string> $ids
     *
     * @return int number of records approved
     */
    public function approve(array $ids, string $supervisorName, string $userId): int
    {
        $count = 0;
        foreach (array_unique($ids) as $id) {
            $ref  = $this->db->collection(self::COLLECTION)->document($id);
            $done = $this->db->runTransaction(static function ($tx) use ($ref, $supervisorName, $userId) {
                $snap = $tx->snapshot($ref);
                if (! $snap->exists() || ! empty($snap->data()['approved'])) {
                    return false;
                }
                $tx->update($ref, [
                    ['path' => 'approved', 'value' => true],
                    ['path' => 'supervisor_name', 'value' => $supervisorName],
                    ['path' => 'approved_by', 'value' => $userId],
                    ['path' => 'approved_at', 'value' => new Timestamp(new \DateTime())],
                ]);

                return true;
            });
            $count += $done ? 1 : 0;
        }

        return $count;
    }
}
