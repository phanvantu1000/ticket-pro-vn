CREATE TABLE IF NOT EXISTS licenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key_hash TEXT NOT NULL UNIQUE,
    key_hint TEXT NOT NULL,
    key_ciphertext TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active',
    domain TEXT NOT NULL DEFAULT '',
    install_id TEXT NOT NULL DEFAULT '',
    note TEXT NOT NULL DEFAULT '',
    duration_days INTEGER NOT NULL DEFAULT 0,
    activated_at TEXT NOT NULL DEFAULT '',
    expires_at TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    license_id INTEGER NOT NULL DEFAULT 0,
    event_name TEXT NOT NULL,
    details TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);
