<?php
/**
 * ---------------------------------------------------------------------
 *  MailAction - Send ticket information by email from GLPI
 *  ---------------------------------------------------------------------
 *  Copyright © 2026 DVBNL
 *  License: GPLv3+ (see LICENSE file)
 *  ---------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . "/inc/includes.php");
}

$prof = new PluginMailactionProfile();

if (isset($_POST['update_user_profile'])) {
    $id = (int)$_POST['id'];
    $value = $_POST['show_mailaction_onglet'] == '1' ? '1' : '0';

    global $DB;
    $DB->update(
        'glpi_plugin_mailaction_profiles',
        ['show_mailaction_onglet' => $value],
        ['id' => $id]
    );

    Html::back();
}
