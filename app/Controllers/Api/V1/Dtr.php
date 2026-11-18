<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Libraries\DtrRepository;

/**
 * GET /api/v1/dtr?from=YYYY-MM-DD&to=YYYY-MM-DD[&employee_id=EMP001]
 *
 * v1.1 (additive): optional employee_id filter; pending entries carry the
 * same fields as approved ones.
 *
 * Integration Point 4 (Attendance -> Payroll). Returns APPROVED records in
 * the range, plus the pending records so Payroll can warn the operator.
 *
 * Contract v1 (additive changes only within v1):
 * {
 *   "api_version": "v1", "from": "...", "to": "...",
 *   "count": 2, "data": [ {employee_id, date, schedule_type, late_minutes,
 *                          undertime_minutes, ot_hours, is_absent,
 *                          approved, supervisor_name}, ... ],
 *   "pending_count": 1, "pending": [ {employee_id, date}, ... ]
 * }
 */
class Dtr extends BaseController
{
    private const MAX_RANGE_DAYS = 31;

    private const PUBLIC_FIELDS = [
        'employee_id', 'date', 'schedule_type', 'late_minutes', 'undertime_minutes',
        'ot_hours', 'is_absent', 'approved', 'supervisor_name',
    ];

    public function index()
    {
        $from = (string) $this->request->getGet('from');
        $to   = (string) $this->request->getGet('to');

        $f = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $t = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if (! $f || ! $t || $f->format('Y-m-d') !== $from || $t->format('Y-m-d') !== $to) {
            return $this->error(422, 'validation', 'from and to must be YYYY-MM-DD dates.');
        }
        if ($f > $t || $f->diff($t)->days >= self::MAX_RANGE_DAYS) {
            return $this->error(422, 'validation', 'from must be on or before to, and the range at most ' . self::MAX_RANGE_DAYS . ' days.');
        }

        try {
            $records = (new DtrRepository())->between($from, $to);
        } catch (\Throwable $e) {
            log_message('error', 'API dtr: ' . $e->getMessage());

            return $this->error(503, 'unavailable', 'DTR data is temporarily unavailable.');
        }

        $employeeId = (string) $this->request->getGet('employee_id');

        $approved = [];
        $pending  = [];
        foreach ($records as $r) {
            if ($employeeId !== '' && $r['employee_id'] !== $employeeId) {
                continue;
            }
            $row = [];
            foreach (self::PUBLIC_FIELDS as $k) {
                $row[$k] = $r[$k] ?? null;
            }
            $row['late_minutes']      = (int) $row['late_minutes'];
            $row['undertime_minutes'] = (int) $row['undertime_minutes'];
            $row['ot_hours']          = (float) $row['ot_hours'];
            $row['is_absent']         = (bool) $row['is_absent'];
            $row['approved']          = ! empty($r['approved']);

            if ($row['approved']) {
                $approved[] = $row;
            } else {
                $pending[] = $row; // v1 guaranteed employee_id + date; other fields added in v1.1
            }
        }

        return $this->response->setJSON([
            'api_version'   => 'v1',
            'from'          => $from,
            'to'            => $to,
            'count'         => count($approved),
            'data'          => $approved,
            'pending_count' => count($pending),
            'pending'       => $pending,
        ]);
    }

    private function error(int $status, string $code, string $message)
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => ['code' => $code, 'message' => $message]]);
    }
}
