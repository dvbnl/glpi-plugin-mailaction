<?php
/**
 * MailAction - Copyright © 2026 DVBNL - GPLv3+
 *
 * Handles email composition, recipient resolution, and the ticket tab form.
 */

class PluginMailactionCompose extends CommonDBTM {

    /**
     * Get the display name for a GLPI user by ID.
     * Tries firstname+realname, then falls back to login name.
     */
    private static function getUserDisplayName(int $userId): string {
        $user = new User();
        if (!$user->getFromDB($userId)) {
            return '';
        }
        $f = $user->fields;
        $full = trim(($f['firstname'] ?? '') . ' ' . ($f['realname'] ?? ''));
        return $full ?: ($f['name'] ?? '');
    }

    /**
     * Get the primary email address for a GLPI user by ID.
     * Queries glpi_useremails directly, preferring is_default=1.
     */
    private static function getUserEmailAddress(int $userId): string {
        global $DB;

        $rows = $DB->request([
            'SELECT'   => ['email'],
            'FROM'     => 'glpi_useremails',
            'WHERE'    => ['users_id' => $userId],
            'ORDER'    => ['is_default DESC'],
            'LIMIT'    => 1,
        ]);
        foreach ($rows as $row) {
            if (!empty($row['email'])) {
                return $row['email'];
            }
        }
        return '';
    }

    /**
     * Resolve all unique recipients linked to a ticket.
     *
     * Returns a flat array of ['name' => ..., 'email' => ...] entries,
     * de-duplicated by email address.
     */
    public static function resolveRecipients(int $ticketId): array {
        global $DB;

        $seen = [];
        $recipients = [];

        $rows = $DB->request([
            'SELECT' => ['users_id', 'alternative_email'],
            'FROM'   => 'glpi_tickets_users',
            'WHERE'  => ['tickets_id' => $ticketId],
        ]);

        foreach ($rows as $row) {
            $email = null;
            $label = null;

            if ($row['users_id'] > 0) {
                // Registered GLPI user - look up email and name separately
                $email = self::getUserEmailAddress($row['users_id']);
                if (!$email && !empty($row['alternative_email'])) {
                    $email = $row['alternative_email'];
                }
                if (!$email) {
                    continue;
                }
                $label = self::getUserDisplayName($row['users_id']) ?: $email;
            } elseif (!empty($row['alternative_email'])) {
                $email = $row['alternative_email'];
                $label = $email;
            }

            if ($email && !isset($seen[strtolower($email)])) {
                $seen[strtolower($email)] = true;
                $recipients[] = [
                    'name'  => $label,
                    'email' => $email,
                ];
            }
        }

        return $recipients;
    }

    /**
     * Gather full ticket metadata for a rich email.
     */
    public static function getTicketMeta(int $ticketId): array {
        global $DB;

        $meta = [];
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            return $meta;
        }

        $f = $ticket->fields;
        $meta['id']       = $f['id'];
        $meta['title']    = $f['name'];
        $meta['date']     = Html::convDateTime($f['date']);
        $meta['content']  = $f['content'];
        $meta['status']   = Ticket::getStatus($f['status']);
        $meta['urgency']  = CommonITILObject::getUrgencyName($f['urgency']);
        $meta['impact']   = CommonITILObject::getImpactName($f['impact']);
        $meta['priority'] = CommonITILObject::getPriorityName($f['priority']);
        $meta['closedate'] = $f['closedate'] ? Html::convDateTime($f['closedate']) : '—';

        // Request type
        if ($f['requesttypes_id'] > 0) {
            $rt = new RequestType();
            $meta['requesttype'] = $rt->getFromDB($f['requesttypes_id']) ? $rt->fields['name'] : '—';
        } else {
            $meta['requesttype'] = '—';
        }

        // Category
        if ($f['itilcategories_id'] > 0) {
            $cat = new ITILCategory();
            $meta['category'] = $cat->getFromDB($f['itilcategories_id']) ? $cat->fields['completename'] : '—';
        } else {
            $meta['category'] = '—';
        }

        // People by role
        $roleMap = [1 => 'requesters', 2 => 'technicians', 3 => 'observers'];
        $meta['requesters'] = [];
        $meta['technicians'] = [];
        $meta['observers'] = [];

