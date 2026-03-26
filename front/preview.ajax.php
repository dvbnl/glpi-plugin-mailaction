<?php
/**
 * MailAction - Copyright (C) 2026 DVBNL - GPLv3+
 *
 * AJAX endpoint: renders the email preview server-side, including GLPI tag resolution.
 */

include(dirname(__DIR__, 3) . "/inc/includes.php");

header('Content-Type: text/html; charset=utf-8');

if (!isset($_POST['id'])) {
    echo 'Missing ticket ID';
    exit;
}

$ticketId = (int)$_POST['id'];
$subject  = $_POST['subject'] ?? '';
$body     = stripslashes(html_entity_decode($_POST['body'] ?? ''));

if (!empty($_POST['hide_private']) && $_POST['hide_private'] == '1') {
    $body = preg_replace('/<div class=["\\\\"]*mailaction-entry\s+is_private["\\\\"]*>[\s\S]*?<\/div>/i', '', $body);
}

$fullHtml = PluginMailactionConfig::applyTemplate($subject, $body);
$fullHtml = PluginMailactionConfig::resolveGlpiTags($ticketId, $fullHtml);

echo $fullHtml;
