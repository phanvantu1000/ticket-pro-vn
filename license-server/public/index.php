<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Create license-server/config.php from config.php.example.');
}
$config = require $configFile;
require dirname(__DIR__) . '/src/LicenseRepository.php';
require dirname(__DIR__) . '/src/LicenseKeyCipher.php';

$repo = new LicenseRepository($config['database']);
$keyCipher = null;
try {
    $keyCipher = new LicenseKeyCipher($config['key_encryption_file'] ?? dirname(__DIR__) . '/storage/license-key-encryption.secret');
} catch (Throwable $exception) {
    // Existing license validation remains available if protected key recovery is unavailable.
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

function input(): array {
    $body = $_POST;
    if (!$body) {
        $json = json_decode(file_get_contents('php://input'), true);
        if (is_array($json)) { $body = $json; }
    }
    return $body;
}

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function domain(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('#^https?://#', '', $value);
    $value = preg_replace('#/.*$#', '', $value);
    $value = preg_replace('#^www\.#', '', $value);
    if (!$value || filter_var($value, FILTER_VALIDATE_IP) || !preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $value)) {
        return '';
    }
    return $value;
}

function escape_html(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function status_label(string $status): string {
    $labels = array('active' => 'Đang hoạt động', 'expired' => 'Đã hết hạn', 'suspended' => 'Tạm dừng', 'revoked' => 'Đã thu hồi');
    return $labels[$status] ?? $status;
}

function duration_label(int $durationDays): string {
    return $durationDays === 0 ? 'Vĩnh viễn' : $durationDays . ' ngày';
}

function format_time(string $value): string {
    if (!$value) { return '—'; }
    try {
        $time = new DateTimeImmutable($value);
        return $time->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i');
    } catch (Exception $exception) {
        return $value;
    }
}

function expiry_label(array $license): string {
    $durationDays = (int) ($license['duration_days'] ?? 0);
    $activatedAt = (string) ($license['activated_at'] ?? '');
    $expiresAt = (string) ($license['expires_at'] ?? '');
    if ($activatedAt === '') { return 'Bắt đầu khi kích hoạt'; }
    if ($durationDays === 0 || $expiresAt === '') { return 'Vĩnh viễn'; }
    $expiry = strtotime($expiresAt);
    if ($expiry === false) { return '—'; }
    if ($expiry <= time()) { return 'Đã hết hạn ' . format_time($expiresAt); }
    $days = max(1, (int) ceil(($expiry - time()) / 86400));
    return format_time($expiresAt) . ' · còn ' . $days . ' ngày';
}

function admin_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('tpv_license_admin');
        session_start(array('cookie_httponly' => true, 'cookie_secure' => true, 'cookie_samesite' => 'Strict'));
    }
}

