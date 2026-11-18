<?php

namespace App\Controllers;

use App\Libraries\DtrRepository;
use App\Libraries\DtrValidator;
use App\Libraries\HrisApiException;
use App\Libraries\HrisClient;
use App\Libraries\PayPeriod;
use App\Libraries\PeriodLockRepository;

class Dtr extends BaseController
{
    private DtrRepository $repo;
    private PeriodLockRepository $locks;

    public function __construct()
    {
        $this->repo  = new DtrRepository();
        $this->locks = new PeriodLockRepository();
    }

    /**
     * Tells hris-payroll that DTR changed on these dates so it marks the
     * affected draft payrolls out of date. Best effort: payroll re-checks
     * its inputs before finalizing, so a missed event is caught there.
     *
     * @param list<string> $dates
     */
    private function notifyPayroll(array $dates, array $employeeIds): void
    {
        HrisClient::payroll()->notify('dtr.changed', [
            'dates'        => array_values(array_unique($dates)),
            'employee_ids' => array_values(array_unique($employeeIds)),
        ]);
    }

    /**
     * Payroll status per period from hris-payroll, e.g. ["2026-11-A" => ["status" => "draft", ...]].
     * Returns null when payroll cannot be reached (the page still works).
     */
    private function payrollStatus(): ?array
    {
        try {
            $out = [];
            foreach (HrisClient::payroll()->get('api/v1/runs')['data'] ?? [] as $run) {
                $out[$run['period_id']] = $run;
            }

            return $out;
        } catch (HrisApiException $e) {
            log_message('warning', 'Payroll status unavailable: ' . $e->getMessage());

            return null;
        }
    }

    /** Records for a date range (defaults to the current pay period), with approval. */
    public function index()
    {
        $period = PayPeriod::containing(date('Y-m-d'));
        $from   = $this->validDate($this->request->getGet('from')) ?? $period->start;
        $to     = $this->validDate($this->request->getGet('to')) ?? $period->end;
        $status = (string) $this->request->getGet('status');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        try {
            $records = $this->repo->between($from, $to);
        } catch (\Throwable $e) {
            log_message('error', 'DTR index: ' . $e->getMessage());
            $records = [];
            session()->setFlashdata('error', 'Could not load DTR records.');
        }

        $pending = count(array_filter($records, static fn ($r) => empty($r['approved'])));
        if ($status === 'pending' || $status === 'approved') {
            $want    = $status === 'approved';
            $records = array_filter($records, static fn ($r) => (bool) ($r['approved'] ?? false) === $want);
        }

        // Payroll status for every pay period the range touches.
        $runs    = $this->payrollStatus();
        $periods = [];
        for ($d = $from; $d <= $to; $d = (new \DateTimeImmutable(PayPeriod::containing($d)->end))->modify('+1 day')->format('Y-m-d')) {
            $p              = PayPeriod::containing($d);
            $periods[$p->id] = ['period' => $p, 'run' => $runs[$p->id] ?? null, 'locked' => $this->locks->lockFor($p->id)];
        }

        return view('dtr/index', compact('records', 'from', 'to', 'status', 'pending', 'periods') + [
            'title'            => 'DTR Records',
            'payrollReachable' => $runs !== null,
        ]);
    }

    /** Entry sheet for one day: every active employee on one row. */
    public function day()
    {
        $date = $this->validDate($this->request->getGet('date')) ?? date('Y-m-d');
        $base = ['title' => 'Daily Entry', 'date' => $date, 'employees' => [], 'existing' => [], 'loadError' => null, 'lock' => null];

        try {
            $base['lock'] = $this->locks->lockFor(PayPeriod::containing($date)->id);
        } catch (\Throwable $e) {
            log_message('error', 'DTR day lock check: ' . $e->getMessage());

            return view('dtr/day', ['loadError' => 'Could not check whether this pay period is locked.'] + $base);
        }

        try {
            $base['employees'] = $this->activeEmployees();
        } catch (HrisApiException $e) {
            return view('dtr/day', ['loadError' => 'Could not load employees from the main app: ' . $e->getMessage()] + $base);
        }

        try {
            foreach ($this->repo->forDate($date) as $r) {
                $base['existing'][$r['employee_id']] = $r;
            }
        } catch (\Throwable $e) {
            log_message('error', 'DTR day: ' . $e->getMessage());

            return view('dtr/day', ['loadError' => 'Could not load existing records for this date.'] + $base);
        }

        return view('dtr/day', $base);
    }

