<?= $this->extend('layouts/main') ?>
<?= $this->section('nav') ?><?= $this->include('dtr/_nav') ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap align-items-end gap-3 mb-3">
    <div>
        <h1 class="h4 mb-0">DTR Records &amp; Approval</h1>
        <p class="text-muted small mb-0">Payroll only counts approved records. <?= $pending ?> pending in this range.</p>
    </div>
    <form method="get" action="<?= base_url('dtr') ?>" class="ms-md-auto d-flex flex-wrap gap-2">
        <input type="date" name="from" value="<?= esc($from) ?>" class="form-control form-control-sm" style="width:auto">
        <input type="date" name="to" value="<?= esc($to) ?>" class="form-control form-control-sm" style="width:auto">
        <select name="status" class="form-select form-select-sm" style="width:auto">
            <option value="">All</option>
            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary">Filter</button>
    </form>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
<?php foreach ($periods as $pid => $p): $run = $p['run']; ?>
    <div class="border rounded bg-white px-3 py-2 small">
        <strong><?= esc($p['period']->label()) ?></strong> · Payroll:
        <?php if ($p['locked']): ?>
            <span class="badge text-bg-success">Finalized · DTR locked</span>
        <?php elseif (! $payrollReachable): ?>
            <span class="text-muted">status unavailable</span>
        <?php elseif ($run === null): ?>
            <span class="text-muted">not computed yet</span>
        <?php else: ?>
            <span class="badge text-bg-warning">Draft</span>
            <?php if (! empty($run['stale'])): ?><span class="text-muted">· will update with your latest changes</span><?php endif; ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>

<form method="post" action="<?= base_url('dtr/approve') ?>">
    <?= csrf_field() ?>
    <div class="table-responsive bg-white rounded shadow-sm">
    <table class="table table-sm mb-0">
        <thead class="table-light">
            <tr>
                <th style="width:2rem"><input type="checkbox" class="form-check-input" id="checkAll" aria-label="Select all pending"></th>
                <th>Date</th>
                <th>Employee</th>
                <th>Schedule</th>
                <th class="num">Late (min)</th>
                <th class="num">Undertime (min)</th>
                <th class="num">OT (hrs)</th>
                <th>Absent</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($records)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No records in this range.</td></tr>
        <?php endif; ?>
        <?php foreach ($records as $id => $r): $approved = ! empty($r['approved']); ?>
            <tr>
                <td><?php if (! $approved): ?><input type="checkbox" class="form-check-input pending-box" name="ids[]" value="<?= esc($id) ?>"><?php endif; ?></td>
                <td><a href="<?= base_url('dtr/day?date=' . $r['date']) ?>"><?= esc($r['date']) ?></a></td>
                <td><span class="text-muted small"><?= esc($r['employee_id']) ?></span> <?= esc($r['employee_name'] ?? '') ?></td>
                <td><?= esc($r['schedule_type']) ?></td>
                <td class="num"><?= (int) $r['late_minutes'] ?></td>
                <td class="num"><?= (int) $r['undertime_minutes'] ?></td>
                <td class="num"><?= number_format((float) $r['ot_hours'], 2) ?></td>
                <td><?= ! empty($r['is_absent']) ? 'Yes' : '' ?></td>
                <td>
                    <?php if ($approved): ?><span class="badge text-bg-success">Approved</span> <span class="small text-muted">by <?= esc($r['supervisor_name'] ?? '') ?></span>
                    <?php else: ?><span class="badge text-bg-warning">Pending</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <button class="btn btn-pz mt-3">Approve selected</button>
</form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.getElementById('checkAll')?.addEventListener('change', function () {
    document.querySelectorAll('.pending-box').forEach(b => { b.checked = this.checked; });
});
</script>
<?= $this->endSection() ?>
