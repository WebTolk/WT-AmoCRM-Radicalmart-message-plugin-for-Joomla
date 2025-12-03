CREATE TABLE `#__plg_radicalmart_wtamocrmradicalmart` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `radicalmart_order_id` int(11) UNSIGNED NOT NULL COMMENT 'RadicalMart order id',
  `amocrm_lead_id` int(20) NOT NULL COMMENT 'AmoCRM lead id',
  PRIMARY KEY (`id`)
) DEFAULT CHARSET=utf8;