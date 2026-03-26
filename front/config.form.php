<?php
/**
 * MailAction - Copyright (C) 2026 DVBNL - GPLv3+
 *
 * Configuration page for the email HTML template.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . "/inc/includes.php");
}

Session::checkRight('config', UPDATE);

if (isset($_POST['save'])) {
    $config = new PluginMailactionConfig();
    $data = [
        'id'              => 1,
        'html_template'   => $_POST['html_template'] ?? '',
        'subject_prefix'  => $_POST['subject_prefix'] ?? '',
    ];
    if (!$config->getFromDB(1)) {
        $config->add($data);
    } else {
        $config->update($data);
    }
    Session::addMessageAfterRedirect(__('Configuration saved', 'mailaction'));
    Html::back();
} elseif (isset($_POST['reset'])) {
    $config = new PluginMailactionConfig();
    if (!$config->getFromDB(1)) {
        $config->add(['id' => 1, 'html_template' => '', 'subject_prefix' => '']);
    } else {
        $config->update(['id' => 1, 'html_template' => '', 'subject_prefix' => '']);
    }
    Session::addMessageAfterRedirect(__('Configuration reset to default', 'mailaction'));
    Html::back();
} else {
    Html::header(__('MailAction configuration', 'mailaction'), $_SERVER['PHP_SELF'], 'config', 'plugins');
    PluginMailactionConfig::showConfigForm();
    Html::footer();
}
