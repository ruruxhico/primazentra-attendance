<?= $this->extend('layouts/main') ?>
<?= $this->section('nav') ?><?= $this->include('dtr/_nav') ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap align-items-end gap-3 mb-3">
    <div>
        <h1 class="h4 mb-0">Daily Entry</h1>
        <p class="text-muted small mb-0">Enter exceptions only. A blank row means present, on time, no OT.</p>
    </div>
    <form method="get" action="<?= base_url('dtr/day') ?>" class="ms-md-auto d-flex gap-2">
        <input type="date" name="date" value="<?= esc($date) ?>" max="<?= date('Y-m-d') ?>" class="form-control form-control-sm">
        <button class="btn btn-sm btn-outline-secondary">Go</button>
    </form>
</div>

<?php if ($lock): ?>
    <div class="alert alert-secondary">
        <strong>Locked.</strong> Payroll for this pay period was finalized by <?= esc($lock['locked_by'] ?? 'Payroll') ?>,
        so this day's records can't be changed. Payroll must reopen the period first.
    </div>
<?php endif; ?>

<?php if ($loadError): ?>
    <div class="alert alert-danger"><?= esc($loadError) ?></div>
<?php elseif (empty($employees)): ?>
    <div class="alert alert-info">There are no active employees. Add them under Employees in the main PrimaZentra app.</div>
<?php else: ?>
<form method="post" action="<?= base_url('dtr/day') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="date" value="<?= esc($date) ?>">
    <div class="table-responsive bg-white rounded shadow-sm">
    <table class="table table-sm mb-0">
        <thead class="table-light">
            <tr>
                <th>Employee</th>
                <th>Schedule</th>
                <th class="text-center">Absent</th>
                <th style="width:8rem">Late (min)</th>
                <th style="width:8rem">Undertime (min)</th>
                <th style="width:8rem">OT (hrs)</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($employees as $id => $e):
            $r      = $existing[$id] ?? null;
            $locked = ! empty($r['approved']) || $lock;
            $dis    = $locked ? 'disabled' : '';
            // Show the re-submitted value after a validation error, else the saved one; hide zeros.
            $f = static function (string $k) use ($id, $r): string {
                $v = (string) (old("rows.{$id}.{$k}") ?? ($r[$k] ?? ''));

                return $v === '0' ? '' : esc($v);
            };
        ?>
            <tr class="<?= $locked ? 'table-light' : '' ?>">
                <td><span class="text-muted small"><?= esc($id) ?></span> <?= esc($e['last_name'] . ', ' . $e['first_name']) ?></td>
                <td><?= esc($e['schedule_type']) ?></td>
                <td class="text-center">
                    <?php if (! $locked): ?><input type="hidden" name="rows[<?= esc($id) ?>][is_absent]" value="0"><?php endif; ?>
                    <input type="checkbox" class="form-check-input" name="rows[<?= esc($id) ?>][is_absent]" value="1" <?= ! empty($r['is_absent']) ? 'checked' : '' ?> <?= $dis ?>>
                </td>
                <td><input type="number" min="0" step="1" class="form-control form-control-sm" name="rows[<?= esc($id) ?>][late_minutes]" value="<?= $f('late_minutes') ?>" <?= $dis ?>></td>
                <td><input type="number" min="0" step="1" class="form-control form-control-sm" name="rows[<?= esc($id) ?>][undertime_minutes]" value="<?= $f('undertime_minutes') ?>" <?= $dis ?>></td>
                <td><input type="number" min="0" max="16" step="0.25" class="form-control form-control-sm" name="rows[<?= esc($id) ?>][ot_hours]" value="<?= $f('ot_hours') ?>" <?= $dis ?>></td>
                <td>
                    <?php if (! empty($r['approved'])): ?><span class="badge text-bg-success">Approved</span>
                    <?php elseif ($lock): ?><span class="badge text-bg-secondary">Locked</span>
                    <?php elseif ($r): ?><span class="badge text-bg-warning">Pending</span>
                    <?php else: ?><span class="badge text-bg-secondary">Not saved</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="mt-3 d-flex gap-2">
        <?php if (! $lock): ?><button class="btn btn-pz">Save as pending</button><?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= base_url('dtr?from=' . $date . '&to=' . $date . '&status=pending') ?>">Review &amp; approve this day</a>
    </div>
</form>
<?php endif; ?>
<?= $this->endSection() ?>
