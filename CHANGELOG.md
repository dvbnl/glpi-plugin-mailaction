# Changelog

All notable changes to MailAction will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.1] - 2026-03-26

### Added

- **Private task setting** - tasks created by MailAction are now marked as private by default, configurable in plugin settings

### Fixed

- **Authorization checks** - added plugin right and ticket read-access checks to `compose.form.php` and `preview.ajax.php`, preventing unauthorized access to ticket data and email dispatch
- **Server-side HTML sanitization** - email body is now sanitized via GLPI's `RichText::getSafeHtml()` (HTMLPurifier) instead of relying on client-side TinyMCE filtering
- **Log injection** - subject line is sanitized to strip newline characters before being written to log files
- **Private content filtering** - replaced bypassable regex-based filtering with a DOM-based approach (`DOMDocument`/`DOMXPath`) that correctly handles nested elements
- **Temp file cleanup** - temporary HTML files are now cleaned up in a `finally` block, ensuring removal even if document creation fails

## [1.0.0] - 2026-03-26

Initial release of MailAction for GLPI 11.

### Added

- **Email composition tab** on tickets with rich text editor (TinyMCE)
- **Smart recipient selection** - all ticket requesters, technicians, and observers (GLPI users and external email addresses) shown as checkboxes
- **Custom email addresses** - enter any external email address alongside ticket stakeholders
- **Rich ticket content** - email body pre-filled with ticket metadata (ID, title, status, dates, category, request type, urgency, impact, priority, people) plus content, tasks, and followups
- **Privacy control** - toggle to exclude private tasks and followups from the email
- **Customizable HTML email template** - configure via Setup > Plugins > MailAction, with default responsive template including dark mode support
- **GLPI notification tag support** - use all standard GLPI tags (`##ticket.title##`, `##ticket.status##`, `##FOREACHtasks##`, `##IFticket.category##`, etc.) in custom templates, resolved via GLPI's NotificationTargetTicket engine
- **Email preview** - server-side rendered preview modal showing the exact email output including resolved GLPI tags
- **Sender selection** - choose from GLPI notification sender, admin email, or personal email addresses
- **Audit trail** - every sent email logged as a completed TicketTask with the full HTML email attached as a document
- **Ticket history logging** - email sends recorded in GLPI ticket history
- **Profile-based permissions** - per-profile control over MailAction tab visibility
- **Migration support** - automatic migration from legacy "ticketmail" plugin
- **Multi-language support** - English, Dutch, French, German, Spanish, and Portuguese
- **GLPI 11 native** - built with `$DB->request()`, proper type hints, and GLPI 11 API
- **PHP 8.2+** - uses modern PHP features
- **Input validation** - email addresses validated with `FILTER_VALIDATE_EMAIL`, XSS protection, CSRF tokens
- **Secure email sending** - uses GLPI's `GLPIMailer` with Auto-Submitted and X-Auto-Response-Suppress headers
