CREATE TABLE players (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(80) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    category VARCHAR(50) NULL,
    required_qty INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_player_cards_card_qty
    ON player_cards(card_id, owned_qty);
