<?php
// Moved to admin/menu_list.php (now requires an admin login)
header('Location: admin/menu_list.php' . (isset($_GET['type_id']) ? '?type_id=' . (int) $_GET['type_id'] : ''));
exit;
