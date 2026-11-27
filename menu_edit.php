<?php
// Moved to admin/menu_edit.php (now requires an admin login)
header('Location: admin/menu_edit.php?' . http_build_query($_GET));
exit;
