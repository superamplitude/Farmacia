<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

if (!Auth::check()) {
    header('Location: ' . url('admin.php'));
    exit;
}

if (Auth::role() === 'super_admin') {
    header('Location: ' . url('superadmin.php'));
    exit;
}

header('Location: ' . url('farmacia-admin.php'));
exit;
