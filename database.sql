CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    cat VARCHAR(50) NOT NULL,
    mrp INT NOT NULL,
    price INT NOT NULL,
    icon VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO products (name, cat, mrp, price, icon) VALUES
('12cm Color Sparklers (10 Pcs)', 'Sparklers', 240, 27, '✨'),
('30cm Electric Sparklers (5 Pcs)', 'Sparklers', 300, 35, '✨'),
('Ground Chakkar Big (10 Pcs)', 'Chakkar', 330, 42, '🌀'),
('Ground Chakkar Special (10 Pcs)', 'Chakkar', 600, 95, '🌀'),
('Flower Pots Big (10 Pcs)', 'Flower Pots', 450, 65, '🌋'),
('Color Koti Deluxe (10 Pcs)', 'Flower Pots', 900, 140, '🌋'),
('7 Shots Multi Color (5 Pcs)', 'Aerial', 1300, 145, '🎆'),
('30 Shot Multi Color (1 Pc)', 'Aerial', 4500, 440, '🎆'),
('120 Shot Multi Color (1 Pc)', 'Aerial', 15000, 1520, '🎇'),
('Red Bijili (100 Pcs)', 'Sound', 200, 32, '🧨'),
('Family Gift Box (35 Items)', 'Gift Box', 4000, 850, '🎁');