function csrf_token(): string {
    if (empty($_SESSION['tpv_license_csrf'])) {
        $_SESSION['tpv_license_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['tpv_license_csrf'];
}

function valid_csrf(array $input): bool {
    return !empty($input['csrf']) && !empty($_SESSION['tpv_license_csrf']) && hash_equals((string) $_SESSION['tpv_license_csrf'], (string) $input['csrf']);
}

function redirect_admin(): never {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    header('Location: ' . ($path ?: '/'), true, 303);
    exit;
}

function dashboard_url(string $query, string $status, int $page = 1): string {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $params = array();
    if ($query !== '') { $params['q'] = $query; }
    if ($status !== '') { $params['status'] = $status; }
    if ($page > 1) { $params['paged'] = $page; }
    return $path . ($params ? '?' . http_build_query($params) : '');
}

function sign_response(array $payload, array $config): array {
    $key = openssl_pkey_get_private('file://' . $config['private_key_file']);
    if (!$key) { json_response(array('error' => 'License signing key is unavailable.'), 500); }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    openssl_sign($json, $signature, $key, OPENSSL_ALGO_SHA256);
    return array('payload' => base64_encode($json), 'signature' => base64_encode($signature));
}

function license_api(string $operation, LicenseRepository $repo, array $config, ?LicenseKeyCipher $keyCipher): never {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { json_response(array('error' => 'POST required'), 405); }
    $input = input();
    $key = trim((string) ($input['license_key'] ?? ''));
    $requestedDomain = domain((string) ($input['domain'] ?? ''));
    $installId = trim((string) ($input['install_id'] ?? ''));
    if (!$key || !$requestedDomain || !$installId) { json_response(array('error' => 'license_key, HTTPS domain, and install_id are required'), 422); }
    $license = $repo->findByKey($key);
    if (!$license) { json_response(array('error' => 'License key was not found'), 404); }
    if ($keyCipher && empty($license['key_ciphertext'])) {
        try {
            $repo->storeKeyCiphertext((int) $license['id'], $keyCipher->encrypt($key));
            $repo->audit((int) $license['id'], 'key_recovery_enabled', array('source' => 'license_api'));
            $license = $repo->findByKey($key);
        } catch (Throwable $exception) {
            // A recovery-storage failure must not interrupt a valid customer license check.
        }
    }

    // Reinstalling WordPress creates a new install ID; the same domain may claim it during activation.
    $canBindInstallation = $operation === 'activate' && $license['status'] === 'active' && ($license['domain'] === '' || $license['domain'] === $requestedDomain);
    if ($canBindInstallation) {
        $wasBound = $license['domain'] !== '' && $license['install_id'] !== '' && $license['install_id'] !== $installId;
        $repo->bindDomain((int) $license['id'], $requestedDomain, $installId);
        $license = $repo->findByKey($key);
        $repo->audit((int) $license['id'], $wasBound ? 'installation_rebound' : 'installation_bound', array('domain' => $requestedDomain));
    }
    if ($operation === 'deactivate' && $license['domain'] === $requestedDomain && $license['install_id'] === $installId) {
        $repo->unbindDomain((int) $license['id']);
        $repo->audit((int) $license['id'], 'deactivated', array('domain' => $requestedDomain));
        $license = $repo->findByKey($key);
    }
    if ($operation !== 'deactivate' && ($license['domain'] !== $requestedDomain || ($license['install_id'] !== '' && $license['install_id'] !== $installId))) {
        json_response(array('error' => 'License is bound to another domain or installation'), 409);
    }
    if ($operation === 'activate' && $license['status'] === 'active') {
        $license = $repo->activate((int) $license['id']) ?: $license;
    }
    $effectiveStatus = LicenseRepository::effectiveStatus($license);
    $repo->audit((int) $license['id'], $operation, array('domain' => $requestedDomain, 'status' => $license['status']));
    json_response(sign_response(array(
        'license_id' => (int) $license['id'], 'status' => $effectiveStatus,
        'domain' => $requestedDomain, 'issued_at' => gmdate('c'), 'nonce' => bin2hex(random_bytes(16)),
        'duration_days' => (int) $license['duration_days'], 'activated_at' => (string) $license['activated_at'], 'expires_at' => (string) $license['expires_at'],
    ), $config));
}

function admin_auth(array $config): void {
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($config['admin_user'] === '' || $config['admin_password'] === '') {
        http_response_code(500); exit('Set TPV_LICENSE_ADMIN_USER and TPV_LICENSE_ADMIN_PASSWORD before using the dashboard.');
    }
    if (!hash_equals((string) $config['admin_user'], $user) || !hash_equals((string) $config['admin_password'], $pass)) {
        header('WWW-Authenticate: Basic realm="Ticket Pro VN License"');
        http_response_code(401); exit('Authentication required.');
    }
}

function admin_page(LicenseRepository $repo, array $config, ?LicenseKeyCipher $keyCipher): never {
    admin_auth($config);
    admin_session();
    header('Content-Type: text/html; charset=utf-8');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'; style-src 'self'; script-src 'self'");

    $error = '';
    $draft = array('domain' => '', 'note' => '', 'duration_days' => 365);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $input = input();
        $action = (string) ($input['action'] ?? '');
        $isKeyAccessRequest = $action === 'access_key';
        if (!valid_csrf($input)) {
            if ($isKeyAccessRequest) { json_response(array('error' => 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.'), 403); }
            $error = 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.';
        } elseif ($action === 'create') {
            $draft['domain'] = trim((string) ($input['domain'] ?? ''));
            $draft['note'] = trim((string) ($input['note'] ?? ''));
            $draft['duration_days'] = (int) ($input['duration_days'] ?? 365);
            $reservedDomain = $draft['domain'] === '' ? '' : domain($draft['domain']);
            if ($draft['domain'] !== '' && $reservedDomain === '') {
                $error = 'Tên miền chưa hợp lệ. Ví dụ hợp lệ: example.com hoặc www.example.com.';
            } elseif (!in_array($draft['duration_days'], LicenseRepository::allowedDurations(), true)) {
                $error = 'Thời hạn license không hợp lệ.';
            } elseif (!$keyCipher) {
                $error = 'Kho lưu key bảo mật chưa sẵn sàng. Vui lòng liên hệ quản trị máy chủ.';
            } else {
                $key = 'TPV-' . strtoupper(bin2hex(random_bytes(10)));
                try {
                    $repo->create($key, $keyCipher->encrypt($key), $draft['note'], $reservedDomain, $draft['duration_days']);
                    $_SESSION['tpv_license_flash'] = array('type' => 'success', 'key' => $key, 'domain' => $reservedDomain, 'duration_days' => $draft['duration_days'], 'headline' => 'Key ' . duration_label($draft['duration_days']) . ' đã được tạo');
                    redirect_admin();
                } catch (Throwable $exception) {
                    $error = 'Không thể lưu key bảo mật. Vui lòng thử lại.';
                }
            }
        } elseif ($action === 'access_key') {
            $license = $repo->findById((int) ($input['id'] ?? 0));
            $mode = (string) ($input['mode'] ?? 'copy');
            if (!in_array($mode, array('copy', 'reveal'), true) || !$license || !$keyCipher || empty($license['key_ciphertext'])) {
                json_response(array('error' => 'Key này chưa thể khôi phục. Hãy dùng chức năng cấp lại key.'), 422);
            }
            try {
                $key = $keyCipher->decrypt((string) $license['key_ciphertext']);
                if (!$key || !password_verify($key, (string) $license['key_hash'])) {
                    json_response(array('error' => 'Không thể đọc key an toàn. Hãy cấp lại key.'), 422);
                }
                $repo->audit((int) $license['id'], $mode === 'copy' ? 'key_copied' : 'key_revealed', array('mode' => $mode));
                json_response(array('key' => $key));
            } catch (Throwable $exception) {
                json_response(array('error' => 'Không thể đọc key an toàn. Hãy thử lại.'), 500);
            }
        } elseif ($action === 'replace_key') {
            $licenseId = (int) ($input['id'] ?? 0);
            if ((string) ($input['confirm_replace'] ?? '') !== 'REPLACE') {
                $error = 'Để cấp lại key, bạn phải nhập chính xác REPLACE.';
            } elseif (!$keyCipher) {
                $error = 'Kho lưu key bảo mật chưa sẵn sàng. Vui lòng liên hệ quản trị máy chủ.';
            } else {
                $key = 'TPV-' . strtoupper(bin2hex(random_bytes(10)));
                try {
                    if ($repo->replaceKey($licenseId, $key, $keyCipher->encrypt($key))) {
                        $_SESSION['tpv_license_flash'] = array('type' => 'success', 'key' => $key, 'headline' => 'Key đã được cấp lại', 'detail' => 'Key cũ đã mất hiệu lực. Hãy cập nhật key mới trên website khách trước lần xác thực tiếp theo.');
                        redirect_admin();
                    }
                    $error = 'Không tìm thấy key cần cấp lại. Vui lòng tải lại trang.';
                } catch (Throwable $exception) {
                    $error = 'Không thể cấp lại key. Vui lòng thử lại.';
                }
            }
        } elseif ($action === 'status') {
            $status = (string) ($input['status'] ?? '');
            if (!in_array($status, array('active', 'suspended', 'revoked'), true)) {
                $error = 'Trạng thái giấy phép không hợp lệ.';
            } else {
                $repo->setStatus((int) ($input['id'] ?? 0), $status);
                $_SESSION['tpv_license_flash'] = array('type' => 'success', 'message' => 'Đã cập nhật trạng thái giấy phép.');
                redirect_admin();
            }
        } elseif ($action === 'renew') {
            $durationDays = (int) ($input['duration_days'] ?? 365);
            if (!in_array($durationDays, LicenseRepository::allowedDurations(), true)) {
                $error = 'Thời hạn gia hạn không hợp lệ.';
            } elseif ($repo->renew((int) ($input['id'] ?? 0), $durationDays)) {
                $_SESSION['tpv_license_flash'] = array('type' => 'success', 'message' => 'Đã cập nhật thời hạn license: ' . duration_label($durationDays) . '.');
                redirect_admin();
            } else {
                $error = 'Không tìm thấy key cần gia hạn. Vui lòng tải lại trang.';
            }
        } elseif ($action === 'delete') {
            if ((string) ($input['confirm_delete'] ?? '') !== 'DELETE') {
                $error = 'Để xóa key, bạn phải nhập chính xác DELETE.';
            } elseif ($repo->delete((int) ($input['id'] ?? 0))) {
                $_SESSION['tpv_license_flash'] = array('type' => 'success', 'message' => 'Đã xóa key. Nhật ký thao tác vẫn được lưu lại.');
                redirect_admin();
            } else {
                $error = 'Không tìm thấy key cần xóa. Vui lòng tải lại trang.';
            }
        }
    }

    $searchQuery = trim((string) ($_GET['q'] ?? ''));
    $statusFilter = (string) ($_GET['status'] ?? '');
    if (!in_array($statusFilter, array('', 'active', 'expired', 'suspended', 'revoked'), true)) { $statusFilter = ''; }
    $perPage = 25;
    $totalMatches = $repo->dashboardCount($searchQuery, $statusFilter);
    $totalPages = max(1, (int) ceil($totalMatches / $perPage));
    $page = max(1, (int) ($_GET['paged'] ?? 1));
    if ($page > $totalPages) { $page = $totalPages; }
    $licenses = $repo->dashboardPage($searchQuery, $statusFilter, $page, $perPage);
    $paginationPages = array_unique(array_filter(array_merge(array(1, $totalPages), range(max(1, $page - 2), min($totalPages, $page + 2)))));
    sort($paginationPages);

    $flash = $_SESSION['tpv_license_flash'] ?? null;
    unset($_SESSION['tpv_license_flash']);
    $stats = $repo->dashboardStats();
    $csrf = csrf_token();
    ?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Quản lý license | Ticket Pro VN</title>
    <link rel="stylesheet" href="/assets/license-dashboard.css?v=1.5.0">
</head>
<body>
<main class="license-shell" data-csrf="<?php echo escape_html($csrf); ?>">
    <header class="license-hero">
        <div class="brand-lockup"><span class="brand-mark" aria-hidden="true">TP</span><span>Ticket Pro VN</span></div>
        <div class="hero-copy"><p class="eyebrow">LICENSE CONTROL CENTER</p><h1>Quản lý giấy phép</h1><p>Cấp key theo thời hạn, đặt trước website khách hàng và theo dõi kích hoạt, ngày hết hạn trong một nơi.</p></div>
        <div class="hero-rule"><span aria-hidden="true"></span><p>Một key<br><strong>Một website</strong><br>Đếm từ kích hoạt</p></div>
    </header>

    <section class="metrics" aria-label="Tổng quan license">
        <article><span>Tổng key đã cấp</span><strong><?php echo (int) $stats['total']; ?></strong></article>
        <article><span>Còn hiệu lực</span><strong><?php echo (int) $stats['active']; ?></strong></article>
        <article><span>Đã gắn website</span><strong><?php echo (int) $stats['bound']; ?></strong></article>
        <article><span>Chờ kích hoạt</span><strong><?php echo (int) $stats['pending']; ?></strong></article>
    </section>

    <?php if ($error !== '') : ?><div class="notice notice-error" role="alert"><strong>Không thể thực hiện</strong><span><?php echo escape_html($error); ?></span></div><?php endif; ?>
    <?php if (is_array($flash) && ($flash['type'] ?? '') === 'success') : ?>
        <section class="notice notice-success" role="status">
            <?php if (!empty($flash['key'])) : ?>
                <div><strong><?php echo escape_html((string) ($flash['headline'] ?? ('Key ' . duration_label((int) ($flash['duration_days'] ?? 365)) . ' đã được tạo'))); ?></strong><p><?php echo !empty($flash['detail']) ? escape_html((string) $flash['detail']) : (!empty($flash['domain']) ? 'Đã đặt trước cho ' . escape_html((string) $flash['domain']) . '.' : 'Key sẽ được khóa vào website khi kích hoạt lần đầu.'); ?> <?php echo empty($flash['detail']) ? 'Thời hạn chỉ bắt đầu từ lần kích hoạt thành công.' : ''; ?></p></div>
                <div class="key-reveal"><code id="new-license-key"><?php echo escape_html((string) $flash['key']); ?></code><button type="button" class="copy-button" data-copy="<?php echo escape_html((string) $flash['key']); ?>">Sao chép key</button></div>
            <?php else : ?><strong><?php echo escape_html((string) ($flash['message'] ?? 'Đã cập nhật.')); ?></strong><?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="issue-card" aria-labelledby="issue-title">
        <div class="issue-intro"><p class="eyebrow">CẤP PHÉP MỚI</p><h2 id="issue-title">Tạo key cho khách hàng</h2><p>Thời hạn không chạy khi tạo key. Nó chỉ bắt đầu ở lần kích hoạt thành công đầu tiên trên website khách.</p></div>
        <form method="post" class="issue-form">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="csrf" value="<?php echo escape_html($csrf); ?>">
            <label class="form-field"><span>Tên miền website <em>Tùy chọn</em></span><input name="domain" type="text" inputmode="url" autocomplete="off" spellcheck="false" value="<?php echo escape_html($draft['domain']); ?>" placeholder="example.com"><small>Chấp nhận <code>www.example.com</code>; hệ thống tự quy về <code>example.com</code>.</small></label>
            <label class="form-field"><span>Ghi chú khách hàng <em>Tùy chọn</em></span><input name="note" type="text" maxlength="255" value="<?php echo escape_html($draft['note']); ?>" placeholder="Tên khách, gói dịch vụ hoặc ghi chú nội bộ"><small>Chỉ hiển thị trong dashboard quản trị.</small></label>
            <label class="form-field"><span>Thời hạn license</span><select name="duration_days"><?php foreach (LicenseRepository::allowedDurations() as $durationDays) : ?><option value="<?php echo (int) $durationDays; ?>" <?php echo (int) $draft['duration_days'] === $durationDays ? 'selected' : ''; ?>><?php echo escape_html(duration_label($durationDays)); ?><?php echo $durationDays === 365 ? ' (mặc định)' : ''; ?></option><?php endforeach; ?></select><small>Đếm từ lần kích hoạt đầu tiên, không phải ngày tạo key.</small></label>
            <button class="primary-button" type="submit"><span aria-hidden="true">+</span> Cấp key theo thời hạn</button>
        </form>
    </section>

    <section class="licenses-section" aria-labelledby="licenses-title">
        <div class="section-heading"><div><p class="eyebrow">DANH SÁCH QUẢN LÝ</p><h2 id="licenses-title">Key đã cấp</h2></div><p><?php echo (int) $stats['total']; ?> key trong hệ thống</p></div>
        <form method="get" class="license-search" role="search">
            <label><span>Tìm license</span><input type="search" name="q" value="<?php echo escape_html($searchQuery); ?>" placeholder="Tên miền, ghi chú, mã #, mã cài đặt hoặc đầu/cuối key"></label>
            <label><span>Trạng thái</span><select name="status"><option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>Tất cả trạng thái</option><option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Đang hoạt động</option><option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Đã hết hạn</option><option value="suspended" <?php echo $statusFilter === 'suspended' ? 'selected' : ''; ?>>Tạm dừng</option><option value="revoked" <?php echo $statusFilter === 'revoked' ? 'selected' : ''; ?>>Đã thu hồi</option></select></label>
            <button type="submit" class="primary-button">Tìm kiếm</button><?php if ($searchQuery !== '' || $statusFilter !== '') : ?><a class="clear-search" href="<?php echo escape_html(dashboard_url('', '')); ?>">Xóa bộ lọc</a><?php endif; ?>
        </form>
        <p class="results-summary"><?php echo $totalMatches === $stats['total'] ? 'Hiển thị toàn bộ ' . (int) $totalMatches . ' key' : 'Tìm thấy ' . (int) $totalMatches . ' key'; ?><?php echo $totalMatches > 0 ? ' · trang ' . (int) $page . '/' . (int) $totalPages : ''; ?></p>
        <div class="license-list">
            <?php if (!$licenses) : ?><div class="empty-state"><?php echo $totalMatches === 0 && ($searchQuery !== '' || $statusFilter !== '') ? 'Không tìm thấy license phù hợp. Thử tên miền, ghi chú, mã # hoặc xóa bộ lọc.' : 'Chưa có key nào. Hãy tạo key đầu tiên ở biểu mẫu phía trên.'; ?></div><?php endif; ?>
            <?php foreach ($licenses as $license) : ?>
                <?php $effectiveStatus = LicenseRepository::effectiveStatus($license); $recoverable = !empty($license['key_ciphertext']); ?>
                <article class="license-card">
                    <header class="license-card-head">
                        <div class="license-key-block"><span class="license-number">#<?php echo (int) $license['id']; ?></span><code class="key-hint"><?php echo escape_html($license['key_hint']); ?></code><span class="key-storage <?php echo $recoverable ? 'key-storage-ready' : 'key-storage-legacy'; ?>"><?php echo $recoverable ? 'Có thể sao chép lại' : 'Key cũ: cần cấp lại để lưu'; ?></span></div>
                        <span class="status status-<?php echo escape_html($effectiveStatus); ?>"><?php echo escape_html(status_label($effectiveStatus)); ?></span>
                        <?php if ($recoverable) : ?><button type="button" class="copy-button key-access-button" data-key-id="<?php echo (int) $license['id']; ?>" data-key-mode="copy">Sao chép key</button><?php else : ?><button type="button" class="secondary-button replace-button" data-replace-id="<?php echo (int) $license['id']; ?>" data-replace-label="Key #<?php echo (int) $license['id']; ?> (<?php echo escape_html($license['key_hint']); ?>)">Cấp lại key</button><?php endif; ?>
                    </header>
                    <dl class="license-facts">
                        <div><dt>Website</dt><dd><?php echo $license['domain'] !== '' ? '<code class="domain-value">' . escape_html($license['domain']) . '</code>' : '<span class="muted">Chưa gắn website</span>'; ?></dd></div>
                        <div><dt>Gói license</dt><dd><strong><?php echo escape_html(duration_label((int) $license['duration_days'])); ?></strong></dd></div>
                        <div><dt>Hết hạn</dt><dd><span class="expiry-value<?php echo $effectiveStatus === 'expired' ? ' expiry-expired' : ''; ?>"><?php echo escape_html(expiry_label($license)); ?></span></dd></div>
                        <div><dt>Kích hoạt</dt><dd><?php echo $license['activated_at'] !== '' ? '<time datetime="' . escape_html($license['activated_at']) . '">' . escape_html(format_time($license['activated_at'])) . '</time>' : '<span class="muted">Chưa kích hoạt</span>'; ?></dd></div>
                    </dl>
                    <details class="license-manage">
                        <summary>Thông tin và quản trị key</summary>
                        <div class="license-manage-body">
                            <dl class="license-meta"><div><dt>Ghi chú</dt><dd><?php echo $license['note'] !== '' ? escape_html($license['note']) : '—'; ?></dd></div><div><dt>Mã cài đặt</dt><dd><?php echo $license['install_id'] !== '' ? '<code class="install-id" title="' . escape_html($license['install_id']) . '">' . escape_html($license['install_id']) . '</code>' : '<span class="muted">Chưa kích hoạt</span>'; ?></dd></div><div><dt>Cấp lúc</dt><dd><time datetime="<?php echo escape_html($license['created_at']); ?>"><?php echo escape_html(format_time($license['created_at'])); ?></time></dd></div></dl>
                            <div class="manage-actions">
                                <div class="key-recovery-actions"><?php if ($recoverable) : ?><button type="button" class="secondary-button key-access-button" data-key-id="<?php echo (int) $license['id']; ?>" data-key-mode="reveal">Hiện key</button><button type="button" class="secondary-button key-access-button" data-key-id="<?php echo (int) $license['id']; ?>" data-key-mode="copy">Sao chép key</button><?php else : ?><p>Key này được cấp trước tính năng lưu mã hóa. Cấp lại để có thể sao chép về sau.</p><?php endif; ?><button type="button" class="secondary-button replace-button" data-replace-id="<?php echo (int) $license['id']; ?>" data-replace-label="Key #<?php echo (int) $license['id']; ?> (<?php echo escape_html($license['key_hint']); ?>)">Cấp lại key</button></div>
                                <form method="post" class="inline-control"><input type="hidden" name="action" value="renew"><input type="hidden" name="csrf" value="<?php echo escape_html($csrf); ?>"><input type="hidden" name="id" value="<?php echo (int) $license['id']; ?>"><label>Thời hạn<select name="duration_days" aria-label="Thời hạn key <?php echo (int) $license['id']; ?>"><?php foreach (LicenseRepository::allowedDurations() as $durationDays) : ?><option value="<?php echo (int) $durationDays; ?>" <?php echo (int) $license['duration_days'] === $durationDays ? 'selected' : ''; ?>><?php echo escape_html(duration_label($durationDays)); ?></option><?php endforeach; ?></select></label><button type="submit" class="secondary-button"><?php echo $license['activated_at'] === '' ? 'Lưu gói' : 'Gia hạn'; ?></button></form>
                                <form method="post" class="inline-control"><input type="hidden" name="action" value="status"><input type="hidden" name="csrf" value="<?php echo escape_html($csrf); ?>"><input type="hidden" name="id" value="<?php echo (int) $license['id']; ?>"><label>Trạng thái<select name="status" aria-label="Trạng thái key <?php echo (int) $license['id']; ?>"><option value="active" <?php echo $license['status'] === 'active' ? 'selected' : ''; ?>>Đang hoạt động</option><option value="suspended" <?php echo $license['status'] === 'suspended' ? 'selected' : ''; ?>>Tạm dừng</option><option value="revoked" <?php echo $license['status'] === 'revoked' ? 'selected' : ''; ?>>Đã thu hồi</option></select></label><button type="submit" class="secondary-button">Lưu trạng thái</button></form>
                                <button type="button" class="delete-button" data-delete-id="<?php echo (int) $license['id']; ?>" data-delete-label="Key #<?php echo (int) $license['id']; ?> (<?php echo escape_html($license['key_hint']); ?>)">Xóa key</button>
                            </div>
                        </div>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($totalPages > 1) : ?>
            <nav class="license-pagination" aria-label="Phân trang license">
                <?php if ($page > 1) : ?><a href="<?php echo escape_html(dashboard_url($searchQuery, $statusFilter, $page - 1)); ?>">Trang trước</a><?php endif; ?>
                <?php $previousPage = 0; foreach ($paginationPages as $paginationPage) : ?>
                    <?php if ($previousPage && $paginationPage > $previousPage + 1) : ?><span aria-hidden="true">...</span><?php endif; ?>
                    <?php if ($paginationPage === $page) : ?><strong aria-current="page"><?php echo (int) $paginationPage; ?></strong><?php else : ?><a href="<?php echo escape_html(dashboard_url($searchQuery, $statusFilter, $paginationPage)); ?>"><?php echo (int) $paginationPage; ?></a><?php endif; ?>
                    <?php $previousPage = $paginationPage; ?>
                <?php endforeach; ?>
                <?php if ($page < $totalPages) : ?><a href="<?php echo escape_html(dashboard_url($searchQuery, $statusFilter, $page + 1)); ?>">Trang sau</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
<dialog class="key-dialog" id="key-dialog" aria-labelledby="key-dialog-title">
    <div class="delete-dialog-head"><p class="eyebrow">KEY BẢO MẬT</p><button class="dialog-close" type="button" data-key-close aria-label="Đóng">&times;</button></div>
    <h2 id="key-dialog-title">Key bản quyền</h2><p id="key-dialog-label" class="delete-target"></p><p>Chỉ chia sẻ key này qua kênh riêng tư.</p>
    <code class="revealed-key" id="revealed-key"></code><div class="delete-dialog-actions"><button type="button" class="cancel-button" data-key-close>Đóng</button><button type="button" class="copy-button" id="copy-revealed-key">Sao chép key</button></div>
</dialog>
<dialog class="replace-dialog" id="replace-dialog" aria-labelledby="replace-title">
    <div class="delete-dialog-head"><p class="eyebrow">CẤP LẠI KEY</p><button class="dialog-close" type="button" data-replace-close aria-label="Đóng">&times;</button></div>
    <h2 id="replace-title">Cấp lại key?</h2><p id="replace-license-label" class="delete-target"></p><p>Key mới giữ nguyên website, thời hạn và lịch sử. Key cũ sẽ mất hiệu lực ở lần website xác thực tiếp theo; hãy cập nhật key mới cho khách ngay sau đó.</p>
    <form method="post" class="replace-form"><input type="hidden" name="action" value="replace_key"><input type="hidden" name="csrf" value="<?php echo escape_html($csrf); ?>"><input type="hidden" id="replace-license-id" name="id" value=""><label class="form-field"><span>Nhập <code>REPLACE</code> để xác nhận</span><input id="replace-confirmation" name="confirm_replace" type="text" autocomplete="off" spellcheck="false" placeholder="REPLACE"></label><div class="delete-dialog-actions"><button type="button" class="cancel-button" data-replace-close>Hủy</button><button type="submit" class="confirm-replace-button" id="confirm-replace-button" disabled>Cấp lại key</button></div></form>
</dialog>
<dialog class="delete-dialog" id="delete-dialog" aria-labelledby="delete-title">
    <div class="delete-dialog-head"><p class="eyebrow">THAO TÁC KHÔNG THỂ HOÀN TÁC</p><button class="dialog-close" type="button" data-delete-close aria-label="Đóng">&times;</button></div>
    <h2 id="delete-title">Xóa key?</h2>
    <p id="delete-license-label" class="delete-target"></p>
    <p>Key sẽ bị xóa khỏi hệ thống cấp phép. Website đang dùng key này sẽ mất hiệu lực khi xác thực license lần tiếp theo.</p>
    <form method="post" class="delete-form">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="csrf" value="<?php echo escape_html($csrf); ?>"><input type="hidden" id="delete-license-id" name="id" value="">
        <label class="form-field"><span>Nhập <code>DELETE</code> để xác nhận</span><input id="delete-confirmation" name="confirm_delete" type="text" autocomplete="off" spellcheck="false" placeholder="DELETE"></label>
        <div class="delete-dialog-actions"><button type="button" class="cancel-button" data-delete-close>Hủy</button><button type="submit" class="confirm-delete-button" id="confirm-delete-button" disabled>Xóa key</button></div>
    </form>
</dialog>
<script src="/assets/license-dashboard.js?v=1.5.0" defer></script>
</body>
</html>
<?php
    exit;
}

if (preg_match('#/api/v1/(activate|validate|deactivate|status)$#', $path, $matches)) {
    license_api($matches[1], $repo, $config, $keyCipher);
}
admin_page($repo, $config, $keyCipher);
