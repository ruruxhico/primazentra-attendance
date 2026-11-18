<?php $path = trim(uri_string(), '/'); ?>
<li class="nav-item"><a class="nav-link <?= $path === 'dtr/day' ? 'active' : '' ?>" href="<?= base_url('dtr/day') ?>">Daily Entry</a></li>
<li class="nav-item"><a class="nav-link <?= in_array($path, ['', 'dtr'], true) ? 'active' : '' ?>" href="<?= base_url('dtr') ?>">Records &amp; Approval</a></li>
