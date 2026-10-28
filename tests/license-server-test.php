<?php
// Lightweight database tests for the PHP 8.2+ license service.
require dirname(__DIR__) . '/license-server/src/LicenseRepository.php';
require dirname(__DIR__) . '/license-server/src/LicenseKeyCipher.php';

function license_expect($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAILED: " . $message . "\n");
        exit(1);
    }
}

$database = sys_get_temp_dir() . '/tpv-license-test-' . getmypid() . '-' . mt_rand() . '.sqlite';
$secretFile = sys_get_temp_dir() . '/tpv-license-secret-test-' . getmypid() . '-' . mt_rand();
file_put_contents($secretFile, base64_encode(random_bytes(48)));
$cipher = new LicenseKeyCipher($secretFile);
$repo = new LicenseRepository($database);
$key = 'TPV-TEST-ONE';
$licenseId = $repo->create($key, $cipher->encrypt($key), 'Automated test', 'example.com', 365);
$license = $repo->findByKey($key);

license_expect($licenseId > 0, 'license is created');
license_expect($cipher->decrypt($license['key_ciphertext']) === $key, 'new keys are recoverable from encrypted storage');
license_expect((int) $license['duration_days'] === 365, 'new keys default to a 365-day term');
license_expect($license['activated_at'] === '' && $license['expires_at'] === '', 'term has not started before activation');

$repo->bindDomain($licenseId, 'example.com', 'install-test');
$license = $repo->activate($licenseId);
$expiry = strtotime($license['expires_at']);
license_expect($license['activated_at'] !== '', 'first activation is recorded');
license_expect($expiry !== false && $expiry > time() + (364 * 86400), 'expiry begins from first activation');
license_expect(LicenseRepository::effectiveStatus($license) === 'active', 'fresh activation is active');

$pdo = new PDO('sqlite:' . $database);
$pdo->exec("UPDATE licenses SET expires_at='2000-01-01T00:00:00+00:00' WHERE id=" . (int) $licenseId);
$license = $repo->findByKey($key);
license_expect(LicenseRepository::effectiveStatus($license) === 'expired', 'past expiry produces an expired API status');
license_expect($repo->renew($licenseId, 30) === true, 'expired key can be renewed');
$license = $repo->findByKey($key);
license_expect(strtotime($license['expires_at']) > time() + (29 * 86400), 'renewal extends from the present when already expired');

$replacementKey = 'TPV-TEST-TWO';
license_expect($repo->replaceKey($licenseId, $replacementKey, $cipher->encrypt($replacementKey)) === true, 'a lost key can be replaced without creating another license record');
license_expect($repo->findByKey($key) === null, 'replaced key is no longer valid');
$replacement = $repo->findByKey($replacementKey);
license_expect($replacement && $cipher->decrypt($replacement['key_ciphertext']) === $replacementKey, 'replacement key can be recovered securely');

$searchKey = 'TPV-SEARCH-THREE';
$searchId = $repo->create($searchKey, $cipher->encrypt($searchKey), 'Customer Alpha', 'alpha.example.com', 90);
license_expect($repo->dashboardCount('alpha.example.com') === 1, 'dashboard search finds a license by domain');
license_expect($repo->dashboardCount('Customer Alpha') === 1, 'dashboard search finds a license by note');
license_expect($repo->dashboardCount('#' . $searchId) === 1, 'dashboard search finds a license by ID');
license_expect(count($repo->dashboardPage('alpha', 'active', 1, 25)) === 1, 'dashboard search and status filter paginate matching results');
$stats = $repo->dashboardStats();
license_expect($stats['total'] === 2 && $stats['active'] === 2, 'dashboard statistics remain correct when records are paginated');

$legacyDatabase = sys_get_temp_dir() . '/tpv-license-legacy-test-' . getmypid() . '-' . mt_rand() . '.sqlite';
$legacyPdo = new PDO('sqlite:' . $legacyDatabase);
$legacyPdo->exec("CREATE TABLE licenses (id INTEGER PRIMARY KEY AUTOINCREMENT, key_hash TEXT NOT NULL UNIQUE, key_hint TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'active', domain TEXT NOT NULL DEFAULT '', install_id TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
$legacyPdo->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, license_id INTEGER NOT NULL DEFAULT 0, event_name TEXT NOT NULL, details TEXT NOT NULL DEFAULT '{}', created_at TEXT NOT NULL)");
$legacyRepo = new LicenseRepository($legacyDatabase);
$columns = $legacyPdo->query('PRAGMA table_info(licenses)')->fetchAll(PDO::FETCH_COLUMN, 1);
license_expect(in_array('key_ciphertext', $columns, true) && in_array('duration_days', $columns, true) && in_array('activated_at', $columns, true) && in_array('expires_at', $columns, true), 'existing SQLite databases migrate safely');

echo "License server checks passed\n";
