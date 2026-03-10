/*
 Navicat Premium Data Transfer

 Source Server         : MySpaLive
 Source Server Type    : MariaDB
 Source Server Version : 100328 (10.3.28-MariaDB)
 Source Host           : 159.223.157.6:3306
 Source Schema         : spalivemd_dev

 Target Server Type    : MariaDB
 Target Server Version : 100328 (10.3.28-MariaDB)
 File Encoding         : 65001

 Date: 10/01/2023 13:51:58
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for _files
-- ----------------------------
DROP TABLE IF EXISTS `_files`;
CREATE TABLE `_files` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `size` double(11,0) NOT NULL,
  `path` varchar(6) NOT NULL,
  `_mimetype_id` int(11) NOT NULL,
  `_filedata_id` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE,
  KEY `mimetype_id` (`_mimetype_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=9624 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for _files_data
-- ----------------------------
DROP TABLE IF EXISTS `_files_data`;
CREATE TABLE `_files_data` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `size` int(11) NOT NULL,
  `data` longblob NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=9624 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for _mimetypes
-- ----------------------------
DROP TABLE IF EXISTS `_mimetypes`;
CREATE TABLE `_mimetypes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mimetype` varchar(100) NOT NULL,
  `type` enum('other','image','js','css','font','xml','video','pdf','xls') NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `mimetype` (`mimetype`) USING BTREE,
  KEY `ext` (`type`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for api_applications
-- ----------------------------
DROP TABLE IF EXISTS `api_applications`;
CREATE TABLE `api_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) COLLATE utf8_unicode_ci NOT NULL,
  `appname` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `debug` int(11) NOT NULL DEFAULT 0,
  `json_config` varchar(1000) COLLATE utf8_unicode_ci NOT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for api_debug
-- ----------------------------
DROP TABLE IF EXISTS `api_debug`;
CREATE TABLE `api_debug` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `version` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `action` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `post` text COLLATE utf8_unicode_ci NOT NULL,
  `post_input` text COLLATE utf8_unicode_ci NOT NULL,
  `get` text COLLATE utf8_unicode_ci NOT NULL,
  `files` text COLLATE utf8_unicode_ci NOT NULL,
  `result` text COLLATE utf8_unicode_ci NOT NULL,
  `error` text COLLATE utf8_unicode_ci NOT NULL,
  `key_id` int(11) NOT NULL,
  `token` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `cache` int(11) NOT NULL,
  `ip` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `agent` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `app_version` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `application_id` (`application_id`) USING BTREE,
  KEY `action` (`action`) USING BTREE,
  KEY `token` (`token`) USING BTREE,
  KEY `key_id` (`key_id`) USING BTREE,
  KEY `createdby` (`createdby`)
) ENGINE=InnoDB AUTO_INCREMENT=1517788 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for api_devices
-- ----------------------------
DROP TABLE IF EXISTS `api_devices`;
CREATE TABLE `api_devices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `device` enum('IOS','ANDROID') NOT NULL,
  `uid` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `version` varchar(255) NOT NULL,
  `created` datetime NOT NULL,
  `developer` int(11) NOT NULL DEFAULT 0,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `device` (`device`) USING BTREE COMMENT '(null)',
  KEY `uid` (`uid`) USING BTREE,
  KEY `token` (`token`) USING BTREE COMMENT '(null)'
) ENGINE=InnoDB AUTO_INCREMENT=3361 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for api_keys
-- ----------------------------
DROP TABLE IF EXISTS `api_keys`;
CREATE TABLE `api_keys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `key` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `type` enum('ANDROID','IOS','SERVER','WEB') COLLATE utf8_unicode_ci NOT NULL,
  `descripcion` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `active` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `key` (`key`) USING BTREE,
  KEY `active` (`active`) USING BTREE,
  KEY `application_id` (`application_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for app_bug
-- ----------------------------
DROP TABLE IF EXISTS `app_bug`;
CREATE TABLE `app_bug` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `file_id` int(11) NOT NULL,
  `op_system` varchar(255) NOT NULL,
  `ver_system` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for app_bug_replies
-- ----------------------------
DROP TABLE IF EXISTS `app_bug_replies`;
CREATE TABLE `app_bug_replies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `bug_id` int(11) NOT NULL,
  `reply` longtext NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for app_master_key
-- ----------------------------
DROP TABLE IF EXISTS `app_master_key`;
CREATE TABLE `app_master_key` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `password` varchar(255) NOT NULL,
  `pass_hash` text NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for app_patient
-- ----------------------------
DROP TABLE IF EXISTS `app_patient`;
CREATE TABLE `app_patient` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(55) NOT NULL,
  `name` varchar(50) NOT NULL,
  `middle_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `full_name` varchar(160) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `state_id` int(11) NOT NULL,
  `city` varchar(55) NOT NULL,
  `street` varchar(100) NOT NULL,
  `zip` varchar(10) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `active` tinyint(1) NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifieby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `state_id` (`state_id`) USING BTREE,
  FULLTEXT KEY `full_name` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for app_tokens
-- ----------------------------
DROP TABLE IF EXISTS `app_tokens`;
CREATE TABLE `app_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_role` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '0',
  `token` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `is_admin` int(11) NOT NULL DEFAULT 0,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `created` datetime DEFAULT NULL,
  `hold` int(11) DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `unico` (`token`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=26537 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for app_university_tokens
-- ----------------------------
DROP TABLE IF EXISTS `app_university_tokens`;
CREATE TABLE `app_university_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_role` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '0',
  `token` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `is_admin` int(11) NOT NULL DEFAULT 0,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `created` datetime DEFAULT NULL,
  `hold` int(11) DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `unico` (`token`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=12828 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for cat_agreements
-- ----------------------------
DROP TABLE IF EXISTS `cat_agreements`;
CREATE TABLE `cat_agreements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) DEFAULT NULL,
  `state_id` int(11) NOT NULL,
  `user_type` enum('PATIENT','INJECTOR','EXAMINER','CLINIC') DEFAULT NULL,
  `agreement_type` enum('REGISTRATION','EXAM','TREAMENT','NEUROTOXINS','FILLERS','SUBSCRIPTION','TERMSANDCONDITIONS','SUBSCRIPTIONMSL','SUBSCRIPTIONMD') DEFAULT NULL,
  `agreement_title` varchar(255) NOT NULL,
  `content` text DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifieby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for cat_brands
-- ----------------------------
DROP TABLE IF EXISTS `cat_brands`;
CREATE TABLE `cat_brands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `info` varchar(255) NOT NULL,
  `deleted` int(11) DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for cat_courses
-- ----------------------------
DROP TABLE IF EXISTS `cat_courses`;
CREATE TABLE `cat_courses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  `school_id` int(11) NOT NULL,
  `price` int(11) NOT NULL,
  `type` enum('NEUROTOXINS BASIC','NEUROTOXINS ADVANCED') COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for cat_faqs
-- ----------------------------
DROP TABLE IF EXISTS `cat_faqs`;
CREATE TABLE `cat_faqs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `question` text NOT NULL,
  `answer` longtext NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for cat_icon_trophy
-- ----------------------------
DROP TABLE IF EXISTS `cat_icon_trophy`;
CREATE TABLE `cat_icon_trophy` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(55) COLLATE utf8_unicode_ci NOT NULL,
  `name` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `file_id` int(11) NOT NULL,
  `type_icon` enum('ICON','FILTER') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'ICON',
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  `deleted` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for cat_instructions
-- ----------------------------
DROP TABLE IF EXISTS `cat_instructions`;
CREATE TABLE `cat_instructions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `instruction` text NOT NULL,
  `order` int(11) NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid_unique` (`uid`) USING BTREE,
  KEY `id` (`id`) USING BTREE,
  KEY `uid` (`uid`) USING BTREE,
  KEY `title` (`title`) USING BTREE,
  KEY `instruction` (`instruction`(255)) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for cat_labels
-- ----------------------------
DROP TABLE IF EXISTS `cat_labels`;
CREATE TABLE `cat_labels` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(60) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `value` text NOT NULL,
  `key_field` varchar(200) NOT NULL DEFAULT '',
  `tipo` enum('HOME','REGISTER','APPLY','WAITING APPLY') NOT NULL DEFAULT 'HOME',
  `field_note` text NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for cat_notifications
-- ----------------------------
DROP TABLE IF EXISTS `cat_notifications`;
CREATE TABLE `cat_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `descr` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `body_push` varchar(255) NOT NULL,
  `fixed` text NOT NULL,
  `fixed_push` varchar(255) NOT NULL,
  `section` varchar(255) NOT NULL,
  `user_type` varchar(255) NOT NULL,
  `last_update` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `title` (`title`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for cat_partnerships
-- ----------------------------
DROP TABLE IF EXISTS `cat_partnerships`;
CREATE TABLE `cat_partnerships` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `info` varchar(255) NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for cat_products
-- ----------------------------
DROP TABLE IF EXISTS `cat_products`;
CREATE TABLE `cat_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category` enum('NEUROTOXINS','FILLERS','MATERIALS','MISCELLANEOUS','FLIP','LIFT','NEUROTOXIN PACKAGES','FILLER PACKAGES','GIFT CARDS') NOT NULL,
  `name` varchar(255) NOT NULL,
  `sold_as` varchar(255) NOT NULL,
  `unit_price` int(11) NOT NULL,
  `unit_cost` int(11) NOT NULL,
  `item_description` text NOT NULL,
  `featured` int(11) NOT NULL DEFAULT 0,
  `stock` int(11) NOT NULL,
  `comission_spalive` int(11) NOT NULL,
  `available_units` int(11) NOT NULL,
  `comission_a` int(11) NOT NULL,
  `comission_b` int(11) NOT NULL,
  `comission_c` int(11) NOT NULL,
  `comission_d` int(11) NOT NULL,
  `hwh` int(11) NOT NULL,
  `shw` int(11) NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `sku` varchar(255) NOT NULL,
  `add_shipping` int(11) NOT NULL DEFAULT 0,
  `purchase_limit` int(11) NOT NULL DEFAULT 0,
  `require_details` int(11) NOT NULL,
  `details_text` varchar(255) NOT NULL,
  `store_type` enum('SPALIVEMD','AMAZON') NOT NULL DEFAULT 'SPALIVEMD',
  `store_link` text NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=86 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for cat_products_price_composition
-- ----------------------------
DROP TABLE IF EXISTS `cat_products_price_composition`;
CREATE TABLE `cat_products_price_composition` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) DEFAULT NULL,
  `vial` int(11) DEFAULT NULL,
  `profit` int(11) DEFAULT NULL,
  `shipping` int(11) DEFAULT NULL,
  `admin_fee` int(11) DEFAULT NULL,
  `doctor_fee` int(11) DEFAULT NULL,
  `multilevel` int(11) DEFAULT NULL,
  `insurance` int(11) DEFAULT NULL,
  `marketing` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for cat_questions
-- ----------------------------
DROP TABLE IF EXISTS `cat_questions`;
CREATE TABLE `cat_questions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `deleted` int(10) unsigned DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for cat_states
-- ----------------------------
DROP TABLE IF EXISTS `cat_states`;
CREATE TABLE `cat_states` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(55) DEFAULT NULL,
  `name` varchar(50) NOT NULL,
  `abv` varchar(4) NOT NULL,
  `cost_ci` int(11) NOT NULL DEFAULT 0,
  `refund_ci` int(11) NOT NULL DEFAULT 0,
  `cost_gfe` int(11) DEFAULT NULL,
  `payment_gfe` int(11) DEFAULT NULL,
  `shipping_cost_both` int(11) NOT NULL,
  `shipping_cost_inj` int(11) NOT NULL,
  `shipping_cost_mat` int(11) NOT NULL,
  `shipping_cost` int(11) NOT NULL DEFAULT 1000,
  `require_ci_license` int(11) NOT NULL DEFAULT 0,
  `phone_number` varchar(10) DEFAULT NULL,
  `enabled` int(11) NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifieby` int(11) NOT NULL,
  `price_sub_msl` int(11) NOT NULL,
  `price_sub_md` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for cat_stripe_instructions
-- ----------------------------
DROP TABLE IF EXISTS `cat_stripe_instructions`;
CREATE TABLE `cat_stripe_instructions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `instruction` text NOT NULL,
  `order` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid_unique` (`uid`) USING BTREE,
  KEY `id` (`id`) USING BTREE,
  KEY `uid` (`uid`) USING BTREE,
  KEY `title` (`title`) USING BTREE,
  KEY `instruction` (`instruction`(255)) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for cat_subscriptions
-- ----------------------------
DROP TABLE IF EXISTS `cat_subscriptions`;
CREATE TABLE `cat_subscriptions` (
  `id` int(11) NOT NULL,
  `uid` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `price_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for cat_trainings
-- ----------------------------
DROP TABLE IF EXISTS `cat_trainings`;
CREATE TABLE `cat_trainings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `scheduled` datetime(6) NOT NULL,
  `logo_id` int(11) NOT NULL,
  `address` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `state_id` int(11) NOT NULL,
  `zip` int(11) NOT NULL,
  `neurotoxins` int(11) NOT NULL DEFAULT 0,
  `fillers` int(11) NOT NULL DEFAULT 0,
  `materials` int(11) DEFAULT 0,
  `miscellaneous` int(11) DEFAULT 0,
  `flip` int(11) NOT NULL DEFAULT 0,
  `lift` int(11) NOT NULL DEFAULT 0,
  `email_sent_date` datetime DEFAULT NULL,
  `sms_sent_date` datetime DEFAULT NULL,
  `email_model_sent_date` datetime DEFAULT NULL,
  `sms_model_sent_date` datetime DEFAULT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `created` datetime(6) NOT NULL,
  `available_seats` int(11) NOT NULL,
  `level` enum('LEVEL 1','LEVEL 2') DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=69 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for cat_treatments
-- ----------------------------
DROP TABLE IF EXISTS `cat_treatments`;
CREATE TABLE `cat_treatments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `type_trmt` enum('NEUROTOXINS','FILLERS','FLIP','LIFT') CHARACTER SET latin1 NOT NULL DEFAULT 'FILLERS',
  `details` int(11) DEFAULT 0,
  `haschild` int(10) unsigned DEFAULT 0,
  `deleted` int(10) unsigned DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=98 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for cat_treatments_category
-- ----------------------------
DROP TABLE IF EXISTS `cat_treatments_category`;
CREATE TABLE `cat_treatments_category` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `type` enum('NEUROTOXINS BASIC','NEUROTOXINS ADVANCED') COLLATE utf8_unicode_ci DEFAULT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for cat_treatments_ci
-- ----------------------------
DROP TABLE IF EXISTS `cat_treatments_ci`;
CREATE TABLE `cat_treatments_ci` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `details` varchar(255) NOT NULL,
  `treatment_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `min` int(11) NOT NULL,
  `max` int(11) NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `created` datetime(6) NOT NULL,
  `category_treatment_id` int(11) DEFAULT 0,
  `description` text DEFAULT NULL,
  `std_price` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=89 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_agreements
-- ----------------------------
DROP TABLE IF EXISTS `data_agreements`;
CREATE TABLE `data_agreements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `user_id` int(11) NOT NULL,
  `agreement_uid` varchar(255) NOT NULL,
  `sign` varchar(255) NOT NULL,
  `file_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `created` datetime DEFAULT NULL,
  `deleted` int(11) DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2956 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_certificates
-- ----------------------------
DROP TABLE IF EXISTS `data_certificates`;
CREATE TABLE `data_certificates` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) DEFAULT NULL,
  `consultation_id` bigint(20) DEFAULT NULL,
  `date_start` date DEFAULT NULL,
  `date_expiration` date DEFAULT NULL,
  `deleted` int(10) unsigned DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=785 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for data_claim_treatments
-- ----------------------------
DROP TABLE IF EXISTS `data_claim_treatments`;
CREATE TABLE `data_claim_treatments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `treatment_uid` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `injector_id` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=270 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_code_confirm
-- ----------------------------
DROP TABLE IF EXISTS `data_code_confirm`;
CREATE TABLE `data_code_confirm` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `code` int(11) DEFAULT NULL,
  `method` enum('SMS','EMAIL') COLLATE utf8_unicode_ci DEFAULT NULL,
  `status` enum('CONFIRMED','NOTCONFIRMED') COLLATE utf8_unicode_ci DEFAULT NULL,
  `expiration` datetime DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `deleted` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=733 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_concierge
-- ----------------------------
DROP TABLE IF EXISTS `data_concierge`;
CREATE TABLE `data_concierge` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `desc` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `lat` double(11,8) DEFAULT NULL,
  `lon` double(11,8) DEFAULT NULL,
  `enabled` int(11) DEFAULT 1,
  `deleted` int(11) DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_consultation
-- ----------------------------
DROP TABLE IF EXISTS `data_consultation`;
CREATE TABLE `data_consultation` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `patient_id` bigint(20) NOT NULL DEFAULT 0,
  `assistance_id` bigint(20) NOT NULL,
  `clinic_patient_id` bigint(20) NOT NULL DEFAULT 0,
  `treatments` varchar(255) NOT NULL,
  `treatments_requested` varchar(255) NOT NULL,
  `payment` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `payment_intent` varchar(255) NOT NULL DEFAULT '',
  `payment_method` varchar(255) NOT NULL,
  `promo_code` varchar(255) NOT NULL,
  `use_credits` int(11) NOT NULL DEFAULT 0,
  `amount` int(11) NOT NULL DEFAULT 0,
  `receipt_url` text NOT NULL,
  `meeting` varchar(255) NOT NULL,
  `meeting_pass` varchar(255) NOT NULL,
  `join_url` varchar(255) DEFAULT NULL,
  `schedule_date` datetime NOT NULL,
  `status` enum('INIT','DONE','CERTIFICATE','ONLINE','CANCEL') DEFAULT 'INIT',
  `schedule_by` bigint(20) NOT NULL,
  `participants` varchar(255) NOT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime(6) NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime(6) DEFAULT NULL,
  `notes` text NOT NULL,
  `reserve_examiner_id` int(11) NOT NULL,
  `is_waiting` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `patient_id` (`patient_id`) USING BTREE,
  KEY `assistance_id` (`assistance_id`) USING BTREE,
  KEY `createdby` (`createdby`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=3204 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for data_consultation_answers
-- ----------------------------
DROP TABLE IF EXISTS `data_consultation_answers`;
CREATE TABLE `data_consultation_answers` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) DEFAULT NULL,
  `consultation_id` bigint(20) DEFAULT NULL,
  `question_id` int(11) DEFAULT NULL,
  `response` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `findings` text DEFAULT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=52566 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for data_consultation_plan
-- ----------------------------
DROP TABLE IF EXISTS `data_consultation_plan`;
CREATE TABLE `data_consultation_plan` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) DEFAULT NULL,
  `consultation_id` bigint(20) DEFAULT NULL,
  `treatment_id` int(11) DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `plan` text DEFAULT NULL,
  `proceed` int(10) unsigned DEFAULT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1113 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for data_consultation_postexam
-- ----------------------------
DROP TABLE IF EXISTS `data_consultation_postexam`;
CREATE TABLE `data_consultation_postexam` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `consultation_id` int(11) DEFAULT NULL,
  `data` text COLLATE utf8_unicode_ci DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `deleted` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=186 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_courses
-- ----------------------------
DROP TABLE IF EXISTS `data_courses`;
CREATE TABLE `data_courses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `course_id` int(11) DEFAULT NULL,
  `training_id` int(11) DEFAULT NULL,
  `status` enum('PENDING','DONE','REJECTED') COLLATE utf8_unicode_ci DEFAULT NULL,
  `payment_intent` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `payment` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `receipt` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `deleted` int(1) DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=354 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_credits
-- ----------------------------
DROP TABLE IF EXISTS `data_credits`;
CREATE TABLE `data_credits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `purchase_uid` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `consultation_id` int(11) NOT NULL,
  `amount` int(11) NOT NULL,
  `created` datetime(6) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_custom_payments
-- ----------------------------
DROP TABLE IF EXISTS `data_custom_payments`;
CREATE TABLE `data_custom_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `type` enum('TRAINING TREATMENT') COLLATE utf8_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `lname` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `service_description` text COLLATE utf8_unicode_ci NOT NULL,
  `total` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `payment_intent` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `payment` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `receipt` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_director_clinic
-- ----------------------------
DROP TABLE IF EXISTS `data_director_clinic`;
CREATE TABLE `data_director_clinic` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(55) COLLATE utf8_unicode_ci NOT NULL,
  `clinic_id` int(11) NOT NULL,
  `director_name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `director_number` varchar(18) COLLATE utf8_unicode_ci NOT NULL,
  `director_license` varchar(15) COLLATE utf8_unicode_ci NOT NULL,
  `file_id` int(11) NOT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_favorites
-- ----------------------------
DROP TABLE IF EXISTS `data_favorites`;
CREATE TABLE `data_favorites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `injector_id` int(11) NOT NULL,
  `deleted` int(11) DEFAULT NULL,
  `created` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=200 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_forum
-- ----------------------------
DROP TABLE IF EXISTS `data_forum`;
CREATE TABLE `data_forum` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `deleted` int(11) DEFAULT 0,
  `created` datetime(6) DEFAULT NULL,
  `createdby` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_forum_likes
-- ----------------------------
DROP TABLE IF EXISTS `data_forum_likes`;
CREATE TABLE `data_forum_likes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `forum_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_gfe_availability
-- ----------------------------
DROP TABLE IF EXISTS `data_gfe_availability`;
CREATE TABLE `data_gfe_availability` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `days` varchar(255) NOT NULL,
  `start` varchar(255) NOT NULL,
  `end` varchar(255) NOT NULL,
  `available` int(11) NOT NULL DEFAULT 1,
  `created` datetime(6) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_gift_cards
-- ----------------------------
DROP TABLE IF EXISTS `data_gift_cards`;
CREATE TABLE `data_gift_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created` datetime NOT NULL,
  `expiration` datetime NOT NULL,
  `user_id` int(11) NOT NULL,
  `receipt_id` int(11) NOT NULL,
  `card_id` int(11) NOT NULL,
  `discount` int(11) DEFAULT NULL,
  `use_date` datetime DEFAULT NULL,
  `active` int(11) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_image_course
-- ----------------------------
DROP TABLE IF EXISTS `data_image_course`;
CREATE TABLE `data_image_course` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) DEFAULT NULL,
  `data_course_id` int(11) DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `deleted` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_injector_month
-- ----------------------------
DROP TABLE IF EXISTS `data_injector_month`;
CREATE TABLE `data_injector_month` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `injector_id` int(11) NOT NULL,
  `state` int(11) NOT NULL,
  `date_injector` date NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `createdby` bigint(20) NOT NULL,
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_medical_directors
-- ----------------------------
DROP TABLE IF EXISTS `data_medical_directors`;
CREATE TABLE `data_medical_directors` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `lname` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `license` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `city` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `state_id` int(11) DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `sign_id` int(11) DEFAULT NULL,
  `subscription_price` int(11) DEFAULT NULL,
  `created` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted` int(10) unsigned DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_messages
-- ----------------------------
DROP TABLE IF EXISTS `data_messages`;
CREATE TABLE `data_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('TREATMENT','NOTIFICATION') NOT NULL,
  `id_from` int(11) NOT NULL,
  `id_to` int(11) NOT NULL,
  `message` text NOT NULL,
  `extra` varchar(255) NOT NULL,
  `read` int(11) NOT NULL DEFAULT 0,
  `readed` int(11) NOT NULL DEFAULT 0,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `created` datetime(6) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=126018 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_model_patient
-- ----------------------------
DROP TABLE IF EXISTS `data_model_patient`;
CREATE TABLE `data_model_patient` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `mname` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `lname` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `requested_training_id` int(11) DEFAULT 0,
  `status` enum('assigned','not assigned') COLLATE utf8_unicode_ci DEFAULT 'not assigned',
  `registered_training_id` int(11) DEFAULT 0,
  `gfe` enum('Yes','No') COLLATE utf8_unicode_ci DEFAULT 'Yes',
  `understand` enum('Yes','No') COLLATE utf8_unicode_ci DEFAULT 'Yes',
  `notes` text COLLATE utf8_unicode_ci DEFAULT NULL,
  `attendance_hour` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `created` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sent_email` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1009 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_network
-- ----------------------------
DROP TABLE IF EXISTS `data_network`;
CREATE TABLE `data_network` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `level` int(11) NOT NULL,
  `lft` int(11) NOT NULL,
  `rght` int(11) NOT NULL,
  `order` int(11) NOT NULL,
  `levels_below` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `id` (`id`) USING BTREE,
  KEY `parent_id` (`parent_id`) USING BTREE,
  KEY `user_id` (`user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=780 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_network_backup
-- ----------------------------
DROP TABLE IF EXISTS `data_network_backup`;
CREATE TABLE `data_network_backup` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `level` int(11) NOT NULL,
  `lft` int(11) NOT NULL,
  `rght` int(11) NOT NULL,
  `order` int(11) NOT NULL,
  `levels_below` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `id` (`id`) USING BTREE,
  KEY `parent_id` (`parent_id`) USING BTREE,
  KEY `user_id` (`user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=418 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_network_invitations
-- ----------------------------
DROP TABLE IF EXISTS `data_network_invitations`;
CREATE TABLE `data_network_invitations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `paid` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=116 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_network_invitees
-- ----------------------------
DROP TABLE IF EXISTS `data_network_invitees`;
CREATE TABLE `data_network_invitees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `meeting_id` (`meeting_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=110 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_network_meeting
-- ----------------------------
DROP TABLE IF EXISTS `data_network_meeting`;
CREATE TABLE `data_network_meeting` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(55) NOT NULL,
  `scheduled_date` datetime NOT NULL,
  `zoom_meeting_id` varchar(255) NOT NULL,
  `createdby` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_newsletter_state
-- ----------------------------
DROP TABLE IF EXISTS `data_newsletter_state`;
CREATE TABLE `data_newsletter_state` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `state_id` int(11) DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `deleted` int(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_notifications
-- ----------------------------
DROP TABLE IF EXISTS `data_notifications`;
CREATE TABLE `data_notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT 0,
  `type` enum('NOTIFICATION') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'NOTIFICATION',
  `message` text COLLATE utf8_unicode_ci NOT NULL,
  `json_users` text COLLATE utf8_unicode_ci NOT NULL,
  `json_data` text COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifieby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=14509 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_participant_trainers
-- ----------------------------
DROP TABLE IF EXISTS `data_participant_trainers`;
CREATE TABLE `data_participant_trainers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `training_id` int(11) DEFAULT NULL,
  `deleted` int(11) DEFAULT 0,
  `attended` int(11) NOT NULL DEFAULT 0,
  `status` enum('PENDING','ACCEPTED','REJECTED') COLLATE utf8_unicode_ci DEFAULT 'PENDING',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_patient_clinic
-- ----------------------------
DROP TABLE IF EXISTS `data_patient_clinic`;
CREATE TABLE `data_patient_clinic` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(55) NOT NULL,
  `injector_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `user_injector_id` (`injector_id`,`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=846 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for data_patient_consult
-- ----------------------------
DROP TABLE IF EXISTS `data_patient_consult`;
CREATE TABLE `data_patient_consult` (
  `patient_clin_id` int(11) NOT NULL,
  `consult_id` int(11) NOT NULL,
  PRIMARY KEY (`patient_clin_id`,`consult_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for data_payment
-- ----------------------------
DROP TABLE IF EXISTS `data_payment`;
CREATE TABLE `data_payment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_from` int(11) NOT NULL,
  `id_to` int(11) NOT NULL,
  `uid` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `service_uid` varchar(255) NOT NULL,
  `type` enum('CI REGISTER','PURCHASE','GFE','TREATMENT','GFE COMMISSION','REFUND','CI COMMISSION','REFUND CI REGISTER','REFUND PRODUCT','SHIPPING REFUND','TRAINING TREATMENT','BASIC COURSE','ADVANCED COURSE','TIP','TIP COMMISSION') CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `intent` varchar(1000) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `payment` varchar(1000) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `receipt` varchar(1000) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `promo_discount` varchar(2) NOT NULL DEFAULT '0',
  `promo_code` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `discount_credits` int(11) NOT NULL DEFAULT 0,
  `subtotal` int(11) NOT NULL,
  `total` int(11) NOT NULL,
  `prod` int(11) NOT NULL DEFAULT 1,
  `prepaid` int(11) NOT NULL,
  `is_visible` int(11) NOT NULL DEFAULT 0,
  `comission_payed` int(11) NOT NULL DEFAULT 0,
  `comission_generated` int(11) NOT NULL DEFAULT 0,
  `created` datetime(6) NOT NULL,
  `createdby` int(11) NOT NULL,
  `refund_id` int(11) NOT NULL,
  `transfer` varchar(255) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `from` (`id_from`),
  KEY `to` (`id_to`),
  KEY `uid` (`uid`),
  KEY `total` (`total`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=6474 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_pre_register
-- ----------------------------
DROP TABLE IF EXISTS `data_pre_register`;
CREATE TABLE `data_pre_register` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `mname` varchar(255) NOT NULL,
  `lname` varchar(255) NOT NULL,
  `state` varchar(255) NOT NULL,
  `state_id` int(11) NOT NULL,
  `status` enum('','PENDING FORM','PENDING AGREEMENT','FINISHED') CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `origin` enum('','PUBLIC SITE','WEB APP') CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `type` varchar(255) NOT NULL,
  `street` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `suite` varchar(255) NOT NULL,
  `zip` varchar(255) NOT NULL,
  `phone` varchar(255) NOT NULL,
  `business_name` varchar(255) NOT NULL,
  `business_ein` varchar(255) NOT NULL,
  `interface` enum('','Web','iOS','Android') NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  `archived` tinyint(1) NOT NULL,
  `notes` text NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2345 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_pre_register_notes
-- ----------------------------
DROP TABLE IF EXISTS `data_pre_register_notes`;
CREATE TABLE `data_pre_register_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `notes` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_promo_codes
-- ----------------------------
DROP TABLE IF EXISTS `data_promo_codes`;
CREATE TABLE `data_promo_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(255) DEFAULT NULL,
  `discount` int(11) DEFAULT NULL,
  `active` int(11) DEFAULT 0,
  `used` int(11) DEFAULT NULL,
  `short_description` varchar(255) DEFAULT NULL,
  `deleted` int(11) DEFAULT 0,
  `created` datetime(6) DEFAULT NULL,
  `type` enum('PERCENTAGE','AMOUNT') DEFAULT 'PERCENTAGE',
  `user_id` int(11) DEFAULT 0,
  `category` enum('ALL','REGISTER','TRAINING','TREATMENT','GFE','PURCHASE','SUBSCRIPTIONMSL','SUBSCRIPTIONMD') DEFAULT 'ALL',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_purchases
-- ----------------------------
DROP TABLE IF EXISTS `data_purchases`;
CREATE TABLE `data_purchases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `address` varchar(255) NOT NULL,
  `suite` varchar(255) NOT NULL,
  `state` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `zip` int(11) NOT NULL,
  `status` enum('NEW','PURCHASED','SHIPPED','DELIVERED','CANCELED','PARTIALLY SHIPPED','PICKED UP BY SELF','PICKING UP AT CLASS','PICK UP AT SPA BY NATE','REFUNDED','ON HOLD','ATTENDING TO CLASS') NOT NULL,
  `tracking` varchar(255) NOT NULL,
  `tracking2` varchar(255) NOT NULL,
  `payment` varchar(255) CHARACTER SET utf8 NOT NULL,
  `payment_intent` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '',
  `use_credits` int(11) NOT NULL DEFAULT 0,
  `receipt_url` text CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `amount` int(11) NOT NULL DEFAULT 0,
  `delivery_company` varchar(255) NOT NULL,
  `delivery_company2` varchar(255) NOT NULL,
  `shipping_date` date NOT NULL,
  `shipping_cost` int(11) NOT NULL DEFAULT 0,
  `created` date NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `notified` int(11) NOT NULL DEFAULT 0,
  `notes` text NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `user_id` (`user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1174 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_purchases_copy1
-- ----------------------------
DROP TABLE IF EXISTS `data_purchases_copy1`;
CREATE TABLE `data_purchases_copy1` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `address` varchar(255) NOT NULL,
  `state` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `zip` int(11) NOT NULL,
  `status` enum('NEW','PURCHASED','SHIPPED','DELIVERED','CANCELED') NOT NULL,
  `tracking` varchar(255) NOT NULL,
  `payment` varchar(255) CHARACTER SET utf8 NOT NULL,
  `payment_intent` varchar(255) CHARACTER SET utf8 DEFAULT '',
  `receipt_url` text CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `amount` int(11) NOT NULL DEFAULT 0,
  `delivery_company` varchar(255) NOT NULL,
  `shipping_date` date NOT NULL,
  `created` date NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=170 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_purchases_detail
-- ----------------------------
DROP TABLE IF EXISTS `data_purchases_detail`;
CREATE TABLE `data_purchases_detail` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `price` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `shipped_qty` int(11) NOT NULL DEFAULT 0,
  `refunded` int(11) NOT NULL DEFAULT 0,
  `refunded_amount` int(11) NOT NULL,
  `product_number` varchar(100) NOT NULL,
  `serial_number` varchar(100) NOT NULL,
  `lot_number` varchar(100) NOT NULL,
  `expiration_date` date NOT NULL,
  `product_detail_question` varchar(255) NOT NULL,
  `product_detail` varchar(255) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=3760 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_reminders
-- ----------------------------
DROP TABLE IF EXISTS `data_reminders`;
CREATE TABLE `data_reminders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `title_reminders` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `receives` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `type_reminders` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `text_reminders` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `event` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `time_reminders` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `created` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `deleted` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_request_gfe_ci
-- ----------------------------
DROP TABLE IF EXISTS `data_request_gfe_ci`;
CREATE TABLE `data_request_gfe_ci` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `status` enum('INIT','READY','REJECTED') DEFAULT NULL,
  `created` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=383 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_request_users
-- ----------------------------
DROP TABLE IF EXISTS `data_request_users`;
CREATE TABLE `data_request_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(55) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `request` text COLLATE utf8_unicode_ci NOT NULL,
  `model` enum('BRAND','PARTNERSHIP') COLLATE utf8_unicode_ci DEFAULT NULL,
  `data` text COLLATE utf8_unicode_ci NOT NULL,
  `user_id` int(11) NOT NULL,
  `created` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `createdby` int(11) NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `modifieby` int(11) NOT NULL DEFAULT 0,
  `notes` text COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_sales_representative
-- ----------------------------
DROP TABLE IF EXISTS `data_sales_representative`;
CREATE TABLE `data_sales_representative` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `deleted` int(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_sales_representative_register
-- ----------------------------
DROP TABLE IF EXISTS `data_sales_representative_register`;
CREATE TABLE `data_sales_representative_register` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `representative_id` int(11) DEFAULT NULL,
  `deleted` int(1) DEFAULT 0,
  `created` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=215 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_schedule_appointments
-- ----------------------------
DROP TABLE IF EXISTS `data_schedule_appointments`;
CREATE TABLE `data_schedule_appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `treatment_id` int(10) unsigned DEFAULT NULL,
  `injector_id` int(10) unsigned DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `deleted` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1551 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_schedule_days_off
-- ----------------------------
DROP TABLE IF EXISTS `data_schedule_days_off`;
CREATE TABLE `data_schedule_days_off` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `date_off` date NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=642 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_schedule_model
-- ----------------------------
DROP TABLE IF EXISTS `data_schedule_model`;
CREATE TABLE `data_schedule_model` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `injector_id` int(11) DEFAULT NULL,
  `days` set('MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY') DEFAULT NULL,
  `time_start` int(11) DEFAULT NULL,
  `time_end` int(11) DEFAULT NULL,
  `model` enum('injector','examiner') NOT NULL DEFAULT 'injector',
  `deleted` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2313 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_school_register
-- ----------------------------
DROP TABLE IF EXISTS `data_school_register`;
CREATE TABLE `data_school_register` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `nameschool` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `schoolweb` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `rname` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `schoolphone` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `address` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `city` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `id_state` int(11) NOT NULL,
  `zip` int(5) NOT NULL,
  `certifications` text COLLATE utf8_unicode_ci NOT NULL,
  `additional_comments` text COLLATE utf8_unicode_ci NOT NULL,
  `deleted` int(11) DEFAULT 0,
  `status` enum('Active','Inactive') COLLATE utf8_unicode_ci DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_send_reminders
-- ----------------------------
DROP TABLE IF EXISTS `data_send_reminders`;
CREATE TABLE `data_send_reminders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_id` int(11) NOT NULL,
  `type` enum('RENEW_CERTIFICATE_LONG','RENEW_CERTIFICATE_SHORT','TREATMENT_STARTING_SOON','TREATMENT_STARTING_SOON_INJECTOR','MODEL_PATIENT_REMINDER') COLLATE utf8_unicode_ci NOT NULL,
  `form` enum('EMAIL','SMS','NOTIFICATION') COLLATE utf8_unicode_ci NOT NULL,
  `status` enum('DONE','PENDING','FAILURE','OUT OF TRIES') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'PENDING',
  `tries` int(11) DEFAULT 0,
  `contact` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime DEFAULT NULL,
  `last_try` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_subscription_cancelled
-- ----------------------------
DROP TABLE IF EXISTS `data_subscription_cancelled`;
CREATE TABLE `data_subscription_cancelled` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subscription_id` int(11) DEFAULT NULL,
  `date_cancelled` datetime DEFAULT NULL,
  `date_payment` datetime DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `deleted` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_subscription_method_payments
-- ----------------------------
DROP TABLE IF EXISTS `data_subscription_method_payments`;
CREATE TABLE `data_subscription_method_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `payment_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `preferred` int(1) DEFAULT NULL,
  `error` int(1) DEFAULT 0,
  `created` datetime DEFAULT NULL,
  `deleted` int(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=417 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_subscription_payments
-- ----------------------------
DROP TABLE IF EXISTS `data_subscription_payments`;
CREATE TABLE `data_subscription_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `subscription_id` int(11) DEFAULT NULL,
  `total` int(11) DEFAULT NULL,
  `payment_id` varchar(11) COLLATE utf8_unicode_ci DEFAULT NULL,
  `charge_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `receipt_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `error` text COLLATE utf8_unicode_ci DEFAULT NULL,
  `status` enum('DONE','PENDING','REFUNDED') COLLATE utf8_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8_unicode_ci DEFAULT NULL,
  `created` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=314 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_subscription_pending_payments
-- ----------------------------
DROP TABLE IF EXISTS `data_subscription_pending_payments`;
CREATE TABLE `data_subscription_pending_payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `uid` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `event` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `payload` text COLLATE utf8_unicode_ci DEFAULT NULL,
  `request_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `data_object_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `customer_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `payment_method` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `subscription_type` enum('SUBSCRIPTIONMSL','SUBSCRIPTIONMD') COLLATE utf8_unicode_ci DEFAULT NULL,
  `promo_code` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `subtotal` int(11) DEFAULT NULL,
  `total` int(11) DEFAULT NULL,
  `status` enum('PENDING','COMPLETED') COLLATE utf8_unicode_ci DEFAULT NULL,
  `effective_date` datetime DEFAULT NULL,
  `deleted` int(11) DEFAULT 0,
  `created` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_subscriptions
-- ----------------------------
DROP TABLE IF EXISTS `data_subscriptions`;
CREATE TABLE `data_subscriptions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `uid` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `event` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `payload` text COLLATE utf8_unicode_ci DEFAULT NULL,
  `request_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `data_object_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `customer_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `payment_method` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `subscription_type` enum('SUBSCRIPTIONMSL','SUBSCRIPTIONMD') COLLATE utf8_unicode_ci DEFAULT NULL,
  `promo_code` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `subtotal` int(11) DEFAULT NULL,
  `total` int(11) DEFAULT NULL,
  `status` enum('ACTIVE','CANCELLED','HOLD') COLLATE utf8_unicode_ci DEFAULT NULL,
  `deleted` int(11) DEFAULT 0,
  `created` datetime DEFAULT NULL,
  `agreement_id` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=580 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_trainers
-- ----------------------------
DROP TABLE IF EXISTS `data_trainers`;
CREATE TABLE `data_trainers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `injector_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `training_id` int(11) DEFAULT NULL,
  `status` enum('APPROVED','REJECT','PENDING') COLLATE utf8_unicode_ci DEFAULT NULL,
  `experience` varchar(500) COLLATE utf8_unicode_ci DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `deleted` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_trainers_image
-- ----------------------------
DROP TABLE IF EXISTS `data_trainers_image`;
CREATE TABLE `data_trainers_image` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) DEFAULT NULL,
  `trainer_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_training_model
-- ----------------------------
DROP TABLE IF EXISTS `data_training_model`;
CREATE TABLE `data_training_model` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `training_id` int(11) DEFAULT NULL,
  `model_id` int(11) DEFAULT NULL,
  `deleted` int(11) DEFAULT 0,
  `created` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_trainings
-- ----------------------------
DROP TABLE IF EXISTS `data_trainings`;
CREATE TABLE `data_trainings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `training_id` int(11) DEFAULT NULL,
  `deleted` int(11) DEFAULT 0,
  `attended` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `user_id` (`user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1320 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_trainings_notes
-- ----------------------------
DROP TABLE IF EXISTS `data_trainings_notes`;
CREATE TABLE `data_trainings_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `training_id` int(11) NOT NULL,
  `title` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `notes` longtext COLLATE utf8_unicode_ci NOT NULL,
  `serials` longtext COLLATE utf8_unicode_ci NOT NULL,
  `participants` longtext COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime(6) NOT NULL,
  `createdby` int(11) NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_treatment
-- ----------------------------
DROP TABLE IF EXISTS `data_treatment`;
CREATE TABLE `data_treatment` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `patient_id` bigint(20) NOT NULL DEFAULT 0,
  `assistance_id` bigint(20) NOT NULL,
  `clinic_patient_id` int(11) NOT NULL,
  `treatments` varchar(255) NOT NULL,
  `payment` varchar(255) NOT NULL,
  `payment_intent` varchar(255) NOT NULL DEFAULT '',
  `receipt_url` text CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `amount` int(11) NOT NULL DEFAULT 0,
  `address` text NOT NULL,
  `suite` varchar(255) NOT NULL,
  `zip` int(11) NOT NULL,
  `city` varchar(255) NOT NULL,
  `notes` text NOT NULL,
  `latitude` double NOT NULL DEFAULT 0,
  `longitude` double NOT NULL DEFAULT 0,
  `state` int(11) NOT NULL,
  `schedule_date` datetime(6) NOT NULL,
  `status` enum('INIT','DONE','CANCEL','CONFIRM','REJECT','TEST','PETITION','REQUEST','INVITATION') NOT NULL DEFAULT 'INIT',
  `approved` enum('APPROVED','REJECTED','PENDING') NOT NULL DEFAULT 'PENDING',
  `approved_date` datetime NOT NULL,
  `schedule_by` bigint(20) NOT NULL,
  `assigned_doctor` int(11) NOT NULL,
  `tip` int(11) NOT NULL DEFAULT 0,
  `request_payment` int(11) NOT NULL DEFAULT 0,
  `like` enum('NOTVALUED','LIKE','DISLIKE') NOT NULL DEFAULT 'NOTVALUED',
  `promo_code` varchar(255) DEFAULT NULL,
  `payment_method_patient` varchar(255) DEFAULT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime(6) NOT NULL,
  `modified` datetime(6) NOT NULL,
  `type_uber` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `assistance_id` (`assistance_id`) USING BTREE,
  KEY `patient_id` (`patient_id`) USING BTREE,
  KEY `assigned_doctor` (`assigned_doctor`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2127 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for data_treatment_detail
-- ----------------------------
DROP TABLE IF EXISTS `data_treatment_detail`;
CREATE TABLE `data_treatment_detail` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `treatment_id` bigint(20) NOT NULL,
  `quantity` int(11) NOT NULL,
  `cat_treatment_id` int(11) NOT NULL,
  `price` int(11) NOT NULL,
  `total` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2806 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_treatment_image
-- ----------------------------
DROP TABLE IF EXISTS `data_treatment_image`;
CREATE TABLE `data_treatment_image` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `treatment_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `treatment_id` (`treatment_id`),
  KEY `file_treat_id` (`treatment_id`,`file_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=4075 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_treatment_notes
-- ----------------------------
DROP TABLE IF EXISTS `data_treatment_notes`;
CREATE TABLE `data_treatment_notes` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `treatment_id` bigint(20) NOT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`,`treatment_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=717 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_treatment_reviews
-- ----------------------------
DROP TABLE IF EXISTS `data_treatment_reviews`;
CREATE TABLE `data_treatment_reviews` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `treatment_id` int(11) DEFAULT NULL,
  `injector_id` int(11) DEFAULT NULL,
  `score` int(11) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `like` enum('NOTVALUED','LIKE','DISLIKE') CHARACTER SET utf8 NOT NULL DEFAULT 'NOTVALUED',
  `deleted` int(11) DEFAULT 0,
  `createdby` bigint(20) DEFAULT NULL,
  `created` datetime(6) DEFAULT NULL,
  `half_review` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=501 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_treatment_survey
-- ----------------------------
DROP TABLE IF EXISTS `data_treatment_survey`;
CREATE TABLE `data_treatment_survey` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `treatment_uid` varbinary(255) NOT NULL,
  `injector_name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `pacient_name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `experience` enum('Excellent','Great','Neutral feelings about it','Satisfactory','Poor') COLLATE utf8_unicode_ci DEFAULT NULL,
  `injector_behave` enum('Yes','No') COLLATE utf8_unicode_ci DEFAULT NULL,
  `injector_explain` enum('Yes','No') COLLATE utf8_unicode_ci DEFAULT NULL,
  `company_future` enum('Yes','No') COLLATE utf8_unicode_ci DEFAULT NULL,
  `negative_answers` text COLLATE utf8_unicode_ci DEFAULT NULL,
  `done_improve` text COLLATE utf8_unicode_ci DEFAULT NULL,
  `injector_confident` enum('Yes','No') COLLATE utf8_unicode_ci DEFAULT NULL,
  `created` timestamp NULL DEFAULT NULL,
  `deleted` int(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_treatments_prices
-- ----------------------------
DROP TABLE IF EXISTS `data_treatments_prices`;
CREATE TABLE `data_treatments_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `treatment_id` int(11) NOT NULL,
  `price` int(10) unsigned NOT NULL,
  `deleted` int(11) DEFAULT 0,
  `created` datetime(6) NOT NULL,
  `createdby` bigint(20) NOT NULL,
  `modified` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2777 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_trtment_notes_doc
-- ----------------------------
DROP TABLE IF EXISTS `data_trtment_notes_doc`;
CREATE TABLE `data_trtment_notes_doc` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(60) NOT NULL,
  `notes` text NOT NULL,
  `treatment_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `created` datetime(6) NOT NULL,
  `modified` datetime(6) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for data_user_cpr_licence
-- ----------------------------
DROP TABLE IF EXISTS `data_user_cpr_licence`;
CREATE TABLE `data_user_cpr_licence` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=465 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_user_driver_licence
-- ----------------------------
DROP TABLE IF EXISTS `data_user_driver_licence`;
CREATE TABLE `data_user_driver_licence` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=257 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_user_icon
-- ----------------------------
DROP TABLE IF EXISTS `data_user_icon`;
CREATE TABLE `data_user_icon` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `icon_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `unique_icon` (`user_id`,`icon_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_user_unavailable
-- ----------------------------
DROP TABLE IF EXISTS `data_user_unavailable`;
CREATE TABLE `data_user_unavailable` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(65) COLLATE utf8_unicode_ci NOT NULL,
  `injector_id` int(11) NOT NULL,
  `day_unavailable` date NOT NULL,
  `time_unavailable` time NOT NULL,
  `treatment_id` int(11) NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1094 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_users_notes
-- ----------------------------
DROP TABLE IF EXISTS `data_users_notes`;
CREATE TABLE `data_users_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_users_training_notes
-- ----------------------------
DROP TABLE IF EXISTS `data_users_training_notes`;
CREATE TABLE `data_users_training_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `notes` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_validate_course
-- ----------------------------
DROP TABLE IF EXISTS `data_validate_course`;
CREATE TABLE `data_validate_course` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `data_course_id` int(10) unsigned NOT NULL,
  `key1` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `active` int(11) NOT NULL DEFAULT 0,
  `user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_validate_training
-- ----------------------------
DROP TABLE IF EXISTS `data_validate_training`;
CREATE TABLE `data_validate_training` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `training_id` int(11) DEFAULT NULL,
  `key1` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `active` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `status` enum('PENDING','ACCEPTED','REJECTED') COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for data_webhook
-- ----------------------------
DROP TABLE IF EXISTS `data_webhook`;
CREATE TABLE `data_webhook` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `event` varchar(255) NOT NULL,
  `model` varchar(255) NOT NULL,
  `model_uid` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  `metadata` longtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3456 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_wn
-- ----------------------------
DROP TABLE IF EXISTS `data_wn`;
CREATE TABLE `data_wn` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `bname` varchar(255) NOT NULL,
  `payee` varchar(255) NOT NULL,
  `fatca` varchar(255) NOT NULL,
  `cat` varchar(255) NOT NULL,
  `other` varchar(255) NOT NULL,
  `tax` varchar(2) NOT NULL,
  `address` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `account` varchar(255) NOT NULL,
  `requesters` tinytext NOT NULL,
  `ssn` varchar(255) NOT NULL,
  `ein` varchar(255) NOT NULL,
  `sign_id` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=688 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_wn_bak19112021
-- ----------------------------
DROP TABLE IF EXISTS `data_wn_bak19112021`;
CREATE TABLE `data_wn_bak19112021` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `bname` varchar(255) NOT NULL,
  `payee` varchar(255) NOT NULL,
  `fatca` varchar(255) NOT NULL,
  `cat` varchar(255) NOT NULL,
  `other` varchar(255) NOT NULL,
  `tax` varchar(2) NOT NULL,
  `address` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `account` varchar(255) NOT NULL,
  `requesters` tinytext NOT NULL,
  `ssn` varchar(255) NOT NULL,
  `ein` varchar(255) NOT NULL,
  `sign_id` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=184 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for data_wn_doc
-- ----------------------------
DROP TABLE IF EXISTS `data_wn_doc`;
CREATE TABLE `data_wn_doc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `bname` varchar(255) NOT NULL,
  `payee` varchar(255) NOT NULL,
  `fatca` varchar(255) NOT NULL,
  `cat` varchar(255) NOT NULL,
  `tax` varchar(2) NOT NULL,
  `address` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `account` varchar(255) NOT NULL,
  `requesters` tinytext NOT NULL,
  `ssn` varchar(255) NOT NULL,
  `ein` varchar(255) NOT NULL,
  `sign_id` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for sys_access_control
-- ----------------------------
DROP TABLE IF EXISTS `sys_access_control`;
CREATE TABLE `sys_access_control` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `desc` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_access_control_emails
-- ----------------------------
DROP TABLE IF EXISTS `sys_access_control_emails`;
CREATE TABLE `sys_access_control_emails` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `ip` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime(6) NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=118 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_access_resources
-- ----------------------------
DROP TABLE IF EXISTS `sys_access_resources`;
CREATE TABLE `sys_access_resources` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `alias` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `model_id` int(11) NOT NULL,
  `resource_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `unico` (`model_id`,`resource_id`,`user_id`,`group_id`) USING BTREE,
  KEY `model_id` (`model_id`) USING BTREE,
  KEY `resource_id` (`resource_id`) USING BTREE,
  KEY `user_id` (`user_id`) USING BTREE,
  KEY `group_id` (`group_id`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_actions
-- ----------------------------
DROP TABLE IF EXISTS `sys_actions`;
CREATE TABLE `sys_actions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `controller` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `action` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `response` enum('html','json','javascript') COLLATE utf8_unicode_ci NOT NULL,
  `permission_id` int(11) NOT NULL,
  `min_access_level` enum('Any','Reader','Contributed','Administrator','Owner') COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `unico` (`controller`,`action`,`permission_id`) USING BTREE,
  KEY `controller` (`controller`) USING BTREE,
  KEY `action` (`action`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2395 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_debug
-- ----------------------------
DROP TABLE IF EXISTS `sys_debug`;
CREATE TABLE `sys_debug` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `panel` enum('ADMIN','MD') COLLATE utf8_unicode_ci NOT NULL,
  `version` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `action` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `controller` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `post` text COLLATE utf8_unicode_ci NOT NULL,
  `post_input` text COLLATE utf8_unicode_ci NOT NULL,
  `get` text COLLATE utf8_unicode_ci NOT NULL,
  `ip` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `agent` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `application_id` (`panel`) USING BTREE,
  KEY `action` (`action`) USING BTREE,
  KEY `createdby` (`createdby`)
) ENGINE=InnoDB AUTO_INCREMENT=882776 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_files_tray
-- ----------------------------
DROP TABLE IF EXISTS `sys_files_tray`;
CREATE TABLE `sys_files_tray` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `status` enum('INIT','PROCESSING','FINISHED','ERROR') NOT NULL,
  `model` varchar(255) NOT NULL,
  `observation` text NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  `params` longtext NOT NULL,
  `mimetype` enum('PDF','XLSX') NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for sys_groups
-- ----------------------------
DROP TABLE IF EXISTS `sys_groups`;
CREATE TABLE `sys_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) CHARACTER SET utf8 NOT NULL,
  `name` varchar(100) CHARACTER SET utf8 NOT NULL,
  `description` varchar(255) CHARACTER SET utf8 NOT NULL,
  `active` int(11) NOT NULL,
  `organization_id` int(11) NOT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE,
  KEY `created` (`created`) USING BTREE,
  KEY `active` (`active`) USING BTREE,
  KEY `organization_id` (`organization_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_groups_permissions
-- ----------------------------
DROP TABLE IF EXISTS `sys_groups_permissions`;
CREATE TABLE `sys_groups_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `access_level` enum('Deny','Reader','Contributed','Administrator','Owner') COLLATE utf8_unicode_ci NOT NULL,
  `access_resources` enum('Assigned','Own','Both') COLLATE utf8_unicode_ci NOT NULL,
  `organization_id` int(11) NOT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `unico` (`permission_id`,`group_id`,`organization_id`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE,
  KEY `organization_id` (`organization_id`) USING BTREE,
  KEY `access_resources` (`access_resources`) USING BTREE,
  KEY `access_level` (`access_level`) USING BTREE,
  KEY `permission_id` (`permission_id`) USING BTREE,
  KEY `group_id` (`group_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_intent_recover
-- ----------------------------
DROP TABLE IF EXISTS `sys_intent_recover`;
CREATE TABLE `sys_intent_recover` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `key1` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `key2` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `active` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=211 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_licences
-- ----------------------------
DROP TABLE IF EXISTS `sys_licences`;
CREATE TABLE `sys_licences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `type` enum('DOCTOR','NURSE PRACTICIONER','MEDICAL DOCTOR','NP/PA','MD','NP','PA','RN','MA','CNS','Esthetician','Other') DEFAULT NULL,
  `number` varchar(255) DEFAULT NULL,
  `state` int(11) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `exp_date` date DEFAULT NULL,
  `front` int(11) DEFAULT NULL,
  `back` int(11) DEFAULT NULL,
  `deleted` int(11) DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=748 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for sys_licences_doc
-- ----------------------------
DROP TABLE IF EXISTS `sys_licences_doc`;
CREATE TABLE `sys_licences_doc` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `type` enum('DOCTOR','NURSE PRACTICIONER','MEDICAL DOCTOR','NP/PA','MD') DEFAULT NULL,
  `number` varchar(255) DEFAULT NULL,
  `state` int(11) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `exp_date` date DEFAULT NULL,
  `front` int(11) DEFAULT NULL,
  `back` int(11) DEFAULT NULL,
  `deleted` int(11) DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for sys_menus
-- ----------------------------
DROP TABLE IF EXISTS `sys_menus`;
CREATE TABLE `sys_menus` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `parent_id` int(10) unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8 NOT NULL,
  `element_name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8 NOT NULL,
  `order` int(11) NOT NULL DEFAULT 0,
  `icon` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '',
  `module_id` int(11) NOT NULL,
  `script` text CHARACTER SET utf8 NOT NULL,
  `active` int(11) NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `lft` int(11) NOT NULL,
  `rght` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE,
  KEY `parent_id` (`parent_id`) USING BTREE,
  KEY `lft` (`lft`) USING BTREE,
  KEY `rght` (`rght`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE,
  KEY `active` (`active`) USING BTREE,
  KEY `order` (`order`) USING BTREE,
  KEY `modulo_id` (`module_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_mimetypes
-- ----------------------------
DROP TABLE IF EXISTS `sys_mimetypes`;
CREATE TABLE `sys_mimetypes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mimetype` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `mimetype` (`mimetype`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_models
-- ----------------------------
DROP TABLE IF EXISTS `sys_models`;
CREATE TABLE `sys_models` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) CHARACTER SET utf8 NOT NULL,
  `name` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `model` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `table` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `active` int(11) NOT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `unico` (`uid`) USING BTREE,
  UNIQUE KEY `model` (`model`) USING BTREE,
  UNIQUE KEY `table` (`table`) USING BTREE,
  KEY `active` (`active`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE,
  KEY `name` (`name`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_modules
-- ----------------------------
DROP TABLE IF EXISTS `sys_modules`;
CREATE TABLE `sys_modules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) CHARACTER SET utf8 NOT NULL,
  `name` varchar(255) CHARACTER SET utf8 NOT NULL,
  `description` varchar(255) CHARACTER SET utf8 NOT NULL,
  `permission_id` int(11) NOT NULL,
  `url` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '',
  `file` varchar(255) CHARACTER SET utf8 NOT NULL,
  `controller` varchar(255) CHARACTER SET utf8 NOT NULL,
  `active` int(11) NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE,
  KEY `active` (`active`) USING BTREE,
  KEY `permiso_id` (`permission_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_permissions
-- ----------------------------
DROP TABLE IF EXISTS `sys_permissions`;
CREATE TABLE `sys_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) CHARACTER SET utf8 NOT NULL,
  `parent_id` int(10) unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8 NOT NULL,
  `description` varchar(255) CHARACTER SET utf8 NOT NULL,
  `order` int(11) NOT NULL,
  `active` int(10) unsigned NOT NULL,
  `deleted` int(11) NOT NULL,
  `lft` int(11) NOT NULL,
  `rght` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE,
  KEY `parent_id` (`parent_id`) USING BTREE,
  KEY `tree` (`lft`,`rght`) USING BTREE,
  KEY `active` (`active`) USING BTREE,
  KEY `orden` (`order`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE,
  KEY `lft` (`lft`) USING BTREE,
  KEY `rght` (`rght`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_reports
-- ----------------------------
DROP TABLE IF EXISTS `sys_reports`;
CREATE TABLE `sys_reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `report_from` date NOT NULL,
  `report_to` date NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Table structure for sys_sessions
-- ----------------------------
DROP TABLE IF EXISTS `sys_sessions`;
CREATE TABLE `sys_sessions` (
  `id` char(40) COLLATE utf8_unicode_ci NOT NULL,
  `data` text COLLATE utf8_unicode_ci DEFAULT NULL,
  `expires` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_users
-- ----------------------------
DROP TABLE IF EXISTS `sys_users`;
CREATE TABLE `sys_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `short_uid` varchar(10) COLLATE utf8_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `mname` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `lname` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `description` text COLLATE utf8_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `type` enum('patient','clinic','examiner','master','injector','gfe+ci') COLLATE utf8_unicode_ci NOT NULL,
  `state` int(11) NOT NULL,
  `zip` int(11) NOT NULL,
  `city` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `street` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `suite` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `phone` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `dob` date NOT NULL,
  `gender` enum('Female','Male','Other') COLLATE utf8_unicode_ci DEFAULT 'Other',
  `bname` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `ein` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `active` int(11) NOT NULL,
  `login_status` enum('READY','APPROVE','REJECT','W9','CHANGEPASSWORD','PAYMENT') COLLATE utf8_unicode_ci NOT NULL,
  `latitude` double NOT NULL DEFAULT 0,
  `longitude` double NOT NULL DEFAULT 0,
  `radius` int(11) NOT NULL,
  `score` int(11) NOT NULL,
  `photo_id` int(10) unsigned NOT NULL DEFAULT 0,
  `stripe_account_confirm` int(11) NOT NULL DEFAULT 0,
  `stripe_account` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `i_nine_id` int(10) unsigned NOT NULL DEFAULT 0,
  `ten_nintynine_id` int(10) unsigned NOT NULL DEFAULT 0,
  `amount` int(11) NOT NULL,
  `payment` varchar(255) CHARACTER SET utf8 NOT NULL,
  `payment_intent` varchar(255) CHARACTER SET utf8 NOT NULL,
  `receipt_url` text COLLATE utf8_unicode_ci NOT NULL,
  `tracers` text COLLATE utf8_unicode_ci NOT NULL,
  `tracers_sxo` text COLLATE utf8_unicode_ci NOT NULL,
  `is_test` int(11) NOT NULL DEFAULT 0,
  `enable_notifications` int(11) NOT NULL DEFAULT 1,
  `deleted` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  `show_in_map` int(11) NOT NULL DEFAULT 1,
  `show_most_review` enum('DEFAULT','FORCED','DENIED') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'DEFAULT',
  `last_status_change` datetime DEFAULT NULL,
  `custom_pay` int(11) NOT NULL DEFAULT 0,
  `md_id` int(11) NOT NULL DEFAULT 0,
  `steps` enum('CODEVERIFICATION','PAYMENTMETHOD','STATENOTAVAILABLE','HOWITWORKS','BASICCOURSE','TRACERS','SELECTREFERRED','ADVANCEDCOURSE','SELECTBASICCOURSE','SELECTADVANCEDCOURSE','CPR','TREATMENTSETTINGS','MSLSUBSCRIPTION','MDSUBSCRIPTION','W9','MATERIALS','HOME','WAITINGSCHOOLAPPROVAL','DENIED') COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE,
  KEY `active` (`active`) USING BTREE,
  KEY `rol_id` (`type`) USING BTREE,
  KEY `email` (`email`) USING BTREE,
  KEY `createdby` (`createdby`) USING BTREE,
  FULLTEXT KEY `full_name` (`name`,`mname`,`lname`)
) ENGINE=InnoDB AUTO_INCREMENT=6471 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_users_admin
-- ----------------------------
DROP TABLE IF EXISTS `sys_users_admin`;
CREATE TABLE `sys_users_admin` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(100) CHARACTER SET utf8 NOT NULL,
  `username` varchar(100) CHARACTER SET utf8 NOT NULL,
  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8 NOT NULL,
  `user_type` enum('MASTER','DOCTOR','PANEL') COLLATE utf8_unicode_ci NOT NULL,
  `active` int(11) NOT NULL,
  `last_login` datetime NOT NULL,
  `organization_id` int(11) NOT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE,
  UNIQUE KEY `correo` (`username`) USING BTREE,
  KEY `organization_id` (`organization_id`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=109 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_users_detail
-- ----------------------------
DROP TABLE IF EXISTS `sys_users_detail`;
CREATE TABLE `sys_users_detail` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `mname` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `lname` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `state` int(11) NOT NULL,
  `zip` int(11) NOT NULL,
  `city` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `street` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `phone` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `dob` date NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_users_groups
-- ----------------------------
DROP TABLE IF EXISTS `sys_users_groups`;
CREATE TABLE `sys_users_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `organization_id` int(11) NOT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `unico` (`user_id`,`group_id`) USING BTREE,
  KEY `user_id` (`user_id`) USING BTREE,
  KEY `group_id` (`group_id`) USING BTREE,
  KEY `organization_id` (`organization_id`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_users_permissions
-- ----------------------------
DROP TABLE IF EXISTS `sys_users_permissions`;
CREATE TABLE `sys_users_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `access_level` enum('Deny','Reader','Contributed','Administrator','Owner') COLLATE utf8_unicode_ci NOT NULL,
  `access_resources` enum('Assigned','Own','Both') COLLATE utf8_unicode_ci NOT NULL,
  `organization_id` int(11) NOT NULL,
  `deleted` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `unico` (`permission_id`,`user_id`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE,
  KEY `organization_id` (`organization_id`) USING BTREE,
  KEY `type` (`access_resources`) USING BTREE,
  KEY `access_level` (`access_level`) USING BTREE,
  KEY `permission_id` (`permission_id`) USING BTREE,
  KEY `user_id` (`user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_users_temp_passwords
-- ----------------------------
DROP TABLE IF EXISTS `sys_users_temp_passwords`;
CREATE TABLE `sys_users_temp_passwords` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) CHARACTER SET utf8 NOT NULL,
  `user_id` int(11) NOT NULL,
  `password` varchar(255) CHARACTER SET utf8 NOT NULL,
  `expires` int(11) NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE,
  KEY `user_id` (`user_id`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE,
  KEY `expires` (`expires`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for sys_zoom_tokens
-- ----------------------------
DROP TABLE IF EXISTS `sys_zoom_tokens`;
CREATE TABLE `sys_zoom_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token` text DEFAULT NULL,
  `refresh_token` text DEFAULT NULL,
  `expires_in` int(11) DEFAULT NULL,
  `created` datetime(6) DEFAULT NULL,
  `user` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2965 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for university_articles
-- ----------------------------
DROP TABLE IF EXISTS `university_articles`;
CREATE TABLE `university_articles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `content` text COLLATE utf8_unicode_ci NOT NULL,
  `deleted` int(11) NOT NULL DEFAULT 0,
  `created` datetime(6) NOT NULL,
  `createdby` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for university_cat_courses
-- ----------------------------
DROP TABLE IF EXISTS `university_cat_courses`;
CREATE TABLE `university_cat_courses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `course` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `type` enum('NEUROTOXINS','FILLERS') COLLATE utf8_unicode_ci DEFAULT NULL,
  `training_id` int(11) NOT NULL,
  `active` int(11) DEFAULT 1,
  `deleted` int(11) DEFAULT 0,
  `created` datetime(6) DEFAULT NULL,
  `createdby` int(11) DEFAULT NULL,
  `modified` datetime(6) DEFAULT NULL,
  `modifiedby` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for university_course_trainings
-- ----------------------------
DROP TABLE IF EXISTS `university_course_trainings`;
CREATE TABLE `university_course_trainings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) DEFAULT NULL,
  `training_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for university_files
-- ----------------------------
DROP TABLE IF EXISTS `university_files`;
CREATE TABLE `university_files` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) NOT NULL,
  `type` enum('MEDIA','COURSE') DEFAULT 'MEDIA',
  `title` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `size` double(11,0) NOT NULL,
  `path` varchar(255) NOT NULL,
  `_mimetype_id` int(11) NOT NULL,
  `secure` int(11) NOT NULL DEFAULT 0,
  `deleted` int(11) DEFAULT 0,
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE,
  KEY `mimetype_id` (`_mimetype_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for university_files_tags
-- ----------------------------
DROP TABLE IF EXISTS `university_files_tags`;
CREATE TABLE `university_files_tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Table structure for university_tags
-- ----------------------------
DROP TABLE IF EXISTS `university_tags`;
CREATE TABLE `university_tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tag` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
