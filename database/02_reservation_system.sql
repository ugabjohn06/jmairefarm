-- ============================================================
-- J'Maire Farm Reservation System - tables + demo data
--
-- IMPORT ORDER (phpMyAdmin -> Import):
--   1. 01_jmaire_farm_db.sql      (menu: food, rooms & cottages, activities)
--   2. 02_reservation_system.sql  (this file)
--
-- Demo logins (change these before going live!):
--   admin  / admin123   (Admin / Owner)
--   staff  / staff123   (Staff)
--   guest@example.com / guest123   (Guest)
-- ============================================================

USE jmaire_farm;
SET time_zone = '+08:00';

-- ------------------------------------------------------------
-- USERS (replaces the old adminsys.users table)
-- Guests log in with email; staff/admin can also use a username.
-- ------------------------------------------------------------
CREATE TABLE users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    email      VARCHAR(150) NOT NULL UNIQUE,
    username   VARCHAR(50)  DEFAULT NULL UNIQUE,
    phone      VARCHAR(30)  DEFAULT NULL,
    password   VARCHAR(255) NOT NULL,
    role       ENUM('guest','staff','admin') NOT NULL DEFAULT 'guest',
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used       TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- ACCOMMODATIONS: one row per physical unit (Cottage 1, Cottage 2...)
--   day_cottage    -> day-use, booked one date at a time, rate per_day
--   overnight_room -> booked by nights, rate per_night
-- ------------------------------------------------------------
CREATE TABLE accommodations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    type        ENUM('day_cottage','overnight_room') NOT NULL,
    description TEXT DEFAULT NULL,
    inclusions  VARCHAR(255) DEFAULT NULL,
    capacity    INT NOT NULL DEFAULT 1,
    base_rate   DECIMAL(10,2) NOT NULL,
    rate_type   ENUM('per_day','per_night','per_head') NOT NULL DEFAULT 'per_day',
    photo_path  VARCHAR(255) DEFAULT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    archived_at DATETIME DEFAULT NULL,        -- set when the unit is retired; kept for old bookings
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- ADD-ONS (extra items guests can attach to a booking)
-- ------------------------------------------------------------
CREATE TABLE addons (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    price       DECIMAL(10,2) NOT NULL,
    unit_label  VARCHAR(40) NOT NULL DEFAULT 'each',
    daily_stock INT DEFAULT NULL,             -- how many are available per day; NULL = unlimited
    is_active   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- BOOKINGS
-- Dates are stored as a half-open range [check_in, check_out):
--   overnight: check_out = the morning the guest leaves
--   day-use:   check_out = check_in + 1 day (shown to guests as one date)
-- Two bookings overlap when  a.check_in < b.check_out AND a.check_out > b.check_in
-- so a guest can check in on the same day another one checks out.
-- ------------------------------------------------------------
CREATE TABLE bookings (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    booking_code     VARCHAR(20) NOT NULL UNIQUE,
    user_id          INT DEFAULT NULL,
    guest_name       VARCHAR(120) NOT NULL,
    guest_phone      VARCHAR(30)  NOT NULL,
    guest_email      VARCHAR(150) DEFAULT NULL,
    accommodation_id INT NOT NULL,
    check_in         DATE NOT NULL,
    check_out        DATE NOT NULL,
    num_adults       INT NOT NULL DEFAULT 1,
    num_kids         INT NOT NULL DEFAULT 0,
    include_swimming TINYINT(1) NOT NULL DEFAULT 0,
    total_amount     DECIMAL(10,2) NOT NULL DEFAULT 0,
    downpayment_due  DECIMAL(10,2) NOT NULL DEFAULT 0,
    status           ENUM('pending','confirmed','checked_in','completed','cancelled','expired','rejected')
                     NOT NULL DEFAULT 'pending',
    source           ENUM('online','walk_in') NOT NULL DEFAULT 'online',
    expires_at       DATETIME DEFAULT NULL,
    special_requests TEXT DEFAULT NULL,
    staff_notes      TEXT DEFAULT NULL,
    status_reason    VARCHAR(255) DEFAULT NULL,
    created_by       INT DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (accommodation_id) REFERENCES accommodations(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_avail (accommodation_id, status, check_in, check_out),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Price breakdown, frozen at booking time (rate changes never touch old bookings)
CREATE TABLE booking_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    booking_id  INT NOT NULL,
    item_type   ENUM('accommodation','entrance','swimming','addon','food','other') NOT NULL,
    ref_id      INT DEFAULT NULL,
    description VARCHAR(200) NOT NULL,
    quantity    INT NOT NULL DEFAULT 1,
    unit_price  DECIMAL(10,2) NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- PAYMENTS (GCash / bank receipts uploaded by guests, cash recorded by staff)
-- ------------------------------------------------------------
CREATE TABLE payments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    booking_id    INT NOT NULL,
    amount        DECIMAL(10,2) NOT NULL,
    method        ENUM('gcash','bank','cash') NOT NULL,
    payment_type  ENUM('downpayment','balance','full','other') NOT NULL DEFAULT 'downpayment',
    reference_no  VARCHAR(60) DEFAULT NULL,
    proof_path    VARCHAR(255) DEFAULT NULL,
    status        ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    reject_reason VARCHAR(255) DEFAULT NULL,
    recorded_by   INT DEFAULT NULL,
    verified_by   INT DEFAULT NULL,
    verified_at   DATETIME DEFAULT NULL,
    paid_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_pay_status (status)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SETTINGS (key/value: downpayment %, deadline, GCash number...)
-- ------------------------------------------------------------
CREATE TABLE settings (
    `key`   VARCHAR(60) PRIMARY KEY,
    `value` TEXT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- AUDIT LOG + EMAIL LOG
-- ------------------------------------------------------------
CREATE TABLE audit_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT DEFAULT NULL,
    action      VARCHAR(60) NOT NULL,
    target_type VARCHAR(40) DEFAULT NULL,
    target_id   INT DEFAULT NULL,
    details     VARCHAR(500) DEFAULT NULL,
    ip_address  VARCHAR(45) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE email_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT DEFAULT NULL,
    to_email   VARCHAR(150) NOT NULL,
    subject    VARCHAR(200) NOT NULL,
    body       TEXT NOT NULL,
    status     ENUM('logged','sent','failed') NOT NULL DEFAULT 'logged',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO users (id, name, email, username, phone, password, role) VALUES
(1, 'Farm Owner',  'admin@jmairefarm.test', 'admin', '09170000001', '$2y$10$xcEa/U4FZ8ZRmhorIGQIte8SlCOrn/BuQ8cUuyr.8CLPkEzsIchY.', 'admin'),
(2, 'Front Desk',  'staff@jmairefarm.test', 'staff', '09170000002', '$2y$10$Z/VgcIa4NTLorrQE7ugCE.HPixI70568LmQ3y39hj/u4VWMsZcDDW', 'staff'),
(3, 'Juan Dela Cruz', 'guest@example.com',  NULL,    '09171234567', '$2y$10$24afFKoAiVP5jhDd4kveROX52TjDCdPXOV8RT8PMJH0C.g9IkKIpq', 'guest'),
(4, 'Maria Santos',   'maria@example.com',  NULL,    '09181234567', '$2y$10$24afFKoAiVP5jhDd4kveROX52TjDCdPXOV8RT8PMJH0C.g9IkKIpq', 'guest');

INSERT INTO settings (`key`, `value`) VALUES
('resort_name',            'J''Maire Farm'),
('tagline',                'Farm stays, cottages and good food in the countryside'),
('contact_phone',          '0993 890 0360'),
('contact_email',          'jmairefarm@gmail.com'),
('address',                'Brgy. Sibaguan, Lambunao, Iloilo 5042'),
('facebook_url',           'https://www.facebook.com/Jmairefarm'),
('maps_url',               'https://maps.app.goo.gl/RCytf7VYpvwZjPKK7'),
('downpayment_percent',    '50'),
('payment_deadline_hours', '24'),
('gcash_number',           '0917 000 0000'),
('gcash_name',             'J. M.'),
('bank_name',              'BDO'),
('bank_account_name',      'J''Maire Farm'),
('bank_account_no',        '0000-0000-0000'),
('entrance_adult',         '50'),
('entrance_kid',           '30'),
('swimming_adult',         '80'),
('swimming_kid',           '50'),
('day_hours',              '8:00 AM - 5:00 PM'),
('checkin_time',           '2:00 PM'),
('checkout_time',          '12:00 NN'),
('max_days_ahead',         '180'),
('max_nights',             '7'),
('cancellation_policy',    'Pending bookings can be cancelled anytime online. For confirmed bookings, please message us; downpayments are non-refundable but may be rebooked once within 30 days, subject to availability.');

-- Units. Counts are demo values: confirm with the owner how many of each exist.
INSERT INTO accommodations (id, name, type, description, inclusions, capacity, base_rate, rate_type, photo_path, sort_order) VALUES
(1,  'Open Cottage 1',        'day_cottage',    'Shaded open cottage near the pool, good for 9-12 guests.', NULL, 12, 700.00,  'per_day', 'unit_open_cottage.jpg', 1),
(2,  'Open Cottage 2',        'day_cottage',    'Shaded open cottage near the pool, good for 9-12 guests.', NULL, 12, 700.00,  'per_day', 'unit_open_cottage.jpg', 2),
(3,  'Open Cottage (Large)',  'day_cottage',    'Bigger open cottage for 12-15 guests.',                    NULL, 15, 900.00,  'per_day', 'unit_open_cottage_large_.jpg', 3),
(4,  'Big Cottage',           'day_cottage',    'Our largest cottage for big families and groups of 15-20.', NULL, 20, 1500.00, 'per_day', 'unit_big_cottage.jpg', 4),
(5,  'Kalesa Table & Chairs 1','day_cottage',   'Charming kalesa-style table set for 3-6 guests.',           NULL, 6,  350.00,  'per_day', 'unit_kalesa_table_with_chairs.jpg', 5),
(6,  'Kalesa Table & Chairs 2','day_cottage',   'Charming kalesa-style table set for 3-6 guests.',           NULL, 6,  350.00,  'per_day', 'unit_kalesa_table_with_chairs.jpg', 6),
(7,  'Cottage with Room',     'day_cottage',    'Day cottage with a private room for 3-6 guests.', '10 minutes free kayak for 2', 6, 700.00, 'per_day', 'unit_cottage_with_room.jpg', 7),
(8,  'Deluxe Room A',         'overnight_room', 'Cozy air-conditioned room for 2.',      'Entrance, swimming and breakfast', 2, 2000.00, 'per_night', 'unit_deluxe_room.jpg', 10),
(9,  'Deluxe Room B',         'overnight_room', 'Cozy air-conditioned room for 2.',      'Entrance, swimming and breakfast', 2, 2000.00, 'per_night', 'unit_deluxe_room.jpg', 11),
(10, 'Deluxe Room (4 pax)',   'overnight_room', 'Spacious deluxe room for 4.',           'Entrance, swimming and breakfast', 4, 4000.00, 'per_night', 'unit_deluxe_room_4pax.jpg', 12),
(11, 'Family Room',           'overnight_room', 'Room for a family of 4.',               'Entrance, swimming and breakfast', 4, 3000.00, 'per_night', 'unit_family_room.jpg', 13),
(12, 'Barkada Room',          'overnight_room', 'Room for groups of up to 6 friends.',   'Entrance, swimming and breakfast', 6, 4000.00, 'per_night', 'unit_barkada_room.jpg', 14);

-- Sample add-on prices: the activities in the menu have no listed price yet. Confirm with the owner.
INSERT INTO addons (name, description, price, unit_label) VALUES
('Kayak',            '30 minutes',            150.00, 'per 30 mins'),
('Bonfire setup',    'Firewood and setup',    500.00, 'per setup'),
('KTV',              'Karaoke room',          300.00, 'per hour'),
('Billiards',        'Table rental',          100.00, 'per hour'),
('Extra mattress',   'Overnight rooms only',  300.00, 'per night'),
('Camping tent space','Bring your own tent',  250.00, 'per tent');

-- ------------------------------------------------------------
-- Demo bookings, with dates relative to today so the demo always looks alive.
-- ------------------------------------------------------------
SET @y = YEAR(CURDATE());

INSERT INTO bookings (id, booking_code, user_id, guest_name, guest_phone, guest_email, accommodation_id,
  check_in, check_out, num_adults, num_kids, include_swimming, total_amount, downpayment_due,
  status, source, expires_at, created_by, created_at) VALUES
-- past, completed (feed the reports)
(1, CONCAT('JM-',@y,'-0001'), 3, 'Juan Dela Cruz', '09171234567', 'guest@example.com', 4,
  DATE_SUB(CURDATE(), INTERVAL 150 DAY), DATE_SUB(CURDATE(), INTERVAL 149 DAY), 15, 4, 1, 3770.00, 1885.00, 'completed', 'online', NULL, 3, DATE_SUB(NOW(), INTERVAL 160 DAY)),
(2, CONCAT('JM-',@y,'-0002'), 4, 'Maria Santos', '09181234567', 'maria@example.com', 11,
  DATE_SUB(CURDATE(), INTERVAL 120 DAY), DATE_SUB(CURDATE(), INTERVAL 118 DAY), 2, 2, 0, 6000.00, 3000.00, 'completed', 'online', NULL, 4, DATE_SUB(NOW(), INTERVAL 125 DAY)),
(3, CONCAT('JM-',@y,'-0003'), NULL, 'Pedro Reyes', '09190001111', NULL, 1,
  DATE_SUB(CURDATE(), INTERVAL 90 DAY), DATE_SUB(CURDATE(), INTERVAL 89 DAY), 10, 2, 0, 1260.00, 630.00, 'completed', 'walk_in', NULL, 2, DATE_SUB(NOW(), INTERVAL 90 DAY)),
(4, CONCAT('JM-',@y,'-0004'), 3, 'Juan Dela Cruz', '09171234567', 'guest@example.com', 12,
  DATE_SUB(CURDATE(), INTERVAL 60 DAY), DATE_SUB(CURDATE(), INTERVAL 59 DAY), 6, 0, 0, 4000.00, 2000.00, 'completed', 'online', NULL, 3, DATE_SUB(NOW(), INTERVAL 70 DAY)),
(5, CONCAT('JM-',@y,'-0005'), NULL, 'Ana Lim', '09200002222', 'ana@example.com', 3,
  DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_SUB(CURDATE(), INTERVAL 29 DAY), 12, 3, 1, 3200.00, 1600.00, 'completed', 'online', NULL, NULL, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(6, CONCAT('JM-',@y,'-0006'), 4, 'Maria Santos', '09181234567', 'maria@example.com', 8,
  DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 8 DAY), 2, 0, 0, 4000.00, 2000.00, 'completed', 'online', NULL, 4, DATE_SUB(NOW(), INTERVAL 15 DAY)),
(7, CONCAT('JM-',@y,'-0007'), NULL, 'Carlo Garcia', '09210003333', 'carlo@example.com', 2,
  DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_SUB(CURDATE(), INTERVAL 19 DAY), 8, 0, 0, 1100.00, 550.00, 'cancelled', 'online', NULL, NULL, DATE_SUB(NOW(), INTERVAL 25 DAY)),
-- checked in today
(8, CONCAT('JM-',@y,'-0008'), NULL, 'Liza Mendoza', '09220004444', 'liza@example.com', 10,
  CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 4, 0, 0, 4000.00, 2000.00, 'checked_in', 'online', NULL, NULL, DATE_SUB(NOW(), INTERVAL 5 DAY)),
-- upcoming confirmed
(9, CONCAT('JM-',@y,'-0009'), 3, 'Juan Dela Cruz', '09171234567', 'guest@example.com', 11,
  DATE_ADD(CURDATE(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 5 DAY), 2, 2, 0, 6000.00, 3000.00, 'confirmed', 'online', NULL, 3, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(10, CONCAT('JM-',@y,'-0010'), NULL, 'Ramon Cruz', '09230005555', 'ramon@example.com', 1,
  DATE_ADD(CURDATE(), INTERVAL 7 DAY), DATE_ADD(CURDATE(), INTERVAL 8 DAY), 9, 3, 1, 2260.00, 1130.00, 'confirmed', 'online', NULL, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY)),
-- pending with a receipt waiting to be checked
(11, CONCAT('JM-',@y,'-0011'), 4, 'Maria Santos', '09181234567', 'maria@example.com', 4,
  DATE_ADD(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 11 DAY), 16, 2, 0, 2360.00, 1180.00, 'pending', 'online', DATE_ADD(NOW(), INTERVAL 20 HOUR), 4, DATE_SUB(NOW(), INTERVAL 4 HOUR)),
-- pending, not yet paid
(12, CONCAT('JM-',@y,'-0012'), NULL, 'Grace Tan', '09240006666', 'grace@example.com', 8,
  DATE_ADD(CURDATE(), INTERVAL 14 DAY), DATE_ADD(CURDATE(), INTERVAL 15 DAY), 2, 0, 0, 2000.00, 1000.00, 'pending', 'online', DATE_ADD(NOW(), INTERVAL 22 HOUR), NULL, DATE_SUB(NOW(), INTERVAL 2 HOUR));

INSERT INTO booking_items (booking_id, item_type, ref_id, description, quantity, unit_price, amount) VALUES
(1, 'accommodation', 4, 'Big Cottage (day use)', 1, 1500.00, 1500.00),
(1, 'entrance', NULL, 'Entrance fee - Adult', 15, 50.00, 750.00),
(1, 'entrance', NULL, 'Entrance fee - Kids', 4, 30.00, 120.00),
(1, 'swimming', NULL, 'Swimming fee - Adult', 15, 80.00, 1200.00),
(1, 'swimming', NULL, 'Swimming fee - Kids', 4, 50.00, 200.00),
(2, 'accommodation', 11, 'Family Room x 2 nights', 2, 3000.00, 6000.00),
(3, 'accommodation', 1, 'Open Cottage 1 (day use)', 1, 700.00, 700.00),
(3, 'entrance', NULL, 'Entrance fee - Adult', 10, 50.00, 500.00),
(3, 'entrance', NULL, 'Entrance fee - Kids', 2, 30.00, 60.00),
(4, 'accommodation', 12, 'Barkada Room x 1 night', 1, 4000.00, 4000.00),
(5, 'accommodation', 3, 'Open Cottage (Large) (day use)', 1, 900.00, 900.00),
(5, 'entrance', NULL, 'Entrance fee - Adult', 12, 50.00, 600.00),
(5, 'entrance', NULL, 'Entrance fee - Kids', 3, 30.00, 90.00),
(5, 'swimming', NULL, 'Swimming fee - Adult', 12, 80.00, 960.00),
(5, 'swimming', NULL, 'Swimming fee - Kids', 3, 50.00, 150.00),
(5, 'addon', 2, 'Bonfire setup (per setup)', 1, 500.00, 500.00),
(6, 'accommodation', 8, 'Deluxe Room A x 2 nights', 2, 2000.00, 4000.00),
(7, 'accommodation', 2, 'Open Cottage 2 (day use)', 1, 700.00, 700.00),
(7, 'entrance', NULL, 'Entrance fee - Adult', 8, 50.00, 400.00),
(8, 'accommodation', 10, 'Deluxe Room (4 pax) x 1 night', 1, 4000.00, 4000.00),
(9, 'accommodation', 11, 'Family Room x 2 nights', 2, 3000.00, 6000.00),
(10, 'accommodation', 1, 'Open Cottage 1 (day use)', 1, 700.00, 700.00),
(10, 'entrance', NULL, 'Entrance fee - Adult', 9, 50.00, 450.00),
(10, 'entrance', NULL, 'Entrance fee - Kids', 3, 30.00, 90.00),
(10, 'swimming', NULL, 'Swimming fee - Adult', 9, 80.00, 720.00),
(10, 'swimming', NULL, 'Swimming fee - Kids', 3, 50.00, 150.00),
(10, 'addon', 1, 'Kayak (per 30 mins)', 1, 150.00, 150.00),
(11, 'accommodation', 4, 'Big Cottage (day use)', 1, 1500.00, 1500.00),
(11, 'entrance', NULL, 'Entrance fee - Adult', 16, 50.00, 800.00),
(11, 'entrance', NULL, 'Entrance fee - Kids', 2, 30.00, 60.00),
(12, 'accommodation', 8, 'Deluxe Room A x 1 night', 1, 2000.00, 2000.00);

INSERT INTO payments (booking_id, amount, method, payment_type, reference_no, proof_path, status, recorded_by, verified_by, verified_at, paid_at) VALUES
(1, 1885.00, 'gcash', 'downpayment', '1001234567', NULL, 'verified', 3, 2, DATE_SUB(NOW(), INTERVAL 159 DAY), DATE_SUB(NOW(), INTERVAL 159 DAY)),
(1, 1885.00, 'cash',  'balance',     NULL,         NULL, 'verified', 2, 2, DATE_SUB(NOW(), INTERVAL 149 DAY), DATE_SUB(NOW(), INTERVAL 149 DAY)),
(2, 3000.00, 'bank',  'downpayment', 'BDO-778812', NULL, 'verified', 4, 2, DATE_SUB(NOW(), INTERVAL 124 DAY), DATE_SUB(NOW(), INTERVAL 124 DAY)),
(2, 3000.00, 'cash',  'balance',     NULL,         NULL, 'verified', 2, 2, DATE_SUB(NOW(), INTERVAL 118 DAY), DATE_SUB(NOW(), INTERVAL 118 DAY)),
(3, 1260.00, 'cash',  'full',        NULL,         NULL, 'verified', 2, 2, DATE_SUB(NOW(), INTERVAL 90 DAY), DATE_SUB(NOW(), INTERVAL 90 DAY)),
(4, 2000.00, 'gcash', 'downpayment', '1002345678', NULL, 'verified', 3, 1, DATE_SUB(NOW(), INTERVAL 69 DAY), DATE_SUB(NOW(), INTERVAL 69 DAY)),
(4, 2000.00, 'cash',  'balance',     NULL,         NULL, 'verified', 2, 2, DATE_SUB(NOW(), INTERVAL 59 DAY), DATE_SUB(NOW(), INTERVAL 59 DAY)),
(5, 1600.00, 'gcash', 'downpayment', '1003456789', NULL, 'verified', NULL, 2, DATE_SUB(NOW(), INTERVAL 34 DAY), DATE_SUB(NOW(), INTERVAL 34 DAY)),
(5, 1600.00, 'cash',  'balance',     NULL,         NULL, 'verified', 2, 2, DATE_SUB(NOW(), INTERVAL 29 DAY), DATE_SUB(NOW(), INTERVAL 29 DAY)),
(6, 2000.00, 'gcash', 'downpayment', '1004567890', NULL, 'verified', 4, 2, DATE_SUB(NOW(), INTERVAL 14 DAY), DATE_SUB(NOW(), INTERVAL 14 DAY)),
(6, 2000.00, 'gcash', 'balance',     '1004567999', NULL, 'verified', 2, 2, DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 8 DAY)),
(8, 2000.00, 'gcash', 'downpayment', '1005678901', NULL, 'verified', NULL, 2, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY)),
(9, 3000.00, 'gcash', 'downpayment', '1006789012', NULL, 'verified', 3, 2, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
(10, 1130.00, 'bank', 'downpayment', 'BPI-551203', NULL, 'verified', NULL, 1, NOW(), DATE_SUB(NOW(), INTERVAL 20 HOUR)),
(11, 1180.00, 'gcash', 'downpayment', '1007890123', 'demo_receipt.png', 'pending', 4, NULL, NULL, DATE_SUB(NOW(), INTERVAL 3 HOUR));

INSERT INTO audit_logs (user_id, action, target_type, target_id, details, created_at) VALUES
(1, 'system.setup', NULL, NULL, 'Reservation system installed with demo data', NOW());
