<?php
/**
 * MailAction - Copyright (C) 2026 DVBNL - GPLv3+
 *
 * Plugin configuration: custom HTML email template + GLPI tag resolution.
 */

class PluginMailactionConfig extends CommonDBTM {

    static $rightname = 'config';

    public static function getTypeName($nb = 0): string {
        return __('MailAction configuration', 'mailaction');
    }

    /**
     * Get the stored HTML template, or the default if none is set.
     */
    public static function getTemplate(): string {
        $config = new self();
        if ($config->getFromDB(1) && !empty($config->fields['html_template'])) {
            return $config->fields['html_template'];
        }
        return self::getDefaultTemplate();
    }

    /**
     * Default HTML email wrapper.
     * Supports {{SUBJECT}}, {{CONTENT}} and all ##ticket.xxx## GLPI tags.
     */
    public static function getDefaultTemplate(): string {
        return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
<style>
/* MailAction theme colors – edit these to match your branding */
.ma-label { color: #1a5276; }
.ma-value { color: #2c3e50; }
.ma-section-title { color: #1a5276; border-bottom: 2px solid #2e86c1; }
.ma-stat-cell { background: #f0f7fd; }
.ma-entry { background: #f0f7fd; border-left: 3px solid #2e86c1; }
.ma-timestamp { color: #5b7fa5; }
.ma-private-badge { background: #e5e7eb; color: #6b7280; }

@media only screen and (max-width: 640px) {
  .ma-content { padding: 22px 16px !important; }
  td[width="33%"], td[width="34%"] { display: block !important; width: 100% !important; padding-bottom: 8px !important; }
}
@media (prefers-color-scheme: dark) {
  .ma-shell { background: #0d1b2a !important; }
  .ma-card { background: #1b2838 !important; border-color: rgba(46,134,193,0.35) !important; box-shadow: 0 30px 60px rgba(0,0,0,0.5) !important; }
  .ma-card, .ma-card td, .ma-card div, .ma-card span, .ma-card p, .ma-card li { color: #e8f4fd !important; }
  .ma-card a { color: #5dade2 !important; }
  .ma-card [bgcolor="#ffffff"] { background: #1b2838 !important; }
  .ma-stat-cell, .ma-entry { background: #1f3044 !important; }
  .ma-import, .ma-import :is(p,div,span,li,td,th,h1,h2,h3) { color: inherit !important; }
}
</style>
</head>
<body style="margin:0;padding:0;width:100%;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#2c3e50;-webkit-text-size-adjust:100%;">
<table class="ma-shell" style="border-spacing:0;width:100%;background-color:#e8f0f8;" role="presentation" cellpadding="0" cellspacing="0" bgcolor="#e8f0f8">
<tr><td align="center" style="padding:24px 12px;">
  <table class="ma-card" style="border-spacing:0;width:100%;max-width:660px;border-radius:16px;overflow:hidden;border:1px solid #bdd5ea;box-shadow:0 16px 40px rgba(26,82,118,0.12);" role="presentation" cellpadding="0" cellspacing="0" bgcolor="#ffffff">
    <!-- Gradient accent bar -->
    <tr><td style="background:linear-gradient(135deg,#1a5276 0%,#2e86c1 45%,#27ae60 100%);height:5px;font-size:1px;line-height:1px;">&nbsp;</td></tr>
    <!-- Header -->
    <tr><td style="padding:24px 32px 16px 32px;background:linear-gradient(180deg,#f0f7fd 0%,#ffffff 100%);">
      <table style="border-spacing:0;" role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td class="ma-label" style="font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:1.5px;padding-bottom:6px;">
            <span style="display:inline-block;background:#eaf5eb;color:#27ae60;padding:3px 10px;border-radius:4px;font-size:10px;font-weight:bold;letter-spacing:1px;">TICKET UPDATE</span>
          </td>
        </tr>
        <tr>
          <td class="ma-label" style="font-size:18px;font-weight:700;line-height:1.35;">{{SUBJECT}}</td>
        </tr>
      </table>
    </td></tr>
    <!-- Divider -->
    <tr><td style="padding:0 32px;"><div class="ma-section-title" style="opacity:0.2;">&nbsp;</div></td></tr>
    <!-- Body -->
    <tr><td class="ma-content ma-value" style="padding:20px 32px 28px 32px;font-size:14px;line-height:1.65;">
      <div class="ma-import">{{CONTENT}}</div>
    </td></tr>
    <!-- Footer -->
    <tr><td class="ma-stat-cell" style="padding:16px 32px;border-top:1px solid #d4e6f1;text-align:center;">
      <span style="font-size:12px;color:#7fb3d8;">Sent via GLPI &mdash; MailAction Plugin</span>
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>';
    }

    /**
     * Apply the template: replace {{SUBJECT}} and {{CONTENT}} placeholders.
     */
    public static function applyTemplate(string $subject, string $body): string {
        $template = self::getTemplate();
        $html = str_replace('{{SUBJECT}}', htmlspecialchars($subject), $template);
        $html = str_replace('{{CONTENT}}', $body, $html);
        return $html;
    }

    /**
     * Resolve all GLPI notification tags (##ticket.xxx##, ##FOREACH##, ##IF##)
     * in a rendered HTML string using GLPI's NotificationTargetTicket engine.
     */
    public static function resolveGlpiTags(int $ticketId, string $html): string {
        // Quick check: skip if no ## tags present
        if (strpos($html, '##') === false) {
            return $html;
        }

        try {
            $ticket = new Ticket();
            if (!$ticket->getFromDB($ticketId)) {
                return $html;
            }

            $target = new NotificationTargetTicket(
                $ticket->getEntityID(),
                'update',
                $ticket
            );
            $target->obj = $ticket;
            $target->addDataForTemplate('update');

            $data = $target->data ?? [];
            if (empty($data)) {
                return $html;
            }

            return self::processGlpiTags($html, $data);
        } catch (\Throwable $e) {
            Toolbox::logInFile('mail', "[MailAction] Failed to resolve GLPI tags: " . $e->getMessage() . "\n");
            return $html;
        }
    }

    /**
     * Process ##tag## patterns against the data populated by NotificationTargetTicket.
     *
     * Handles:
     *  - ##FOREACHcollection## ... ##ENDFOREACHcollection##  (loops)
     *  - ##FOREACH FIRST N collection## / ##FOREACH LAST N collection##
     *  - ##IFtag## ... ##ENDIFtag##  (conditionals)
     *  - ##ELSEtag## ... ##ENDELSEtag##  (else branch)
     *  - ##tag##  (simple value replacement)
     */
    private static function processGlpiTags(string $html, array $data): string {
        // 1. Process FOREACH blocks (with optional FIRST/LAST N)
        $html = preg_replace_callback(
            '/##FOREACH(?:\s+(FIRST|LAST)\s+(\d+))?\s*(\w+)##(.*?)##ENDFOREACH\3##/si',
            function ($m) use ($data) {
                $direction  = strtoupper($m[1] ?? '');
                $limit      = (int)($m[2] ?? 0);
                $collection = $m[3];
                $inner      = $m[4];

                $items = $data[$collection] ?? [];
                if (empty($items) || !is_array($items)) {
                    return '';
                }

                if ($limit > 0) {
                    $items = ($direction === 'LAST')
                        ? array_slice($items, -$limit)
                        : array_slice($items, 0, $limit);
                }

                $out = '';
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $block = $inner;
                    foreach ($item as $tag => $value) {
                        if (is_string($value)) {
                            $block = str_replace($tag, $value, $block);
                        }
                    }
                    $out .= $block;
                }
                return $out;
            },
            $html
        );

        // 2. Process IF/ELSE/ENDIF blocks
        //    Pattern: ##IFtag.name## content ##ENDIFtag.name##
        //    Optional: ##ELSEtag.name## else-content ##ENDELSEtag.name##
        $html = preg_replace_callback(
            '/##IF([\w.]+)##(.*?)##ENDIF\1##(?:\s*##ELSE\1##(.*?)##ENDELSE\1##)?/si',
            function ($m) use ($data) {
                $tag         = '##' . $m[1] . '##';
                $ifContent   = $m[2];
                $elseContent = $m[3] ?? '';

                $value = $data[$tag] ?? '';
                return (!empty($value)) ? $ifContent : $elseContent;
            },
            $html
        );

        // 3. Simple tag replacement (##tag.name##)
        foreach ($data as $tag => $value) {
            if (is_string($value) && str_starts_with($tag, '##') && str_ends_with($tag, '##')) {
                $html = str_replace($tag, $value, $html);
            }
        }

        // 4. Clean up any remaining unreplaced ##tags##
        $html = preg_replace('/##(?:lang\.)?\w+(?:\.\w+)*##/', '', $html);

        return $html;
    }

    /**
     * Show the configuration form.
     */
    public static function showConfigForm(): void {
        $config = new self();
        if (!$config->getFromDB(1)) {
            $config->add(['id' => 1, 'html_template' => '']);
        }

        $template = $config->fields['html_template'] ?? '';
        $usingDefault = empty($template);
        $displayValue = $usingDefault ? self::getDefaultTemplate() : $template;

        echo '<div class="container-fluid">';
        echo '<form method="post" action="' . PLUGIN_MAILACTION_WEB_DIR . '/front/config.form.php">';

        echo '<div class="card mb-3">';
        echo '<div class="card-header"><h3 class="card-title mb-0">';
        echo '<i class="fas fa-palette me-2"></i>';
        echo __('Email HTML template', 'mailaction');
        echo '</h3></div>';
        echo '<div class="card-body">';

        echo '<p class="text-muted">';
        echo __('Customize the HTML wrapper for outgoing emails. Use {{SUBJECT}} and {{CONTENT}} for MailAction content, or use GLPI notification tags like ##ticket.title##, ##ticket.status##, ##FOREACHtasks## etc.', 'mailaction');
        echo '</p>';

        if ($usingDefault) {
            echo '<div class="alert alert-info">';
            echo '<i class="fas fa-info-circle me-1"></i> ';
            echo __('Currently using the default template. Edit below and save to use a custom template.', 'mailaction');
            echo '</div>';
        }

        echo '<textarea name="html_template" id="mailaction_template" rows="25" class="form-control font-monospace" style="font-size: 13px;">';
        echo htmlspecialchars($displayValue);
        echo '</textarea>';

        echo '</div>';
        echo '<div class="card-footer text-center">';
        echo '<button type="submit" name="save" class="btn btn-primary me-2">';
        echo '<i class="fas fa-save me-1"></i> ' . __('Save', 'mailaction');
        echo '</button>';
        echo '<button type="submit" name="reset" class="btn btn-outline-secondary">';
        echo '<i class="fas fa-undo me-1"></i> ' . __('Reset to default', 'mailaction');
        echo '</button>';
        echo '</div>';
        echo '</div>';

        Html::closeForm();
        echo '</div>';
    }
}
