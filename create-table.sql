CREATE TABLE `articles` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(255) NOT NULL,
  `header` varchar(255) NOT NULL,
  `content` text,
  `dt_parsed` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `articles_UN` (`url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;