<?php
declare(strict_types=1);

final class Schema
{
    private static function columns(PDO $db, string $table): array
    {
        $rows = $db->query("PRAGMA table_info($table)")->fetchAll();
        return array_map(static fn(array $row): string => (string)$row['name'], $rows);
    }

    private static function add(PDO $db, string $table, string $name, string $definition): void
    {
        if (!in_array($name, self::columns($db, $table), true)) {
            $db->exec("ALTER TABLE $table ADD COLUMN $name $definition");
        }
    }

    public static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS pharmacies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(120) UNIQUE NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            cnpj VARCHAR(30), cnes VARCHAR(40), afe VARCHAR(80),
            responsible_pharmacist VARCHAR(255), crf VARCHAR(80),
            phone VARCHAR(80), email VARCHAR(255), address TEXT,
            city VARCHAR(120), state VARCHAR(20), zip VARCHAR(20),
            delivery_enabled INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS medications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            registration VARCHAR(80) UNIQUE,
            product_name VARCHAR(255) NOT NULL,
            active_ingredient TEXT,
            company VARCHAR(255), regulatory_category VARCHAR(255), therapeutic_class VARCHAR(255), presentation TEXT,
            registration_status VARCHAR(120),
            requires_prescription INTEGER NOT NULL DEFAULT 0,
            retain_prescription INTEGER NOT NULL DEFAULT 0,
            controlled INTEGER NOT NULL DEFAULT 0,
            remote_delivery_allowed INTEGER NOT NULL DEFAULT 1,
            review_required INTEGER NOT NULL DEFAULT 1,
            price DECIMAL(12,2), pmc DECIMAL(12,2), stock INTEGER NOT NULL DEFAULT 0,
            image_url TEXT, bula_url TEXT,
            source VARCHAR(40) NOT NULL DEFAULT 'ANVISA', source_updated_at DATETIME,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_med_name ON medications(product_name)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_med_active ON medications(active_ingredient)');

