<?php
// Deleting is now a POST button on admin/menu_list.php (a GET link could be triggered by accident)
header('Location: admin/menu_list.php');
exit;
