<?php
// admin/logout.php
require_once __DIR__ . '/config.php';
session_unset();
session_destroy();
header('Location: login.php');
exit;