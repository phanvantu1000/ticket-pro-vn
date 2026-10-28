<?php
// Lightweight checks for code paths that do not require a WordPress bootstrap.
define('ABSPATH', __DIR__ . '/');
define('TPV_LICENSE_PUBLIC_KEY_FILE', dirname(__DIR__) . '/ticket-pro-vn/includes/license-public-key.pem');
function wp_strip_all_tags($value) { return strip_tags($value); }
function __($value) { return $value; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
require dirname(__DIR__) . '/ticket-pro-vn/includes/class-tpv-security.php';
require dirname(__DIR__) . '/ticket-pro-vn/includes/class-tpv-license.php';
require dirname(__DIR__) . '/ticket-pro-vn/includes/class-tpv-plugin.php';
require dirname(__DIR__) . '/ticket-pro-vn/includes/class-tpv-admin.php';

function expect_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAILED: " . $message . "\n");
        exit(1);
    }
}

expect_true(TPV_License::normalize_domain('https://www.Example.COM/path') === 'example.com', 'www HTTPS domain normalization');
expect_true(TPV_License::normalize_domain('support.example.com') === 'support.example.com', 'subdomain remains distinct');
expect_true(strpos(TPV_License::public_key(), 'BEGIN PUBLIC KEY') !== false, 'bundled license public key is available');
expect_true(TPV_Security::contains_url('See https://example.com') === true, 'HTTPS URL rejected');
expect_true(TPV_Security::contains_url('See hTtPs : // example.com') === true, 'spaced URL rejected');
expect_true(TPV_Security::contains_url('No links in this ticket') === false, 'plain text accepted');
expect_true(TPV_Plugin::attachment_extensions(array('attachment_mimes' => 'pdf,docm,jpg,xlsx')) === array('pdf', 'jpg', 'xlsx'), 'attachment settings accept only the predefined safe extensions');
expect_true(TPV_Plugin::attachment_extensions_from_groups(array('images', 'word', 'unknown')) === array('jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'), 'attachment category selection expands to known extensions');
expect_true(TPV_Plugin::attachment_accept_attribute(array('attachment_mimes' => 'pdf,txt')) === '.pdf,.txt', 'attachment input accept value follows settings');
expect_true(TPV_Admin::normalize_blacklist_terms("Spam, fraud\nSPAM\nunsafe phrase") === array('Spam', 'fraud', 'unsafe phrase'), 'blacklist accepts commas and lines while removing duplicates');
echo "Plugin logic checks passed\n";
