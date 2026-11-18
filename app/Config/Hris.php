<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Hris extends BaseConfig
{
    public string $appName = 'PrimaZentra Attendance';

    /** Roles (from the main app) allowed to log in. Supervisors encode and approve DTR. */
    public array $allowedRoles = ['Administrator', 'Operations Manager', 'Project Manager'];
}
