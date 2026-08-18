-- V8.17 - Trade proposal workflow
-- Run this once against the Clash Cards database before loading V8.17.

CREATE TABLE IF NOT EXISTS trade_proposals (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
