-- Lumina 积分账本与积分红包
CREATE TABLE IF NOT EXISTS user_points (
  user_id    BIGINT UNSIGNED NOT NULL,
  balance    BIGINT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_points_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS point_transactions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED NOT NULL,
  amount         BIGINT NOT NULL,
  reason         VARCHAR(120) NOT NULL,
  reference_type VARCHAR(40) NULL,
  reference_id   BIGINT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_point_transactions_user_created (user_id, created_at),
  UNIQUE KEY uk_point_transactions_reference (user_id, reference_type, reference_id),
  CONSTRAINT fk_point_transactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lumina_redpackets (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id         BIGINT UNSIGNED NOT NULL,
  creator_id      BIGINT UNSIGNED NOT NULL,
  mode            VARCHAR(10) NOT NULL DEFAULT 'random',
  total_points    BIGINT UNSIGNED NOT NULL,
  remaining_points BIGINT UNSIGNED NOT NULL,
  total_count     INT UNSIGNED NOT NULL,
  remaining_count INT UNSIGNED NOT NULL,
  title           VARCHAR(120) NOT NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'OPEN',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_lumina_redpackets_post (post_id),
  KEY idx_lumina_redpackets_creator (creator_id),
  CONSTRAINT fk_lumina_redpackets_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
  CONSTRAINT fk_lumina_redpackets_creator FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lumina_redpacket_claims (
  packet_id  BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  amount     BIGINT UNSIGNED NOT NULL,
  claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (packet_id, user_id),
  KEY idx_lumina_redpacket_claims_user (user_id, claimed_at),
  CONSTRAINT fk_lumina_redpacket_claims_packet FOREIGN KEY (packet_id) REFERENCES lumina_redpackets (id) ON DELETE CASCADE,
  CONSTRAINT fk_lumina_redpacket_claims_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
