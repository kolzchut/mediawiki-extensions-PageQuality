CREATE TABLE /*_*/pq_settings (
  `id` int unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `setting` varchar(50),
  `value` varchar(50)
);
CREATE INDEX /*i*/pq_settings_index ON /*_*/pq_settings (id);
CREATE UNIQUE INDEX /*i*/pq_settings_setting ON /*_*/pq_settings (setting);