        $db->exec("CREATE TABLE IF NOT EXISTS pharmacy_products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pharmacy_id INTEGER NOT NULL,
            medication_id INTEGER NOT NULL,
            sku VARCHAR(80), price DECIMAL(12,2), stock INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 0, image_url TEXT,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(pharmacy_id,medication_id),
            FOREIGN KEY(pharmacy_id) REFERENCES pharmacies(id),
            FOREIGN KEY(medication_id) REFERENCES medications(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pharmacy_id INTEGER,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            name VARCHAR(255), role VARCHAR(40) NOT NULL DEFAULT 'pharmacy_admin',
            active INTEGER NOT NULL DEFAULT 1, permissions TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(pharmacy_id) REFERENCES pharmacies(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pharmacy_id INTEGER, name VARCHAR(255) NOT NULL, email VARCHAR(255), phone VARCHAR(80), cpf VARCHAR(30),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS prescriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pharmacy_id INTEGER, customer_id INTEGER, order_id INTEGER,
            file_path TEXT NOT NULL, original_name TEXT, mime_type VARCHAR(120), sha256 VARCHAR(64),
            status VARCHAR(50) NOT NULL DEFAULT 'awaiting_review',
            pharmacist_id INTEGER, pharmacist_notes TEXT, sncr_number VARCHAR(80), validated_at DATETIME,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pharmacy_id INTEGER, customer_id INTEGER,
            public_token VARCHAR(80),
            customer_name VARCHAR(255) NOT NULL, customer_email VARCHAR(255) NOT NULL, customer_phone VARCHAR(80),
            status VARCHAR(60) NOT NULL DEFAULT 'pending',
            pharmacist_status VARCHAR(60) NOT NULL DEFAULT 'not_required',
            prescription_path TEXT,
            total DECIMAL(12,2) NOT NULL DEFAULT 0, delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(40), payment_status VARCHAR(40) NOT NULL DEFAULT 'pending',
            payment_provider VARCHAR(40), payment_external_id VARCHAR(120),
            payment_qr_code TEXT, payment_qr_code_base64 TEXT, payment_ticket_url TEXT,
            payment_expires_at DATETIME, payment_updated_at DATETIME, paid_at DATETIME,
            delivery_type VARCHAR(30) NOT NULL DEFAULT 'delivery',
            delivery_status VARCHAR(50) NOT NULL DEFAULT 'pending',
            delivery_address TEXT, delivery_city VARCHAR(120), delivery_state VARCHAR(20), delivery_zip VARCHAR(20), delivery_notes TEXT,
            courier_user_id INTEGER, notes TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL, medication_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL, unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            requires_prescription INTEGER NOT NULL DEFAULT 0, controlled INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY(medication_id) REFERENCES medications(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS delivery_zones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pharmacy_id INTEGER NOT NULL, name VARCHAR(120) NOT NULL,
            zip_prefix VARCHAR(20), city VARCHAR(120), fee DECIMAL(12,2) NOT NULL DEFAULT 0,
            free_above DECIMAL(12,2), eta_min INTEGER, eta_max INTEGER, active INTEGER NOT NULL DEFAULT 1
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS chat_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pharmacy_id INTEGER, customer_id INTEGER, session_key VARCHAR(80) UNIQUE NOT NULL,
            human_mode INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL, sender VARCHAR(30) NOT NULL, message TEXT NOT NULL, metadata TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS import_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source VARCHAR(40) NOT NULL, status VARCHAR(40) NOT NULL,
            rows_seen INTEGER NOT NULL DEFAULT 0, rows_written INTEGER NOT NULL DEFAULT 0,
            message TEXT, started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, finished_at DATETIME
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pharmacy_id INTEGER, actor VARCHAR(255), action VARCHAR(120) NOT NULL,
            entity VARCHAR(80), entity_id VARCHAR(80), payload TEXT, ip VARCHAR(80),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS payment_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider VARCHAR(40) NOT NULL,
            event_key VARCHAR(255) NOT NULL UNIQUE,
            order_id INTEGER,
            payload TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(order_id) REFERENCES orders(id)
        )");

        foreach ([
            'pharmacy_id' => 'INTEGER',
            'delivery_fee' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'payment_method' => 'VARCHAR(40)',
            'payment_status' => "VARCHAR(40) NOT NULL DEFAULT 'pending'",
            'payment_provider' => 'VARCHAR(40)',
            'payment_external_id' => 'VARCHAR(120)',
            'payment_qr_code' => 'TEXT',
            'payment_qr_code_base64' => 'TEXT',
            'payment_ticket_url' => 'TEXT',
            'payment_expires_at' => 'DATETIME',
            'payment_updated_at' => 'DATETIME',
            'paid_at' => 'DATETIME',
            'public_token' => 'VARCHAR(80)',
            'delivery_type' => "VARCHAR(30) NOT NULL DEFAULT 'delivery'",
            'delivery_status' => "VARCHAR(50) NOT NULL DEFAULT 'pending'",
            'delivery_address' => 'TEXT',
            'delivery_city' => 'VARCHAR(120)',
            'delivery_state' => 'VARCHAR(20)',
            'delivery_zip' => 'VARCHAR(20)',
            'delivery_notes' => 'TEXT',
            'courier_user_id' => 'INTEGER',
        ] as $name => $definition) {
            self::add($db, 'orders', $name, $definition);
        }

        self::add($db, 'medications', 'remote_delivery_allowed', 'INTEGER NOT NULL DEFAULT 1');
        self::add($db, 'medications', 'bula_url', 'TEXT');
        self::add($db, 'users', 'pharmacy_id', 'INTEGER');
        self::add($db, 'users', 'name', 'VARCHAR(255)');
        self::add($db, 'users', 'permissions', 'TEXT');

        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_public_token ON orders(public_token) WHERE public_token IS NOT NULL');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_orders_payment_external ON orders(payment_external_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_orders_pharmacy_created ON orders(pharmacy_id,created_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_rx_pharmacy_status ON prescriptions(pharmacy_id,status)');
    }
}
