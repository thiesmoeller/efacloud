<?php
/**
 * PHP configuration display — admin-only (never unauthenticated).
 * Menu entry: config/access/imenu _ecConfig_phpinfo
 */

$user_requested_file = __FILE__;
include_once "../classes/init.php";

echo file_get_contents('../config/snippets/page_01_start');
echo $menu->get_menu();
echo file_get_contents('../config/snippets/page_02_nav_to_body');
echo "<div class='w3-container'>\n";
echo "<h3>PHP configuration</h3>\n";
echo "<div style='overflow:auto'>\n";
ob_start();
phpinfo();
$info = ob_get_clean();
// Strip outer html/body from phpinfo so it nests in the app chrome.
$info = preg_replace('/^.*?<body[^>]*>/is', '', $info);
$info = preg_replace('/<\/body>.*?$/is', '', $info);
echo $info;
echo "</div></div>\n";
end_script();
