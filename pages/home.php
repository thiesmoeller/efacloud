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
 * The start of the session after successfull login.
 * 
 * @author mgSoft
 */

// ===== initialize toolbox and socket and start session.
$user_requested_file = __FILE__;
include_once "../classes/init.php";

$verified_user = $socket->find_record_matched($toolbox->users->user_table_name, 
        [$toolbox->users->user_id_field_name => $toolbox->users->session_user["@id"]
        ]);
$short_home = (strcasecmp($verified_user["Rolle"], "admin") != 0) &&
         (strcasecmp($verified_user["Rolle"], "board") != 0);

// ==== upstream / fork version notice (admins)
include_once "../classes/efa_version.php";
$is_admin = (strcasecmp($verified_user["Rolle"], "admin") == 0);
$version_notification = "";
if ($is_admin) {
    $upstream = Efa_version::upstream();
    $fork = Efa_version::fork();
    $latest = "";
    $update = Efa_version::upstream_update_available($latest);
    $version_notification = "<b>" . i("uP4vRs|Upstream efaCloud:") . "</b> " .
             htmlspecialchars($upstream, ENT_QUOTES);
    if (strlen($fork) > 0)
        $version_notification .= " · <b>" . i("fK8wRc|Club fork:") . "</b> " .
                 htmlspecialchars($fork, ENT_QUOTES);
    $version_notification .= "<br>";
    if ($update && strlen($latest) > 0) {
        $version_notification .= "<b>" . i("p71s5H|Note:") . "</b> " .
                 i("uN7wPq|Upstream efaCloud %1 is ...", htmlspecialchars($latest, ENT_QUOTES)) .
                 " " . i("gI2tMr|Integrate via git and th...");
    } elseif (strlen($latest) > 0) {
        $version_notification .= i("uP9kLm|Integrated upstream is c...");
    } else {
        $version_notification .= i("uP0fEd|Could not reach the upst...");
    }
}

// Boathouse role: use Portal (dockside React) instead of classic efaWeb.
if (strcasecmp($verified_user["Rolle"], "bths") == 0) {
    end_script(false);
    header("Location: ../portal/");
}

// ==== parse configurations
include_once "../classes/efa_config.php";
$efa_config = new Efa_config($toolbox);
$efa_config->parse_client_configs();

$display_name = htmlspecialchars(
        $verified_user["Vorname"] . " " . $verified_user["Nachname"], ENT_QUOTES);
$email = (isset($verified_user["EMail"]) && (strlen($verified_user["EMail"]) > 0))
        ? htmlspecialchars($verified_user["EMail"], ENT_QUOTES)
        : i("2hvcxV|not provided.");

// ===== start page output
echo file_get_contents('../config/snippets/page_01_start');
echo $menu->get_menu();
echo file_get_contents('../config/snippets/page_02_nav_to_body');
?>
<section class="app-panel">
  <h3><?= i("wH3mLc|Welcome, %1", $display_name) ?></h3>
  <p class="app-meta">
    efaCloudUserID: <?= htmlspecialchars($verified_user[$toolbox->users->user_id_field_name], ENT_QUOTES) ?>,
    efaAdmin Name: <?= htmlspecialchars($verified_user["efaAdminName"], ENT_QUOTES) ?>,
    e-Mail: <?= $email ?>.
  </p>
  <?php if (strlen($version_notification) > 0) { ?>
  <p class="app-notice"><?= $version_notification ?></p>
  <?php } ?>

  <h4 class="app-section-title"><?= i("bW4tOn|Boats underway") ?></h4>
  <div class="app-home-boats">
<?php
// Embed directly — an iframe of info.php nested the full shell on ACL errors.
include_once "../classes/efa_info.php";
$efa_info = new Efa_info($toolbox, $socket);
if ($efa_info->is_allowed_info($toolbox->users->session_user, "public_onthewater")) {
    echo $efa_info->get_on_the_water(7);
} else {
    echo "<p class='app-meta'>" . i("Apulhe|Access to this informati...") . "</p>";
}
?>
  </div>

<?php if (! $short_home) {
    include_once "../classes/efa_config.php";
    $efa_config = new Efa_config($toolbox);
    echo "<h4 class='app-section-title'>" . i("Z4VNs0|Active clients") . "</h4>";
    echo $efa_config->get_last_accesses_API($socket, true, true);
} else {
    echo "<p>" . i("pR8nGu|Please use Portal for yo...") . " " .
             "<a href='../portal/'>" . i("pR8nWt|Portal") . "</a>.</p>";
}
?>
</section>
<?php
end_script();
