CREATE TABLE IF NOT EXISTS `dle_yagpt_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int unsigned NOT NULL,
  `processed_at` datetime NOT NULL,
  `used_variables` mediumtext,
  `payload` longtext,
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  KEY `processed_at` (`processed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
