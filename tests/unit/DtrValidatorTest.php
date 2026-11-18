<?php

use App\Libraries\DtrValidator as V;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * DTR contract tests (M4 Part E: record creation, invalid schedule,
 * negative minutes, plus the remaining field rules).
 *
 * @internal
 */
final class DtrValidatorTest extends CIUnitTestCase
{
    private const TODAY = '2026-11-17';

    private static function input(array $over = []): array
    {
        return $over + ['employee_id' => 'EMP001', 'date' => '2026-11-16', 'schedule_type' => '8hrs',
            'late_minutes' => '29', 'undertime_minutes' => '', 'ot_hours' => '2.5', 'is_absent' => '0'];
    }

    private static function errors(array $over = []): array
    {
        return V::validate(V::normalise(self::input($over)), self::TODAY);
    }

    public function testValidRecordIsAcceptedAndNormalised(): void
    {
        $r = V::normalise(self::input());

        $this->assertSame([], V::validate($r, self::TODAY));
        $this->assertSame(29, $r['late_minutes']);
        $this->assertSame(0, $r['undertime_minutes']);   // blank means 0
        $this->assertSame(2.5, $r['ot_hours']);
        $this->assertFalse($r['is_absent']);
    }

    public function testInvalidScheduleIsRejected(): void
    {
        $this->assertArrayHasKey('schedule_type', self::errors(['schedule_type' => '10hrs']));
    }

    public function testNegativeMinutesAreRejected(): void
    {
        $e = self::errors(['late_minutes' => '-1', 'undertime_minutes' => '-5']);

        $this->assertArrayHasKey('late_minutes', $e);
        $this->assertArrayHasKey('undertime_minutes', $e);
    }

    public function testFractionalOrNonNumericMinutesAreRejected(): void
    {
        $this->assertArrayHasKey('late_minutes', self::errors(['late_minutes' => '2.5']));
        $this->assertArrayHasKey('undertime_minutes', self::errors(['undertime_minutes' => 'abc']));
    }

    public function testMissingEmployeeIsRejected(): void
    {
        $this->assertArrayHasKey('employee_id', self::errors(['employee_id' => '']));
    }

    public function testInvalidAndFutureDatesAreRejected(): void
    {
        $this->assertArrayHasKey('date', self::errors(['date' => '2026-02-30']));
        $this->assertArrayHasKey('date', self::errors(['date' => '2026-11-18']));
    }

    public function testOvertimeIsCapped(): void
    {
        $this->assertArrayHasKey('ot_hours', self::errors(['ot_hours' => '17']));
    }

    public function testAbsentDayCannotHaveLateOrOvertime(): void
    {
        $this->assertArrayHasKey('is_absent', self::errors(['is_absent' => '1']));
        $this->assertSame([], self::errors(['is_absent' => '1', 'late_minutes' => '', 'ot_hours' => '']));
    }

    public function testDocumentIdIsOnePerEmployeePerDay(): void
    {
        $this->assertSame('EMP001_2026-11-16', V::documentId('EMP001', '2026-11-16'));
    }
}
