<?php

namespace App\Libraries;

/**
 * Validation for a Daily Time Record (Integration Point 1 in the M4 spec).
 *
 * Stored shape (Firestore `dtr_records`, document id "{employee_id}_{date}"):
 *   employee_id, employee_name, date (Y-m-d), schedule_type ("8hrs"|"9.6hrs"),
 *   late_minutes, undertime_minutes, ot_hours, is_absent, approved,
 *   supervisor_name, schema_version
 *
 * Attendance records raw minutes only. The 15-minute grace period and all
 * peso amounts are payroll rules and live in hris-payroll.
 */
class DtrValidator
{
    public const SCHEDULE_TYPES = ['8hrs', '9.6hrs'];
    public const MAX_OT_HOURS   = 16;

    public static function normalise(array $in): array
    {
        $absent = filter_var($in['is_absent'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'employee_id'       => trim((string) ($in['employee_id'] ?? '')),
            'date'              => trim((string) ($in['date'] ?? '')),
            'schedule_type'     => trim((string) ($in['schedule_type'] ?? '')),
            'late_minutes'      => self::toNumber($in['late_minutes'] ?? 0, true),
            'undertime_minutes' => self::toNumber($in['undertime_minutes'] ?? 0, true),
            'ot_hours'          => self::toNumber($in['ot_hours'] ?? 0, false),
            'is_absent'         => $absent,
        ];
    }

    /**
     * @return array<string, string> field => message; empty when valid
     */
    public static function validate(array $r, ?string $today = null): array
    {
        $errors = [];
        $today ??= date('Y-m-d');

        if (($r['employee_id'] ?? '') === '') {
            $errors['employee_id'] = 'Employee is required.';
        }

        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($r['date'] ?? ''));
        if ($d === false || $d->format('Y-m-d') !== ($r['date'] ?? '')) {
            $errors['date'] = 'Date must be a valid YYYY-MM-DD date.';
        } elseif ($r['date'] > $today) {
            $errors['date'] = 'Date cannot be in the future.';
        }

        if (! in_array($r['schedule_type'] ?? '', self::SCHEDULE_TYPES, true)) {
            $errors['schedule_type'] = 'Schedule must be 8hrs or 9.6hrs.';
        }

        foreach (['late_minutes' => 'Late minutes', 'undertime_minutes' => 'Undertime minutes', 'ot_hours' => 'OT hours'] as $f => $label) {
            $v = $r[$f] ?? null;
            if ($v === null) {
                $errors[$f] = $f === 'ot_hours' ? "{$label} must be a number." : "{$label} must be a whole number.";
            } elseif ($v < 0) {
                $errors[$f] = "{$label} cannot be negative.";
            }
        }

        if (! isset($errors['ot_hours']) && $r['ot_hours'] > self::MAX_OT_HOURS) {
            $errors['ot_hours'] = 'OT hours cannot exceed ' . self::MAX_OT_HOURS . '.';
        }

        if (! empty($r['is_absent']) && (($r['late_minutes'] ?? 0) > 0 || ($r['undertime_minutes'] ?? 0) > 0 || ($r['ot_hours'] ?? 0) > 0)) {
            $errors['is_absent'] = 'An absent day cannot have late, undertime or OT.';
        }

        return $errors;
    }

    public static function documentId(string $employeeId, string $date): string
    {
        return $employeeId . '_' . $date;
    }

    /** Blank input counts as 0; non-numeric input becomes null (invalid). */
    private static function toNumber(mixed $v, bool $integer): int|float|null
    {
        if ($v === '' || $v === null) {
            return 0;
        }
        if (! is_numeric($v)) {
            return null;
        }
        if ($integer) {
            return ((float) $v == (int) $v) ? (int) $v : null;
        }

        return round((float) $v, 2);
    }
}
