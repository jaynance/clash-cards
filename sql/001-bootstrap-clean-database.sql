-- Clash Cards clean production bootstrap
-- Intended for a NEW/EMPTY MySQL database.
-- Import this file once through phpMyAdmin or the mysql client.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS trade_proposals;
DROP TABLE IF EXISTS player_cards;
DROP TABLE IF EXISTS cards;
DROP TABLE IF EXISTS players;

CREATE TABLE players (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    display_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_players_display_name (display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    category VARCHAR(50) NOT NULL,
    required_qty INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cards_name_category (name, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE player_cards (
    player_id BIGINT UNSIGNED NOT NULL,
    card_id BIGINT UNSIGNED NOT NULL,
    owned_qty INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (player_id, card_id),
    CONSTRAINT fk_player_cards_player
        FOREIGN KEY (player_id) REFERENCES players(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_player_cards_card
        FOREIGN KEY (card_id) REFERENCES cards(id)
        ON DELETE CASCADE,
    INDEX idx_player_cards_card (card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trade_proposals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    initiator_player_id BIGINT UNSIGNED NOT NULL,
    other_player_id BIGINT UNSIGNED NOT NULL,
    give_card_id BIGINT UNSIGNED NOT NULL,
    give_qty INT UNSIGNED NOT NULL,
    receive_card_id BIGINT UNSIGNED NOT NULL,
    receive_qty INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'proposed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_trade_proposals_initiator
        FOREIGN KEY (initiator_player_id) REFERENCES players(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_trade_proposals_other
        FOREIGN KEY (other_player_id) REFERENCES players(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_trade_proposals_give_card
        FOREIGN KEY (give_card_id) REFERENCES cards(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_trade_proposals_receive_card
        FOREIGN KEY (receive_card_id) REFERENCES cards(id)
        ON DELETE CASCADE,

    INDEX idx_trade_proposals_initiator_status
        (initiator_player_id, status),
    INDEX idx_trade_proposals_other_status
        (other_player_id, status),
    INDEX idx_trade_proposals_updated
        (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO cards (name, category, required_qty) VALUES
('Barbarian','Elixir',1),
('Archer','Elixir',1),
('Giant','Elixir',1),
('Goblin','Elixir',1),
('Wall Breaker','Elixir',1),
('Balloon','Elixir',1),
('Wizard','Elixir',1),
('Healer','Elixir',1),
('Dragon','Elixir',1),
('P.E.K.K.A','Elixir',1),
('Baby Dragon','Elixir',1),
('Miner','Elixir',1),
('Electro Dragon','Elixir',1),
('Yeti','Elixir',1),
('Dragon Rider','Elixir',1),
('Electro Titan','Elixir',1),
('Root Rider','Elixir',1),
('Thrower','Elixir',1),
('Meteor Golem','Elixir',1),
('Minion','Dark Elixir',1),
('Hog Rider','Dark Elixir',1),
('Valkyrie','Dark Elixir',1),
('Golem','Dark Elixir',1),
('Witch','Dark Elixir',1),
('Lava Hound','Dark Elixir',1),
('Bowler','Dark Elixir',1),
('Ice Golem','Dark Elixir',1),
('Headhunter','Dark Elixir',1),
('Apprentice Warden','Dark Elixir',1),
('Druid','Dark Elixir',1),
('Furnace','Dark Elixir',1),
('Ruin Witch','Dark Elixir',1),
('Raged Barbarian','Builder Base',1),
('Sneaky Archer','Builder Base',1),
('Boxer Giant','Builder Base',1),
('Beta Minion','Builder Base',1),
('Bomber','Builder Base',1),
('Baby Dragon','Builder Base',1),
('Cannon Cart','Builder Base',1),
('Night Witch','Builder Base',1),
('Drop Ship','Builder Base',1),
('Power P.E.K.K.A','Builder Base',1),
('Hog Glider','Builder Base',1),
('Super Barbarian','Super',1),
('Super Archer','Super',1),
('Super Giant','Super',1),
('Sneaky Goblin','Super',1),
('Super Wall Breaker','Super',1),
('Rocket Balloon','Super',1),
('Super Wizard','Super',1),
('Super Dragon','Super',1),
('Inferno Dragon','Super',1),
('Super Miner','Super',1),
('Super Yeti','Super',1),
('Super Minion','Super',1),
('Super Hog Rider','Super',1),
('Super Valkyrie','Super',1),
('Super Witch','Super',1),
('Ice Hound','Super',1),
('Super Bowler','Super',1)
ON DUPLICATE KEY UPDATE required_qty = VALUES(required_qty);
