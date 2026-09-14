<?php
declare(strict_types=1);

final class Schema
{
    public static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS medications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            registration VARCHAR(80) UNIQUE,
            product_name VARCHAR(255) NOT NULL,
            active_ingredient TEXT,
            company VARCHAR(255),
            regulatory_category VARCHAR(255),
            therapeutic_class VARCHAR(255),
            presentation TEXT,
            registration_status VARCHAR(120),
            requires_prescription INTEGER NOT NULL DEFAULT 0,
            retain_prescription INTEGER NOT NULL DEFAULT 0,
            controlled INTEGER NOT NULL DEFAULT 0,
            sell_online INTEGER NOT NULL DEFAULT 0,
            review_required INTEGER NOT NULL DEFAULT 1,
            price DECIMAL(12,2),
            pmc DECIMAL(12,2),
            stock INTEGER NOT NULL DEFAULT 0,
            image_url TEXT,
            source VARCHAR(40) NOT NULL DEFAULT 'ANVISA',
            source_updated_at DATETIME,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_med_name ON medications(product_name)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_med_active ON medications(active_ingredient)");
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'admin',
            active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_name VARCHAR(255) NOT NULL,
            customer_email VARCHAR(255) NOT NULL,
            customer_phone VARCHAR(80),
            status VARCHAR(60) NOT NULL DEFAULT 'pending',
            pharmacist_status VARCHAR(60) NOT NULL DEFAULT 'not_required',
            prescription_path TEXT,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            medication_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY(medication_id) REFERENCES medications(id)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS import_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source VARCHAR(40) NOT NULL,
            status VARCHAR(40) NOT NULL,
            rows_seen INTEGER NOT NULL DEFAULT 0,
            rows_written INTEGER NOT NULL DEFAULT 0,
            message TEXT,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor VARCHAR(255),
            action VARCHAR(120) NOT NULL,
            entity VARCHAR(80),
            entity_id VARCHAR(80),
            payload TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
    }
}
