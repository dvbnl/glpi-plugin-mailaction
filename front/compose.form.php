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

$ticketId = (int)$_POST['id'];

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

$body = stripslashes(html_entity_decode($_POST['body']));

if (!empty($_POST['hide_private']) && $_POST['hide_private'] == '1') {
    $body = preg_replace('/<div class=["\\\\"]*mailaction-entry\s+is_private["\\\\"]*>[\s\S]*?<\/div>\s*<hr>/i', '', $body);
    $body = preg_replace('/<div class=["\\\\"]*is_private["\\\\"]*[^>]*>[\s\S]*?<\/div>/i', '', $body);
}

$subject = $_POST["subject"];
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
        "tickets_id" => $ticketId,
        "actiontime"  => 0,
        "state"       => Planning::DONE,
        "content"     => __('Email sent via MailAction', 'mailaction') . ' ' . __('to') . ' ' . $toList,
    ]);

    // Attach full email as HTML document
    $filename = 'ma-' . $ticketId . '-' . bin2hex(random_bytes(8)) . '.html';
    file_put_contents(GLPI_TMP_DIR . "/$filename", $fullHtml);

    $doc = new Document();
    $prepared = $doc->prepareInputForAdd([
        "items_id"  => $task->getID(),
        "itemtype"  => 'TicketTask',
        "_filename" => [$filename],
    ]);
    if ($prepared) {
        $doc->add($prepared);
    }

    Session::addMessageAfterRedirect(sprintf(__('Email sent to %s', 'mailaction'), $toList));
}

$mailer->clearAddresses();
Html::back();
