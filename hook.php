<?php
/**
 * ---------------------------------------------------------------------
 *  MailAction - Send ticket information by email from GLPI
 *  ---------------------------------------------------------------------
 *  Copyright © 2026 DVBNL
 *  License: GPLv3+ (see LICENSE file)
 *  ---------------------------------------------------------------------
 */

/**
 * Install the plugin database table.
 * Includes automatic migration from the legacy "ticketmail" plugin.
 */
function plugin_mailaction_install(): bool
{
    global $DB;

    $migration = new Migration(MAILACTION_VERSION);

    // Migrate from old "ticketmail" plugin if present
    if ($DB->tableExists('glpi_plugin_ticketmail_profiles') && !$DB->tableExists('glpi_plugin_mailaction_profiles')) {
        $DB->doQuery("RENAME TABLE `glpi_plugin_ticketmail_profiles` TO `glpi_plugin_mailaction_profiles`");
    }

    // Fresh install
    if (!$DB->tableExists('glpi_plugin_mailaction_profiles')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_mailaction_profiles` (
                `id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_profiles (id)',
                `show_mailaction_onglet` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $migration->executeMigration();

        include_once(PLUGIN_MAILACTION_DIR . "/inc/profile.class.php");
        PluginMailactionProfile::createAdminAccess($_SESSION['glpiactiveprofile']['id']);
    } else {
        // Migrate column name from legacy plugin
        if ($DB->fieldExists('glpi_plugin_mailaction_profiles', 'show_ticketmail_onglet')) {
            $DB->doQuery(
                "ALTER TABLE `glpi_plugin_mailaction_profiles`
                 CHANGE `show_ticketmail_onglet` `show_mailaction_onglet` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL"
            );
        }
        // Legacy migration from very old versions (pre-0.84)
        if ($DB->fieldExists('glpi_plugin_mailaction_profiles', 'profiles_id')) {
            $DB->doQuery("ALTER TABLE glpi_plugin_mailaction_profiles DROP COLUMN `id`");
            $DB->doQuery(
                "ALTER TABLE glpi_plugin_mailaction_profiles
                 CHANGE profiles_id id int unsigned NOT NULL DEFAULT '0'
                 COMMENT 'RELATION to glpi_profiles (id)'"
            );
            $DB->doQuery("ALTER TABLE glpi_plugin_mailaction_profiles ADD PRIMARY KEY (id)");
            $DB->doQuery("ALTER TABLE glpi_plugin_mailaction_profiles DROP INDEX profiles_id");
        }
    }

    // Config table for HTML template
    if (!$DB->tableExists('glpi_plugin_mailaction_configs')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_mailaction_configs` (
                `id` int unsigned NOT NULL DEFAULT '1',
                `html_template` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `subject_prefix` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // Migration: add subject_prefix column if missing
    if ($DB->tableExists('glpi_plugin_mailaction_configs')
        && !$DB->fieldExists('glpi_plugin_mailaction_configs', 'subject_prefix')) {
        $DB->doQuery(
            "ALTER TABLE `glpi_plugin_mailaction_configs`
             ADD `subject_prefix` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL"
        );
    }

    return true;
}

/**
 * Uninstall the plugin by dropping its database tables.
 */
function plugin_mailaction_uninstall(): bool
{
    global $DB;

    $DB->doQuery("DROP TABLE IF EXISTS `glpi_plugin_mailaction_profiles`");
    $DB->doQuery("DROP TABLE IF EXISTS `glpi_plugin_mailaction_configs`");

    return true;
}

/**
 * Declare the database relationship between profiles and plugin permissions.
 */
function plugin_mailaction_getPluginsDatabaseRelations(): array
{
    $plugin = new Plugin();
    if ($plugin->isActivated("mailaction")) {
        return [
            "glpi_profiles" => ["glpi_plugin_mailaction_profiles" => "id"]
        ];
    }
    return [];
}
