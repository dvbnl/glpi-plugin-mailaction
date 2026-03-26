<?php
/**
 * MailAction - Copyright © 2026 DVBNL - GPLv3+
 *
 * Processes the email composition form and dispatches the message.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . "/inc/includes.php");
}

if (!isset($_POST["send"])) {
    Html::redirect("../index.php");
}

// Ensure the plugin is active and its functions are loaded
if (!function_exists('plugin_mailaction_haveRight')) {
    Session::addMessageAfterRedirect(__('Plugin not active', 'mailaction'), false, ERROR);
    Html::back();
}

// Authorization: require MailAction plugin right
if (!plugin_mailaction_haveRight()) {
    Session::addMessageAfterRedirect(__('Access denied', 'mailaction'), false, ERROR);
    Html::back();
}

$ticketId = (int)$_POST['id'];

// Authorization: require read access to the ticket
$ticket = new Ticket();
if (!$ticket->can($ticketId, READ)) {
    Session::addMessageAfterRedirect(__('Access denied', 'mailaction'), false, ERROR);
    Html::back();
}

$from = filter_var($_POST['from'], FILTER_VALIDATE_EMAIL);
if (!$from) {
    Session::addMessageAfterRedirect(__("Invalid sender email address", 'mailaction'), false, ERROR);
    Html::back();
}

// Gather all selected and custom recipients
$addresses = [];
if (!empty($_POST['recipients']) && is_array($_POST['recipients'])) {
    foreach ($_POST['recipients'] as $raw) {
        $valid = filter_var(trim($raw), FILTER_VALIDATE_EMAIL);
        if ($valid) {
            $addresses[] = $valid;
        }
    }
}
if (!empty($_POST['custom_address'])) {
    $custom = filter_var(trim($_POST['custom_address']), FILTER_VALIDATE_EMAIL);
    if ($custom) {
        $addresses[] = $custom;
    }
}
$addresses = array_values(array_unique($addresses));

if (empty($addresses)) {
    Session::addMessageAfterRedirect(__("No valid recipient specified", 'mailaction'), false, ERROR);
    Html::back();
}

$body = Glpi\RichText\RichText::getSafeHtml($_POST['body'] ?? '');

if (!empty($_POST['hide_private']) && $_POST['hide_private'] == '1') {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $body, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//*[contains(@class,"is_private")]') as $node) {
        // Also remove a following <hr> sibling if present
        $next = $node->nextSibling;
        while ($next && $next->nodeType === XML_TEXT_NODE && trim($next->textContent) === '') {
            $next = $next->nextSibling;
        }
        if ($next && $next->nodeName === 'hr') {
            $next->parentNode->removeChild($next);
        }
        $node->parentNode->removeChild($node);
    }
    $body = $dom->saveHTML();
    // Remove the xml encoding declaration we added
    $body = preg_replace('/<\?xml encoding="utf-8"\?>\s*/', '', $body);
}

$subject = str_replace(["\r", "\n"], ' ', $_POST['subject'] ?? '');
$toList  = implode(', ', $addresses);

// Apply HTML template, then resolve any ##ticket.xxx## GLPI tags
$fullHtml = PluginMailactionConfig::applyTemplate($subject, $body);
$fullHtml = PluginMailactionConfig::resolveGlpiTags($ticketId, $fullHtml);

// Compose and dispatch
$mailer = new GLPIMailer();
$mailer->setFrom($from, $from);
$mailer->addCustomHeader("Auto-Submitted: auto-generated");
$mailer->addCustomHeader("X-Auto-Response-Suppress: OOF, DR, NDR, RN, NRN");

foreach ($addresses as $addr) {
    $mailer->addAddress($addr, $addr);
}

$mailer->isHTML(true);
$mailer->Subject   = $subject;
$mailer->Body      = $fullHtml;
$mailer->MessageID = "GLPI-MA-" . $ticketId . "-" . time() . "." . bin2hex(random_bytes(4)) . "@" . php_uname('n');

if (!$mailer->send()) {
    Session::addMessageAfterRedirect(
        __("Your email could not be processed. If the problem persists, contact the administrator.", 'mailaction'),
        false,
        ERROR
    );
    Toolbox::logInFile("mail",
        "\n[MailAction] Failed: TO=$toList SUBJECT=$subject ERROR=" . ($mailer->ErrorInfo ?? 'unknown')
    );
} else {
    Toolbox::logInFile("mail", "[MailAction] Sent to $toList: $subject\n");

    Log::history($ticketId, 'Ticket', [0, $toList, "$subject<br/>$body"], 'PluginMailactionCompose', Log::HISTORY_PLUGIN + 1024);

    $task = new TicketTask();
    $task->add([
        "tickets_id"  => $ticketId,
        "actiontime"  => 0,
        "state"       => Planning::DONE,
        "is_private"  => PluginMailactionConfig::getTaskPrivate() ? 1 : 0,
        "content"     => __('Email sent via MailAction', 'mailaction') . ' ' . __('to') . ' ' . $toList,
    ]);

    // Attach full email as HTML document
    $filename = 'ma-' . $ticketId . '-' . bin2hex(random_bytes(8)) . '.html';
    $tmpPath  = GLPI_TMP_DIR . "/$filename";
    file_put_contents($tmpPath, $fullHtml);

    try {
        $doc = new Document();
        $prepared = $doc->prepareInputForAdd([
            "items_id"  => $task->getID(),
            "itemtype"  => 'TicketTask',
            "_filename" => [$filename],
        ]);
        if ($prepared) {
            $doc->add($prepared);
        }
    } finally {
        if (file_exists($tmpPath)) {
            unlink($tmpPath);
        }
    }

    Session::addMessageAfterRedirect(sprintf(__('Email sent to %s', 'mailaction'), $toList));
}

$mailer->clearAddresses();
Html::back();
