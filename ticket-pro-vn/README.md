# Ticket Pro VN

WordPress 6.0+ support-ticket plugin compatible with PHP 5.6+. It creates `Submit a ticket` and `My support tickets` pages during activation and also provides `[tpv_submit_ticket]` and `[tpv_my_tickets]` shortcodes.

## Install and configure

1. Zip the `ticket-pro-vn` directory, upload it in WordPress, and activate it.
2. In **Ticket Pro VN > License**, enter the issued key and select **Activate and validate**. The HTTPS license service and verification key are pinned inside the plugin and are not editable in WordPress.
3. In **Ticket Pro VN > Settings**, configure message limits, attachment categories, blacklist, and retention options. Attachment categories are selected with checkboxes for Images, PDF, Word, Excel, and Text; macro-enabled Office files remain blocked.
4. Edit a WordPress user and enable Ticket Pro VN access, optionally setting a support expiry date.
5. Configure site SMTP normally; notifications use `wp_mail()`.

New subscription keys begin their term on the first successful activation, not
when the key is created. If a signed license expiry is reached, Ticket Pro VN
blocks ticket creation and replies locally, even while the license server is
unreachable. Renew the key in the License Control Center, then use **Activate
and validate** again in WordPress.

Ticket access honors the `tpv_user_email_verified` filter. The default considers a syntactically valid WordPress email verified; a site with an email-verification plugin should return its own verification state from this filter.

## Languages

English is the plugin's source language and Vietnamese is provided as a complete translation. WordPress selects the language automatically from the site language, or from each administrator's language preference in their WordPress profile. The language source file is `languages/ticket-pro-vn.pot`; English and Vietnamese editor packages are included as `.po` and `.mo` files.

## Permanent data removal

Before deleting the plugin, an administrator can use **Ticket Pro VN > Settings > Danger zone** and type `DELETE` to permanently remove Ticket Pro VN ticket records, private attachments, plugin tables, settings, license data, and ticket-access metadata. WordPress users and pages are intentionally preserved. This operation cannot be undone.

## Security notes

The plugin rejects URLs and configured blacklist terms in ticket text, rate limits writes, validates upload MIME/extensions, rejects macro-enabled Office documents, records audit events, and gates downloads through WordPress permission checks. Images are converted to optimized WebP; documents are stored privately without conversion. Attachments are placed under `wp-content/uploads/tpv-private` with an Apache deny rule. For Nginx, add a matching deny rule for `/wp-content/uploads/tpv-private/` so files are available only through the protected WordPress download endpoint.
