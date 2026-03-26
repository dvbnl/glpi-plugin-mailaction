<?php
/**
 * MailAction - Copyright © 2026 DVBNL - GPLv3+
 */

class PluginMailactionProfile extends CommonDBTM {

    public static function getTypeName($nb = 0): string {
        return "MailAction";
    }

    public static function getIcon(): string {
        return "fas fa-paper-plane";
    }

    public static function canCreate(): bool {
        return isset($_SESSION["glpi_plugin_mailaction_profile"])
            && $_SESSION["glpi_plugin_mailaction_profile"]['mailaction'] == 'w';
    }

    public static function canView(): bool {
        return isset($_SESSION["glpi_plugin_mailaction_profile"])
            && in_array($_SESSION["glpi_plugin_mailaction_profile"]['mailaction'], ['w', 'r']);
    }

    public static function createAdminAccess(int $ID): void {
        $self = new self();
        if (!$self->getFromDB($ID)) {
            $self->add([
                'id' => $ID,
                'show_mailaction_onglet' => '1'
            ]);
        }
    }

    public function createAccess(int $ID): void {
        $this->add(['id' => $ID]);
    }

    public static function changeProfile(): void {
        $prof = new self();
        if (
            array_key_exists('glpiactiveprofile', $_SESSION)
            && $prof->getFromDB($_SESSION['glpiactiveprofile']['id'])
        ) {
            $_SESSION["glpi_plugin_mailaction_profile"] = $prof->fields;
        } else {
            unset($_SESSION["glpi_plugin_mailaction_profile"]);
        }
    }

    public function showForm($ID, array $options = []): bool {
        $target = $options['target'] ?? $this->getFormURL();

        $profile = new Profile();
        if ($ID) {
            $this->getFromDB($ID);
            $profile->getFromDB($ID);
        }
        ?>
        <form action='<?php echo $target ?>' method='post'>
            <table class='tab_cadre_fixe'>
                <tr>
                    <th colspan='2' class='center b'>
                        <?php echo __('User rights management', 'mailaction') . " " . $profile->fields["name"]; ?>
                    </th>
                </tr>
                <tr class='tab_bg_2'>
                    <td><?php echo __('Tab display', 'mailaction'); ?>:</td>
                    <td>
                        <?php Dropdown::showYesNo("show_mailaction_onglet", $this->fields["show_mailaction_onglet"]); ?>
                    </td>
                </tr>
                <tr class='tab_bg_1'>
                    <td class='center' colspan='2'>
                        <input type='hidden' name='id' value='<?php echo $ID; ?>'>
                        <input type='submit' name='update_user_profile' value='<?php echo __s('Update'); ?>' class='btn btn-primary'>
                    </td>
                </tr>
            </table>
        <?php
        Html::closeForm();
        return true;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string {
        if (in_array($item->getType(), ['Profile', 'Ticket']) && plugin_mailaction_haveRight()) {
            return self::createTabEntry(__('MailAction', 'mailaction'), 0, $item->getType(), 'fas fa-paper-plane');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
        if ($item->getType() == 'Profile') {
            $prof = new self();
            $ID = $item->getField('id');
            if (!$prof->getFromDB($ID)) {
                $prof->createAccess($ID);
            }
            $prof->showForm($ID);
        } elseif ($item->getType() == 'Ticket' && plugin_mailaction_haveRight()) {
            PluginMailactionCompose::showComposeForm($item->getField('id'));
        }

        return true;
    }
}

// Function moved to setup.php so it is always available when the plugin is active.
