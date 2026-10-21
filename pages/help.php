<?php
/**
 *
 *       efaCloud
 *       --------
 *       https://www.efacloud.org
 *
 * Copyright  2018-2024  Martin Glade
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * A page to reset the complete data base.
 * 
 * @author mgSoft
 */

// ===== initialize toolbox and socket and start session.
$user_requested_file = __FILE__;
include_once "../classes/init.php";

// ===== start page output
echo file_get_contents('../config/snippets/page_01_start');
echo $menu->get_menu();
echo file_get_contents('../config/snippets/page_02_nav_to_body');

include_once "../classes/efa_fork.php";
echo i("Z4iAHN|<!-- START OF content -...");
$helpdocs = scandir("../helpdocs/" . $toolbox->config->language_code);
foreach ($helpdocs as $helpdoc) {
    $helpdoc_name = str_replace(".html", "", $helpdoc);
    $info_link = "<sup class='eventitem' id='showhelptext_" . str_replace(".html", "", $helpdoc_name) . "'>&#9432;</sup>";
    if (substr($helpdoc_name, 0, 1) != ".")
        echo "<li>" . $helpdoc_name . $info_link . "</li>";
}
echo "</ul><p>" . i("fK9sHp|Fork support: %1 · issue...", 
         "<a href='" . htmlspecialchars(Efa_fork::support_mailto(), ENT_QUOTES) . "'>" .
                 htmlspecialchars(Efa_fork::support_email(), ENT_QUOTES) . "</a>", 
         "<a href='" . htmlspecialchars(Efa_fork::support_issues_url(), ENT_QUOTES) .
                 "' target='_blank' rel='noopener noreferrer'>" .
                 htmlspecialchars(i("fK9sIs|GitHub issues"), ENT_QUOTES) . "</a>") .
         "</p></div>";
end_script();
