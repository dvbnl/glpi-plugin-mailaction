<?php
/**
 * MailAction - Copyright (C) 2026 DVBNL - GPLv3+
 *
 * AJAX endpoint: renders the email preview server-side, including GLPI tag resolution.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . "/inc/includes.php");
}

header('Content-Type: text/html; charset=utf-8');
header('X-Glpi-Csrf-Token: ' . Session::getNewCSRFToken());

// Ensure the plugin is active and its functions are loaded
if (!function_exists('plugin_mailaction_haveRight')) {
    http_response_code(403);
    echo 'Plugin not active';
    exit;
}

// Authorization: require MailAction plugin right
if (!plugin_mailaction_haveRight()) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

if (!isset($_POST['id'])) {
    echo 'Missing ticket ID';
    exit;
}

$ticketId = (int)$_POST['id'];

// Authorization: require read access to the ticket
$ticket = new Ticket();
if (!$ticket->can($ticketId, READ)) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$subject  = str_replace(["\r", "\n"], ' ', $_POST['subject'] ?? '');
$body     = Glpi\RichText\RichText::getSafeHtml($_POST['body'] ?? '');

if (!empty($_POST['hide_private']) && $_POST['hide_private'] == '1') {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $body, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//*[contains(@class,"is_private")]') as $node) {
        $node->parentNode->removeChild($node);
    }
    $body = $dom->saveHTML();
    $body = preg_replace('/<\?xml encoding="utf-8"\?>\s*/', '', $body);
}

$fullHtml = PluginMailactionConfig::applyTemplate($subject, $body);
$fullHtml = PluginMailactionConfig::resolveGlpiTags($ticketId, $fullHtml);

echo $fullHtml;