    public function saveDay()
    {
        $date = (string) $this->request->getPost('date');
        $rows = (array) $this->request->getPost('rows');

        if ($this->validDate($date) === null) {
            return redirect()->back()->withInput()->with('error', 'Not saved: invalid date.');
        }
        try {
            if ($this->locks->isLocked($date)) {
                return redirect()->to('dtr/day?date=' . rawurlencode($date))
                    ->with('error', 'Not saved: payroll for this pay period is finalized, so its DTR is locked. Ask Payroll to reopen it first.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'DTR save lock check: ' . $e->getMessage());

            return redirect()->back()->withInput()->with('error', 'Not saved: could not check the pay period lock. Try again.');
        }

        try {
            $employees = $this->activeEmployees();
        } catch (HrisApiException $e) {
            return redirect()->back()->withInput()->with('error', 'Not saved: could not load employees from the main app (' . $e->getMessage() . '). Try again.');
        }

        $saved      = 0;
        $locked     = 0;
        $errors     = [];
        $changedIds = [];
        foreach ($rows as $employeeId => $row) {
            $employee = $employees[$employeeId] ?? null;
            if ($employee === null) {
                $errors[] = "{$employeeId}: not an active employee.";
                continue;
            }

            $record = DtrValidator::normalise(['employee_id' => $employeeId, 'date' => $date, 'schedule_type' => $employee['schedule_type']] + (array) $row);
            if ($rowErrors = DtrValidator::validate($record)) {
                $errors[] = "{$employeeId}: " . implode(' ', $rowErrors);
                continue;
            }
            $record['employee_name'] = $employee['last_name'] . ', ' . $employee['first_name'];

            try {
                if ($this->repo->savePending($record, (string) session()->get('userId'))) {
                    $saved++;
                    $changedIds[] = $employeeId;
                } else {
                    $locked++;
                }
            } catch (\Throwable $e) {
                log_message('error', "DTR save {$employeeId} {$date}: " . $e->getMessage());
                $errors[] = "{$employeeId}: could not be saved.";
            }
        }

        if ($changedIds !== []) {
            $this->notifyPayroll([$date], $changedIds);
        }

        $msg = "{$saved} record(s) saved as pending approval.";
        if ($locked) {
            $msg .= " {$locked} already-approved record(s) were left unchanged.";
        }
        $redirect = redirect()->to('dtr/day?date=' . rawurlencode($date))->with('success', $msg);

        return $errors ? $redirect->with('error', 'Some rows were not saved. ' . implode(' ', $errors)) : $redirect;
    }

    public function approve()
    {
        $ids = array_values(array_filter((array) $this->request->getPost('ids'), 'is_string'));
        if ($ids === []) {
            return redirect()->back()->with('warning', 'Select at least one pending record to approve.');
        }

        // Document ids are "{employee_id}_{Y-m-d}".
        $allowed = [];
        $skipped = 0;
        try {
            foreach ($ids as $id) {
                $date = substr($id, -10);
                if ($this->validDate($date) === null || $this->locks->isLocked($date)) {
                    $skipped++;
                    continue;
                }
                $allowed[$id] = ['employee_id' => substr($id, 0, -11), 'date' => $date];
            }
            $n = $allowed === [] ? 0 : $this->repo->approve(array_keys($allowed), (string) session()->get('userName'), (string) session()->get('userId'));
        } catch (\Throwable $e) {
            log_message('error', 'DTR approve: ' . $e->getMessage());

            return redirect()->back()->with('error', 'Approval failed. No changes were confirmed; try again.');
        }

        if ($n > 0) {
            $this->notifyPayroll(array_column($allowed, 'date'), array_column($allowed, 'employee_id'));
        }

        $redirect = redirect()->back()->with('success', "{$n} record(s) approved.");

        return $skipped ? $redirect->with('warning', "{$skipped} record(s) were not approved because their pay period's payroll is finalized.") : $redirect;
    }

    /** @return array<string, array> active employees keyed by employee_id */
    private function activeEmployees(): array
    {
        $out = [];
        foreach (HrisClient::main()->get('api/v1/employees', ['status' => 'active'])['data'] as $e) {
            $out[$e['employee_id']] = $e;
        }

        return $out;
    }

    private function validDate(mixed $v): ?string
    {
        $d = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;

        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }
}
