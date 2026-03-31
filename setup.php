<?php
/**
 * ---------------------------------------------------------------------
 *  MailAction - Send ticket information by email from GLPI
 *  ---------------------------------------------------------------------
 *  Copyright © 2026 DVBNL
 *  License: GPLv3+ (see LICENSE file)
 *  ---------------------------------------------------------------------
 */

define('MAILACTION_VERSION', '1.0.2');
define('MAILACTION_MIN_GLPI_VERSION', '11.0');
define('MAILACTION_MAX_GLPI_VERSION', '11.0.99');

if (!defined("PLUGIN_MAILACTION_DIR")) {
    define("PLUGIN_MAILACTION_DIR", Plugin::getPhpDir("mailaction"));
}
if (!defined("PLUGIN_MAILACTION_WEB_DIR")) {
    define("PLUGIN_MAILACTION_WEB_DIR", Plugin::getWebDir("mailaction"));
}

/**
 * Return the plugin version and requirements metadata.
 */
function plugin_version_mailaction(): array
{
    return [
        'name'         => "MailAction",
        'version'      => MAILACTION_VERSION,
        'author'       => 'DVBNL',
        'license'      => 'GPLv3+',
        'requirements' => [
            'glpi' => [
                'min' => MAILACTION_MIN_GLPI_VERSION,
                'max' => MAILACTION_MAX_GLPI_VERSION,
            ],
            'php' => [
                'min' => '8.2'
            ]
        ]
    ];
}

/**
 * Verify that the GLPI version meets the minimum requirement.
 */
function plugin_mailaction_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, MAILACTION_MIN_GLPI_VERSION, '<')) {
        echo 'This plugin requires GLPI >= ' . MAILACTION_MIN_GLPI_VERSION;
        return false;
    }
    return true;
}

/**
 * Check plugin configuration (always returns true, no config needed).
 */
function plugin_mailaction_check_config(bool $verbose = false): bool
{
    if ($verbose) {
        echo 'Installed / not configured';
    }
    return true;
}

/**
 * Check whether the current user has the MailAction plugin right.
 */
function plugin_mailaction_haveRight(): bool {
    return isset($_SESSION["glpi_plugin_mailaction_profile"])
        && $_SESSION['glpi_plugin_mailaction_profile']['show_mailaction_onglet'] == "1";
}

/**
 * Initialize the plugin: register classes and hooks.
 */
function plugin_init_mailaction(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass('PluginMailactionProfile', ['addtabon' => ['Profile', 'Ticket']]);
    Plugin::registerClass('PluginMailactionCompose');
    Plugin::registerClass('PluginMailactionConfig');
    $PLUGIN_HOOKS['change_profile']['mailaction'] = ['PluginMailactionProfile', 'changeProfile'];

    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['mailaction'] = 'front/config.form.php';
    }
}