        $rows = $DB->request([
            'SELECT' => ['users_id', 'type', 'alternative_email'],
            'FROM'   => 'glpi_tickets_users',
            'WHERE'  => ['tickets_id' => $ticketId],
        ]);
        foreach ($rows as $row) {
            $role = $roleMap[$row['type']] ?? null;
            if (!$role) continue;
            if ($row['users_id'] > 0) {
                $name = self::getUserDisplayName($row['users_id']);
                $email = self::getUserEmailAddress($row['users_id']);
                $display = $name ?: $email;
                if (!$display) {
                    $display = '#' . $row['users_id'];
                }
                $meta[$role][] = $display;
            } elseif (!empty($row['alternative_email'])) {
                $meta[$role][] = $row['alternative_email'];
            }
        }

        // Assigned groups
        $meta['groups'] = [];
        $grows = $DB->request([
            'SELECT'    => ['g.name'],
            'FROM'      => 'glpi_groups_tickets AS gt',
            'LEFT JOIN' => [
                'glpi_groups AS g' => ['ON' => ['gt' => 'groups_id', 'g' => 'id']],
            ],
            'WHERE' => ['gt.tickets_id' => $ticketId, 'gt.type' => 2],
        ]);
        foreach ($grows as $g) {
            $meta['groups'][] = $g['name'];
        }

