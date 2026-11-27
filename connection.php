<?php
// Old connection file kept so any leftover code still works.
// New code should use includes/bootstrap.php and the db_*() helpers instead.
require_once __DIR__ . '/includes/bootstrap.php';
$connFarm = db();
$connAdmin = db(); // users now live in jmaire_farm.users
