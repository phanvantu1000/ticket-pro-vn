<?php
declare(strict_types=1);

final class LicenseRepository {
    private PDO $db;

    public function __construct(string $database) {
        $directory = dirname($database);
        if (!is_dir($directory)) { mkdir($directory, 0700, true); }
        $this->db = new PDO('sqlite:' . $database, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $schema = file_get_contents(dirname(__DIR__) . '/schema.sql');
        $this->db->exec($schema);
        // SQLite's CREATE TABLE IF NOT EXISTS does not upgrade already-issued licenses.
        $this->ensureColumn('licenses', 'key_ciphertext', "TEXT NOT NULL DEFAULT ''");
        $this->ensureColumn('licenses', 'duration_days', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('licenses', 'activated_at', "TEXT NOT NULL DEFAULT ''");
        $this->ensureColumn('licenses', 'expires_at', "TEXT NOT NULL DEFAULT ''");
    }

    public static function allowedDurations(): array {
        return array(0, 30, 90, 180, 365);
    }

    public static function normalizeDuration(int $durationDays): int {
        return in_array($durationDays, self::allowedDurations(), true) ? $durationDays : 365;
    }

    public static function effectiveStatus(array $license): string {
        $status = (string) ($license['status'] ?? '');
        $expiresAt = (string) ($license['expires_at'] ?? '');
        if ($status === 'active' && $expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) <= time()) {
            return 'expired';
        }
        return $status;
    }

    public function create(string $key, string $keyCiphertext, string $note, string $domain = '', int $durationDays = 365): int {
        $durationDays = self::normalizeDuration($durationDays);
        $now = gmdate('c');
        $stmt = $this->db->prepare('INSERT INTO licenses (key_hash,key_hint,key_ciphertext,status,domain,note,duration_days,created_at,updated_at) VALUES (:hash,:hint,:ciphertext,"active",:domain,:note,:duration,:created,:updated)');
        $stmt->execute(array(':hash' => password_hash($key, PASSWORD_DEFAULT), ':hint' => substr($key, 0, 8) . '...' . substr($key, -4), ':ciphertext' => $keyCiphertext, ':domain' => $domain, ':note' => $note, ':duration' => $durationDays, ':created' => $now, ':updated' => $now));
        $id = (int) $this->db->lastInsertId();
        $this->audit($id, 'created', array('note' => $note, 'domain' => $domain, 'duration_days' => $durationDays));
        return $id;
    }

    public function findByKey(string $key): ?array {
        $all = $this->db->query('SELECT * FROM licenses ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all as $license) { if (password_verify($key, $license['key_hash'])) { return $license; } }
        return null;
    }

    public function bindDomain(int $id, string $domain, string $installId): void { $this->update($id, 'UPDATE licenses SET domain=:domain, install_id=:install, updated_at=:updated WHERE id=:id', array(':domain'=>$domain, ':install'=>$installId)); }
    public function unbindDomain(int $id): void { $this->update($id, 'UPDATE licenses SET domain="", install_id="", updated_at=:updated WHERE id=:id', array()); }
    public function setStatus(int $id, string $status): void { if (!in_array($status, array('active','suspended','revoked'), true)) { return; } $this->update($id, 'UPDATE licenses SET status=:status, updated_at=:updated WHERE id=:id', array(':status'=>$status)); $this->audit($id, 'status_changed', array('status'=>$status)); }
    public function storeKeyCiphertext(int $id, string $keyCiphertext): void { $this->update($id, 'UPDATE licenses SET key_ciphertext=:ciphertext, updated_at=:updated WHERE id=:id', array(':ciphertext' => $keyCiphertext)); }
    public function replaceKey(int $id, string $key, string $keyCiphertext): bool {
        $license = $this->findById($id);
        if (!$license) { return false; }
        $this->update($id, 'UPDATE licenses SET key_hash=:hash, key_hint=:hint, key_ciphertext=:ciphertext, updated_at=:updated WHERE id=:id', array(':hash' => password_hash($key, PASSWORD_DEFAULT), ':hint' => substr($key, 0, 8) . '...' . substr($key, -4), ':ciphertext' => $keyCiphertext));
        $this->audit($id, 'key_replaced', array('previous_key_hint' => $license['key_hint']));
        return true;
    }

    public function activate(int $id): ?array {
        $license = $this->findById($id);
        if (!$license) { return null; }
        if ((string) $license['activated_at'] !== '') { return $license; }

        $durationDays = (int) $license['duration_days'];
        $now = gmdate('c');
        $expiresAt = $durationDays > 0 ? gmdate('c', time() + ($durationDays * 86400)) : '';
        $this->update($id, 'UPDATE licenses SET activated_at=:activated, expires_at=:expires, updated_at=:updated WHERE id=:id', array(':activated' => $now, ':expires' => $expiresAt));
        $this->audit($id, 'subscription_started', array('duration_days' => $durationDays, 'activated_at' => $now, 'expires_at' => $expiresAt));
        return $this->findById($id);
    }

    public function renew(int $id, int $durationDays): bool {
        $license = $this->findById($id);
        if (!$license) { return false; }
        $durationDays = self::normalizeDuration($durationDays);
        $activatedAt = (string) $license['activated_at'];
        $expiresAt = '';
        if ($activatedAt !== '' && $durationDays > 0) {
            $currentExpiry = strtotime((string) $license['expires_at']);
            $base = $currentExpiry !== false && $currentExpiry > time() ? $currentExpiry : time();
            $expiresAt = gmdate('c', $base + ($durationDays * 86400));
        }
        $this->update($id, 'UPDATE licenses SET duration_days=:duration, expires_at=:expires, updated_at=:updated WHERE id=:id', array(':duration' => $durationDays, ':expires' => $expiresAt));
        $this->audit($id, $activatedAt === '' ? 'term_updated_before_activation' : 'renewed', array('duration_days' => $durationDays, 'expires_at' => $expiresAt));
        return true;
    }
    public function delete(int $id): bool {
        $lookup = $this->db->prepare('SELECT id,key_hint,status,domain,install_id,note FROM licenses WHERE id=:id');
        $lookup->execute(array(':id' => $id));
        $license = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$license) { return false; }
        $this->db->beginTransaction();
        try {
            $this->audit($id, 'deleted', array('key_hint' => $license['key_hint'], 'status' => $license['status'], 'domain' => $license['domain'], 'install_id' => $license['install_id'], 'note' => $license['note']));
            $delete = $this->db->prepare('DELETE FROM licenses WHERE id=:id');
            $delete->execute(array(':id' => $id));
            $this->db->commit();
            return $delete->rowCount() === 1;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $exception;
        }
    }
    private function update(int $id, string $sql, array $values): void { $values[':id']=$id; $values[':updated']=gmdate('c'); $stmt=$this->db->prepare($sql); $stmt->execute($values); }
    public function findById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM licenses WHERE id=:id');
        $stmt->execute(array(':id' => $id));
        $license = $stmt->fetch(PDO::FETCH_ASSOC);
        return $license ?: null;
    }
    private function ensureColumn(string $table, string $column, string $definition): void {
        $columns = $this->db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $current) {
            if (($current['name'] ?? '') === $column) { return; }
        }
        $this->db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
    private function dashboardWhere(string $query, string $status): array {
        $where = array();
        $values = array();
        $query = trim($query);
        if ($query !== '') {
            if (preg_match('/^#?(\d+)$/', $query, $matches)) {
                $where[] = 'id=:license_id';
                $values[':license_id'] = (int) $matches[1];
            } else {
                $where[] = '(key_hint LIKE :query OR domain LIKE :query OR note LIKE :query OR install_id LIKE :query)';
                $values[':query'] = '%' . $query . '%';
            }
        }
        $now = gmdate('c');
        if ($status === 'active') {
            $where[] = "status='active' AND (expires_at='' OR expires_at>:now)";
            $values[':now'] = $now;
        } elseif ($status === 'expired') {
            $where[] = "status='active' AND expires_at<>'' AND expires_at<=:now";
            $values[':now'] = $now;
        } elseif (in_array($status, array('suspended', 'revoked'), true)) {
            $where[] = 'status=:status';
            $values[':status'] = $status;
        }
        return array($where ? ' WHERE ' . implode(' AND ', $where) : '', $values);
    }
    public function dashboardCount(string $query = '', string $status = ''): int {
        list($where, $values) = $this->dashboardWhere($query, $status);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM licenses' . $where);
        $stmt->execute($values);
        return (int) $stmt->fetchColumn();
    }
    public function dashboardPage(string $query = '', string $status = '', int $page = 1, int $perPage = 25): array {
        list($where, $values) = $this->dashboardWhere($query, $status);
        $page = max(1, $page);
        $perPage = min(100, max(10, $perPage));
        $values[':limit'] = $perPage;
        $values[':offset'] = ($page - 1) * $perPage;
        $stmt = $this->db->prepare('SELECT id,key_hint,key_ciphertext,status,domain,install_id,note,duration_days,activated_at,expires_at,created_at,updated_at FROM licenses' . $where . ' ORDER BY id DESC LIMIT :limit OFFSET :offset');
        foreach ($values as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function dashboardStats(): array {
        $now = gmdate('c');
        $stmt = $this->db->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status='active' AND (expires_at='' OR expires_at>:now) THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN domain<>'' THEN 1 ELSE 0 END) AS bound, SUM(CASE WHEN activated_at='' THEN 1 ELSE 0 END) AS pending FROM licenses");
        $stmt->execute(array(':now' => $now));
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        return array('total' => (int) $stats['total'], 'active' => (int) $stats['active'], 'bound' => (int) $stats['bound'], 'pending' => (int) $stats['pending']);
    }
    public function all(): array { return $this->db->query('SELECT id,key_hint,key_ciphertext,status,domain,install_id,note,duration_days,activated_at,expires_at,created_at,updated_at FROM licenses ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC); }
    public function audit(int $licenseId, string $event, array $details): void { $stmt=$this->db->prepare('INSERT INTO audit_log (license_id,event_name,details,created_at) VALUES (:id,:event,:details,:created)'); $stmt->execute(array(':id'=>$licenseId,':event'=>$event,':details'=>json_encode($details),':created'=>gmdate('c'))); }
}
