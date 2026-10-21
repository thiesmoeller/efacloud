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
 * Club-fork support form. Sends to the fork maintainer — not upstream efacloud.org.
 *
 * Based on the Tfyh_form class, please read instructions their to better understand this PHP-code part.
 * 
 * @author mgSoft
 */

// ===== initialize toolbox and socket and start session.
$user_requested_file = __FILE__;
include_once "../classes/init.php";
include_once '../classes/tfyh_form.php';
include_once '../classes/efa_fork.php';
include_once '../classes/efa_version.php';
include_once '../classes/tfyh_mail_handler.php';

// === APPLICATION LOGIC ==============================================================
// if validation fails, the same form will be displayed anew with error messgaes
$todo = ($done == 0) ? 1 : $done;
$form_errors = "";
$form_layout = "../config/layouts/support";
$post_result = "";

// ======== Start with form filled in last step: check of the entered values.
if ($done > 0) {
    $form_filled = new Tfyh_form($form_layout, $socket, $toolbox, $done, $fs_id);
    $form_filled->read_entered();
    $form_errors = $form_filled->check_validity();
    $entered_data = $form_filled->get_entered();
    
    // application logic, step by step
    if (strlen($form_errors) > 0) {
        // do nothing. This avoids any change, if form errors occured.
    } elseif ($done == 1) {
        $attachment = "";
        if (isset($entered_data["SendLogs"]) && (strlen($entered_data["SendLogs"]) > 0)) {
            $monitoring_report = "../log/" . $toolbox->logger->zip_logs();
            if (is_readable($monitoring_report))
                $attachment = $monitoring_report;
        }

        $base_url = "";
        if (isset($toolbox->config->cfg_app["app_url"]))
            $base_url = strval($toolbox->config->cfg_app["app_url"]);
        if (strlen($base_url) == 0 && isset($_SERVER["HTTP_HOST"])) {
            $proto = (! empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
            if (! empty($_SERVER["HTTP_X_FORWARDED_PROTO"]))
                $proto = $_SERVER["HTTP_X_FORWARDED_PROTO"];
            $base_url = $proto . "://" . $_SERVER["HTTP_HOST"];
        }

        $subject = "[efaCloud fork] " . i("BVNSCJ|Support request") . " — " .
                 trim($entered_data["Verein"] ?? "");
        $body = i("fK9sUp|Fork support request") . "\n\n" .
                 "Name: " . trim(($entered_data["Vorname"] ?? "") . " " . ($entered_data["Nachname"] ?? "")) .
                 "\n" .
                 "E-Mail: " . trim($entered_data["EMail"] ?? "") . "\n" .
                 "Verein: " . trim($entered_data["Verein"] ?? "") . "\n" .
                 "NamedUser: " . ((isset($entered_data["NamedUser"]) && strlen($entered_data["NamedUser"]) > 0)
                         ? "yes" : "no") . "\n" .
                 "Site: " . $base_url . "\n" .
                 "Upstream: " . Efa_version::upstream() . "\n" .
                 "Fork: " . Efa_version::fork() . "\n" .
                 "Display: " . Efa_version::display_string() . "\n\n" .
                 "----\n" .
                 trim($entered_data["Anliegen"] ?? "") . "\n";

        $mail_handler = new Tfyh_mail_handler($toolbox->config->get_cfg());
        $reply_to = trim($entered_data["EMail"] ?? "");
        $from = $mail_handler->system_mail_sender;
        if (strlen(trim(strval($from))) == 0)
            $from = Efa_fork::support_mailto_header();

        $ok = $mail_handler->send_mail($from, $reply_to, Efa_fork::support_mailto_header(), "", "", 
                $subject, $body, $attachment, "");
        if ($ok) {
            $post_result = i("fK9sOk|Your request was emailed...");
        } else {
            $post_result = i("fK9sFl|Automatic e-mail failed....", 
                    htmlspecialchars(Efa_fork::support_email(), ENT_QUOTES));
        }
        $todo = 2;
    }
}

// ==== continue with the definition and eventually initialization of form to fill for the next step
if (isset($form_filled) && ($todo == $form_filled->get_index())) {
    // redo the 'done' form, if the $todo == $done, i. e. the validation failed.
    $form_to_fill = $form_filled;
} else {
    // if it is the start or all is fine, use a form for the coming step.
    $form_to_fill = new Tfyh_form($form_layout, $socket, $toolbox, $todo, $fs_id);
}

// === PAGE OUTPUT ===================================================================

// ===== start page output
echo file_get_contents('../config/snippets/page_01_start');
echo $menu->get_menu();
echo file_get_contents('../config/snippets/page_02_nav_to_body');

// page heading, identical for all workflow steps
echo i("y1NFtw|<div class=°w3-contain...");
echo $toolbox->form_errors_to_html($form_errors);

// ======== start with the display of either the next form, or the error messages.
if ($todo == 1) {
    echo i("dH58zd|<p>Please let me know y...");
    echo "<p class='app-meta'>" . i("fK9sTo|Requests go to %1 (%2).", 
             htmlspecialchars(Efa_fork::support_name(), ENT_QUOTES), 
             "<a href='" . htmlspecialchars(Efa_fork::support_mailto(), ENT_QUOTES) . "'>" .
                     htmlspecialchars(Efa_fork::support_email(), ENT_QUOTES) . "</a>") . "</p>";
    // step 1. Show form.
    echo $form_to_fill->get_html();
    // insert help text as right hand menu for mobile access
    echo $form_to_fill->get_help_html();
} elseif ($todo == 2) {
    echo "<p>" . i("02SBJ9|Thank you for your reque...") . "</p><p>";
    echo $post_result;
    echo "</p><p>" . i("4hdqAF|The process is now compl...") . "</p>";
}
echo "</div>";
end_script();
