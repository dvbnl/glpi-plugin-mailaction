<?php
/**
 * MailAction - Copyright (C) 2026 DVBNL - GPLv3+
 *
 * Configuration page for the email HTML template.
 */

include(dirname(__DIR__, 3) . "/inc/includes.php");

Session::checkRight('config', UPDATE);

if (isset($_POST['save'])) {
    $config = new PluginMailactionConfig();
    if (!$config->getFromDB(1)) {
        $config->add(['id' => 1, 'html_template' => $_POST['html_template'] ?? '']);
    } else {
        $config->update(['id' => 1, 'html_template' => $_POST['html_template'] ?? '']);
    }
    Session::addMessageAfterRedirect(__('Email template saved', 'mailaction'));
    Html::back();
} elseif (isset($_POST['reset'])) {
    $config = new PluginMailactionConfig();
    if (!$config->getFromDB(1)) {
        $config->add(['id' => 1, 'html_template' => '']);
    } else {
        $config->update(['id' => 1, 'html_template' => '']);
    }
    Session::addMessageAfterRedirect(__('Email template reset to default', 'mailaction'));
    Html::back();
} else {
    Html::header(__('MailAction configuration', 'mailaction'), $_SERVER['PHP_SELF'], 'config', 'plugins');
    PluginMailactionConfig::showConfigForm();
    Html::footer();
}