        return $meta;
    }

    /**
     * Convert relative GLPI document URLs (src/href) to absolute URLs
     * so that images and links work correctly in outgoing emails.
     */
    private static function absolutifyDocumentUrls(string $html): string {
        global $CFG_GLPI;
        $base = rtrim($CFG_GLPI['url_base'] ?? '', '/');
        if (empty($base)) {
            return $html;
        }
        // Convert src="/front/..." and href="/front/..." to absolute URLs
        $html = preg_replace(
            '#((?:src|href)\s*=\s*["\'])(/front/document\.send\.php)#i',
            '$1' . $base . '$2',
            $html
        );
        return $html;
    }

    /**
     * Assemble the email subject and rich HTML body from ticket data.
     */
    public static function assembleContent(int $ticketId): array {
        global $DB;

        $meta = self::getTicketMeta($ticketId);
        if (empty($meta)) {
            return ['subject' => '', 'body' => ''];
        }

        $prefix = PluginMailactionConfig::getSubjectPrefix();
        $prefix = str_replace('{{ID}}', str_pad($meta['id'], 7, '0', STR_PAD_LEFT), $prefix);
        $subject = $prefix . ' ' . $meta['title'];

        // Build a rich structured body
        $b = '';

        // Details section
        $b .= '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;" cellpadding="0" cellspacing="0">';
        $b .= self::metaRow(__('Ticket', 'mailaction'), '#' . str_pad($meta['id'], 7, '0', STR_PAD_LEFT));
        $b .= self::metaRow(__('Title', 'mailaction'), '<strong>' . htmlspecialchars($meta['title']) . '</strong>');
        $b .= self::metaRow(__('Status', 'mailaction'), $meta['status']);
        $b .= self::metaRow(__('Date opened', 'mailaction'), $meta['date']);
        $b .= self::metaRow(__('Date closed', 'mailaction'), $meta['closedate']);
        $b .= self::metaRow(__('Category', 'mailaction'), htmlspecialchars($meta['category']));
        $b .= self::metaRow(__('Request type', 'mailaction'), htmlspecialchars($meta['requesttype']));
        $b .= '</table>';

        // Priority / Urgency / Impact row
        $b .= '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;" cellpadding="0" cellspacing="0"><tr>';
        $b .= '<td style="width:33%;padding:10px 12px;background:rgba(0,0,0,0.04);border-radius:8px 0 0 8px;text-align:center;">';
        $b .= '<div style="font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:0.5px;opacity:0.7;">' . __('Urgency', 'mailaction') . '</div>';
        $b .= '<div style="font-size:14px;font-weight:600;padding-top:2px;">' . $meta['urgency'] . '</div></td>';
        $b .= '<td style="width:34%;padding:10px 12px;background:rgba(0,0,0,0.04);text-align:center;">';
        $b .= '<div style="font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:0.5px;opacity:0.7;">' . __('Impact', 'mailaction') . '</div>';
        $b .= '<div style="font-size:14px;font-weight:600;padding-top:2px;">' . $meta['impact'] . '</div></td>';
        $b .= '<td style="width:33%;padding:10px 12px;background:rgba(0,0,0,0.04);border-radius:0 8px 8px 0;text-align:center;">';
        $b .= '<div style="font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:0.5px;opacity:0.7;">' . __('Priority', 'mailaction') . '</div>';
        $b .= '<div style="font-size:14px;font-weight:600;padding-top:2px;">' . $meta['priority'] . '</div></td>';
        $b .= '</tr></table>';

        // People
        $b .= '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;" cellpadding="0" cellspacing="0">';
        if (!empty($meta['requesters'])) {
            $b .= self::metaRow(__('Requesters', 'mailaction'), implode(', ', array_map('htmlspecialchars', $meta['requesters'])));
        }
        if (!empty($meta['technicians'])) {
            $b .= self::metaRow(__('Technicians', 'mailaction'), implode(', ', array_map('htmlspecialchars', $meta['technicians'])));
        }
        if (!empty($meta['groups'])) {
            $b .= self::metaRow(__('Assigned groups', 'mailaction'), implode(', ', array_map('htmlspecialchars', $meta['groups'])));
        }
        if (!empty($meta['observers'])) {
            $b .= self::metaRow(__('Observers', 'mailaction'), implode(', ', array_map('htmlspecialchars', $meta['observers'])));
        }
        $b .= '</table>';

        // Ticket content
        $b .= '<div style="font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:1px;padding-bottom:8px;margin-bottom:12px;border-bottom:2px solid currentColor;opacity:0.6;">';
        $b .= __('Content of the initial ticket', 'mailaction') . '</div>';
        $b .= '<div style="line-height:1.65;">' . self::absolutifyDocumentUrls($meta['content']) . '</div>';

        // Tasks and followups
        $tasks = $DB->request([
            'SELECT' => ['date', 'content', 'is_private'],
            'FROM'   => 'glpi_tickettasks',
            'WHERE'  => ['tickets_id' => $ticketId],
        ]);
        $followups = $DB->request([
            'SELECT' => ['date', 'content', 'is_private'],
            'FROM'   => 'glpi_itilfollowups',
            'WHERE'  => ['itemtype' => 'Ticket', 'items_id' => $ticketId],
        ]);

        $entries = [];
        foreach ($tasks as $row) { $entries[] = $row; }
        foreach ($followups as $row) { $entries[] = $row; }
        usort($entries, fn($a, $c) => strtotime($c['date']) - strtotime($a['date']));

        if (count($entries) > 0) {
            $b .= '<div style="font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:1px;padding:16px 0 8px 0;margin-bottom:12px;border-bottom:2px solid currentColor;opacity:0.6;">';
            $b .= __('Ticket tasks and followups', 'mailaction') . '</div>';
            foreach ($entries as $entry) {
                $cls = ($entry['is_private'] == 1) ? ' is_private' : '';
                $b .= '<div class="mailaction-entry' . $cls . '" style="margin-bottom:12px;padding:12px;border-radius:8px;background:rgba(0,0,0,0.04);border-left:3px solid currentColor;">';
                $b .= '<div style="font-size:12px;margin-bottom:6px;opacity:0.6;">' . Html::convDateTime($entry['date']);
                if ($entry['is_private'] == 1) {
                    $b .= ' <span style="background:rgba(0,0,0,0.08);padding:1px 6px;border-radius:4px;font-size:11px;">' . __('Private', 'mailaction') . '</span>';
                }
                $b .= '</div>';
                $b .= '<div style="line-height:1.65;">' . self::absolutifyDocumentUrls($entry['content']) . '</div>';
                $b .= '</div>';
            }
        }

        return ['subject' => $subject, 'body' => $b];
    }

    /**
     * Build one metadata row for the email body.
     */
    private static function metaRow(string $label, string $value): string {
        return '<tr>'
            . '<td style="padding:6px 12px 6px 0;font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:0.5px;white-space:nowrap;vertical-align:top;width:140px;opacity:0.7;">' . $label . '</td>'
            . '<td style="padding:6px 0;font-size:14px;line-height:1.5;">' . $value . '</td>'
            . '</tr>';
    }

    /**
     * Collect the available "From" addresses for the current user.
     */
    public static function getSenderAddresses(): array {
        global $CFG_GLPI, $DB;

        $addresses = [];

        if (!empty($CFG_GLPI['from_email'])) {
            $addresses[] = $CFG_GLPI['from_email'];
        }
        if (!empty($CFG_GLPI['admin_email'])) {
            $addresses[] = $CFG_GLPI['admin_email'];
        }

        $rows = $DB->request([
            'SELECT' => ['email'],
            'FROM'   => 'glpi_useremails',
            'WHERE'  => ['users_id' => $_SESSION['glpiID']],
        ]);
        foreach ($rows as $row) {
            $addresses[] = $row['email'];
        }

        return array_values(array_unique(array_filter($addresses)));
    }

    /**
     * Get the default email for a given user ID.
     */
    public static function getUserEmail(int $userId): string {
        return UserEmail::getDefaultForUser($userId) ?: '';
    }

    /**
     * Format a history log entry for the ticket timeline.
     */
    public static function getHistoryEntry(array $data): string {
        return sprintf(__("An email was sent to %s"), $data['old_value']) . " : " . $data['new_value'];
    }

    /**
     * Render the email composition form on the ticket's MailAction tab.
     */
    public static function showComposeForm(int $ticketId): void {
        global $CFG_GLPI;

        $recipients  = self::resolveRecipients($ticketId);
        $content     = self::assembleContent($ticketId);
        $senders     = self::getSenderAddresses();

        ?>
        <div class="container-fluid">
            <form method='post' action="<?php echo PLUGIN_MAILACTION_WEB_DIR . "/front/compose.form.php"; ?>">
                <input type='hidden' name='id' value='<?php echo (int)$ticketId; ?>'>

                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title mb-0">
                            <i class="fas fa-paper-plane me-2"></i>
                            <?php echo __('Send ticket information by email', 'mailaction'); ?>
                        </h3>
                    </div>
                    <div class="card-body">

                        <div class="row mb-3">
                            <label class="col-sm-2 col-form-label fw-bold"><?php echo __('From', 'mailaction'); ?></label>
                            <div class="col-sm-10">
                                <select name="from" class="form-select" required>
                                    <?php foreach ($senders as $addr): ?>
                                        <option value="<?php echo htmlspecialchars($addr); ?>">
                                            <?php echo htmlspecialchars($addr); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <label class="col-sm-2 col-form-label fw-bold"><?php echo __('To', 'mailaction'); ?></label>
                            <div class="col-sm-10">
                                <?php if (empty($recipients)): ?>
                                    <p class="text-muted fst-italic mb-2"><?php echo __('No recipients with email addresses found on this ticket.', 'mailaction'); ?></p>
                                <?php else: ?>
                                    <?php foreach ($recipients as $i => $person): ?>
                                        <div class="form-check">
                                            <input class="form-check-input recipient-checkbox" type="checkbox"
                                                   name="recipients[]"
                                                   value="<?php echo htmlspecialchars($person['email']); ?>"
                                                   id="rcpt_<?php echo $i; ?>">
                                            <label class="form-check-label" for="rcpt_<?php echo $i; ?>">
                                                <?php echo htmlspecialchars($person['name']); ?>
                                                <?php if ($person['name'] !== $person['email']): ?>
                                                    <small class="text-muted">(<?php echo htmlspecialchars($person['email']); ?>)</small>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <div class="mt-2">
                                    <label class="form-label text-muted">
                                        <i class="fas fa-plus-circle me-1"></i>
                                        <?php echo __('Or enter a custom email address', 'mailaction'); ?>
                                    </label>
                                    <input type='email' name='custom_address' id='custom_address'
                                           class='form-control' style='max-width: 400px'
                                           placeholder='email@example.com'>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-sm-2"></div>
                            <div class="col-sm-10">
                                <div class="form-check">
                                    <input type="checkbox" name="hide_private" id="hidePrivate"
                                           value="1" class="form-check-input" checked>
                                    <label class="form-check-label" for="hidePrivate">
                                        <?php echo __('Hide private tasks and private followups', 'mailaction'); ?>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <label class="col-sm-2 col-form-label fw-bold"><?php echo __('Subject', 'mailaction'); ?></label>
                            <div class="col-sm-10">
                                <input type='text' name='subject' maxlength='200' class='form-control'
                                       value='<?php echo htmlspecialchars($content['subject']); ?>'>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <label class="col-sm-2 col-form-label fw-bold"><?php echo __('Message', 'mailaction'); ?></label>
                            <div class="col-sm-10">
                                <textarea name='body' id='composeBody' rows='20' class='form-control'><?php echo $content['body']; ?></textarea>
                            </div>
                        </div>

                    </div>
                    <div class="card-footer text-center">
                        <button type='button' id='mailaction-preview-btn' class='btn btn-outline-secondary btn-lg me-2'>
                            <i class="fas fa-eye me-2"></i>
                            <?php echo __('Preview', 'mailaction'); ?>
                        </button>
                        <button type='submit' name='send' class='btn btn-primary btn-lg'>
                            <i class="fas fa-paper-plane me-2"></i>
                            <?php echo __('Send', 'mailaction'); ?>
                        </button>
                    </div>
                </div>
            <?php Html::closeForm(); ?>
        </div>

        <!-- Preview modal -->
        <div class="modal fade" id="mailaction-preview-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-eye me-2"></i>
                            <?php echo __('Email preview', 'mailaction'); ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-0">
                        <iframe id="mailaction-preview-iframe" style="width:100%; height:70vh; border:none;"></iframe>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <?php echo __('Close', 'mailaction'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            <?php
            $lang = $_SESSION['glpilanguage'];
            if (!file_exists(GLPI_ROOT . "/public/lib/tinymce-i18n/langs/$lang.js")) {
                $lang = $CFG_GLPI["languages"][$_SESSION['glpilanguage']][2] ?? 'en_GB';
                if (!file_exists(GLPI_ROOT . "/public/lib/tinymce-i18n/langs/$lang.js")) {
                    $lang = "en_GB";
                }
            }
            $langUrl = $CFG_GLPI['root_doc'] . '/public/lib/tinymce-i18n/langs/' . $lang . '.js';
            $previewUrl = PLUGIN_MAILACTION_WEB_DIR . '/front/preview.ajax.php';
            $ajaxCsrfToken = Session::getNewCSRFToken();
            ?>
            var mailactionPreviewUrl = '<?php echo $previewUrl; ?>';
            var mailactionTicketId = <?php echo (int)$ticketId; ?>;
            var mailactionCsrfToken = '<?php echo $ajaxCsrfToken; ?>';

            tinymce.init({
                language_url: '<?php echo $langUrl ?>',
                invalid_elements: 'form,iframe,script,@[onclick|ondblclick|onmousedown|onmouseup|onmouseover|onmousemove|onmouseout|onkeypress|onkeydown|onkeyup]',
                browser_spellcheck: true,
                selector: '#composeBody',
                relative_urls: false,
                remove_script_host: false,
                entity_encoding: 'raw',
                menubar: false,
                statusbar: true,
                resize: true,
                plugins: 'lists link image table code',
                toolbar: 'undo redo | formatselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image table | code',
                init_instance_callback: function(editor) {
                    // Hide private entries by default (checkbox is checked on load)
                    if (document.getElementById('hidePrivate').checked) {
                        editor.dom.doc.querySelectorAll('.is_private').forEach(function(el) {
                            el.style.display = 'none';
                        });
                    }
                }
            });

            document.getElementById("hidePrivate").addEventListener("click", function() {
                var iframe = document.getElementById('composeBody_ifr');
                if (iframe) {
                    iframe.contentDocument.querySelectorAll('.is_private').forEach(function(el) {
                        el.style.display = el.style.display === 'none' ? '' : 'none';
                    });
                }
            });

            document.getElementById('mailaction-preview-btn').addEventListener('click', function() {
                var btn = this;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><?php echo __('Loading...', 'mailaction'); ?>';

                var subject = document.querySelector('input[name="subject"]').value;
                var body = tinymce.get('composeBody') ? tinymce.get('composeBody').getContent() : document.getElementById('composeBody').value;
                var hidePrivate = document.getElementById('hidePrivate').checked ? '1' : '0';

                // Server-side preview: resolves ##ticket.xxx## GLPI tags
                var formData = new FormData();
                formData.append('id', mailactionTicketId);
                formData.append('subject', subject);
                formData.append('body', body);
                formData.append('hide_private', hidePrivate);
                formData.append('_glpi_csrf_token', mailactionCsrfToken);

                fetch(mailactionPreviewUrl, { method: 'POST', body: formData })
                    .then(function(r) {
                        var newToken = r.headers.get('X-Glpi-Csrf-Token');
                        if (newToken) {
                            mailactionCsrfToken = newToken;
                        }
                        return r.text();
                    })
                    .then(function(html) {
                        var previewIframe = document.getElementById('mailaction-preview-iframe');
                        var modal = new bootstrap.Modal(document.getElementById('mailaction-preview-modal'));
                        modal.show();
                        setTimeout(function() {
                            var doc = previewIframe.contentDocument || previewIframe.contentWindow.document;
                            doc.open();
                            doc.write(html);
                            doc.close();
                        }, 150);
                    })
                    .catch(function(err) {
                        alert('Preview failed: ' + err.message);
                    })
                    .finally(function() {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-eye me-2"></i><?php echo __('Preview', 'mailaction'); ?>';
                    });
            });

            document.querySelector('form').addEventListener('submit', function(e) {
                var hasRecipient = document.querySelectorAll('.recipient-checkbox:checked').length > 0;
                var hasCustom = document.getElementById('custom_address').value.trim() !== '';
                if (!hasRecipient && !hasCustom) {
                    e.preventDefault();
                    alert('<?php echo __('Please select at least one recipient or enter a custom email address', 'mailaction'); ?>');
                }
            });
        </script>
        <?php
    }
}
