/*
 Navicat Premium Data Transfer

 Source Server         : Spalive
 Source Server Type    : MariaDB
 Source Server Version : 100327
 Source Host           : 161.35.185.166:3306
 Source Schema         : db_spa

 Target Server Type    : MariaDB
 Target Server Version : 100327
 File Encoding         : 65001

 Date: 06/02/2021 01:31:37
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for _files
-- ----------------------------
DROP TABLE IF EXISTS `_files`;
CREATE TABLE `_files` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `size` double(11,0) NOT NULL,
  `path` varchar(6) NOT NULL,
  `_mimetype_id` int(11) NOT NULL,
  `_filedata_id` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`),
  KEY `mimetype_id` (`_mimetype_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT;

-- ----------------------------
-- Records of _files
-- ----------------------------
BEGIN;
COMMIT;

-- ----------------------------
-- Table structure for _files_data
-- ----------------------------
DROP TABLE IF EXISTS `_files_data`;
CREATE TABLE `_files_data` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `size` int(11) NOT NULL,
  `data` longblob NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci ROW_FORMAT=COMPACT;

-- ----------------------------
-- Records of _files_data
-- ----------------------------
BEGIN;
COMMIT;

-- ----------------------------
-- Table structure for _mimetypes
-- ----------------------------
DROP TABLE IF EXISTS `_mimetypes`;
CREATE TABLE `_mimetypes` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `mimetype` varchar(100) NOT NULL,
  `type` enum('other','image','js','css','font','xml','video','pdf','xls') NOT NULL,
  PRIMARY KEY (`id`),
  KEY `mimetype` (`mimetype`),
  KEY `ext` (`type`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT;

-- ----------------------------
-- Records of _mimetypes
-- ----------------------------
BEGIN;
INSERT INTO `_mimetypes` VALUES (18, 'text/plain', 'other');
INSERT INTO `_mimetypes` VALUES (29, 'image/jpeg', 'other');
INSERT INTO `_mimetypes` VALUES (43, 'image/png', 'other');
INSERT INTO `_mimetypes` VALUES (49, '', 'other');
INSERT INTO `_mimetypes` VALUES (50, 'application/pdf', 'other');
INSERT INTO `_mimetypes` VALUES (51, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'other');
COMMIT;

-- ----------------------------
-- Table structure for api_applications
-- ----------------------------
DROP TABLE IF EXISTS `api_applications`;
CREATE TABLE `api_applications` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) COLLATE utf8_unicode_ci NOT NULL,
  `appname` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `debug` int(1) NOT NULL DEFAULT 0,
  `json_config` varchar(1000) COLLATE utf8_unicode_ci NOT NULL,
  `deleted` int(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(1) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Records of api_applications
-- ----------------------------
BEGIN;
INSERT INTO `api_applications` VALUES (1, 'SpaLiveMD', 'SpaLive', 0, '{\"android_access_key\":\"\",\"notify_developer\":\"0\",\"ios_debug\":\"0\",\"ios_passphrase\":\"\"}', 0, '0000-00-00 00:00:00', 0);
COMMIT;

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
  `get` text COLLATE utf8_unicode_ci NOT NULL,
  `files` text COLLATE utf8_unicode_ci NOT NULL,
  `result` text COLLATE utf8_unicode_ci NOT NULL,
  `error` text COLLATE utf8_unicode_ci NOT NULL,
  `key_id` int(11) NOT NULL,
  `token` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `cache` int(1) NOT NULL,
  `ip` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `application_id` (`application_id`) USING BTREE,
  KEY `action` (`action`) USING BTREE,
  KEY `token` (`token`) USING BTREE,
  KEY `key_id` (`key_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Records of api_debug
-- ----------------------------
BEGIN;
COMMIT;

-- ----------------------------
-- Table structure for api_keys
-- ----------------------------
DROP TABLE IF EXISTS `api_keys`;
CREATE TABLE `api_keys` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `key` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `type` enum('ANDROID','IOS','SERVER') COLLATE utf8_unicode_ci NOT NULL,
  `descripcion` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `active` int(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `key` (`key`) USING BTREE,
  KEY `active` (`active`) USING BTREE,
  KEY `application_id` (`application_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Records of api_keys
-- ----------------------------
BEGIN;
INSERT INTO `api_keys` VALUES (1, 1, '2fe548d5ae881ccfbe2be3f6237d7951', 'IOS', '', 1, '0000-00-00 00:00:00', 0);
INSERT INTO `api_keys` VALUES (2, 1, '2fe548d5ae881ccfbe2be3f6237d7952', 'ANDROID', '', 1, '0000-00-00 00:00:00', 0);
COMMIT;

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
  PRIMARY KEY (`id`),
  KEY `state_id` (`state_id`),
  FULLTEXT KEY `full_name` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ----------------------------
-- Records of app_patient
-- ----------------------------
BEGIN;
COMMIT;

-- ----------------------------
-- Table structure for app_tokens
-- ----------------------------
DROP TABLE IF EXISTS `app_tokens`;
CREATE TABLE `app_tokens` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_role` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '0',
  `token` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `deleted` int(1) NOT NULL DEFAULT 0,
  `created` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unico` (`token`),
  KEY `deleted` (`deleted`)
) ENGINE=InnoDB AUTO_INCREMENT=145 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci ROW_FORMAT=COMPACT;

-- ----------------------------
-- Records of app_tokens
-- ----------------------------
BEGIN;
INSERT INTO `app_tokens` VALUES (1, 68, '0', '5ffc82d832ad85.75980476', 0, NULL);
INSERT INTO `app_tokens` VALUES (2, 68, '0', '5ffc82f351cd88.84809009', 0, NULL);
INSERT INTO `app_tokens` VALUES (3, 68, '0', '5ffc82fac00c23.49843248', 0, NULL);
INSERT INTO `app_tokens` VALUES (4, 68, '0', '5ffccbe7e36455.05480156', 0, NULL);
INSERT INTO `app_tokens` VALUES (5, 69, '0', '5ffcd1f98a2246.24218440', 0, NULL);
INSERT INTO `app_tokens` VALUES (6, 70, '0', '5ffcd297396689.67678102', 0, NULL);
INSERT INTO `app_tokens` VALUES (7, 71, '0', '5ffcd72ca188e8.90824524', 0, NULL);
INSERT INTO `app_tokens` VALUES (8, 74, '0', '5ffcda288dfa85.63091582', 0, NULL);
INSERT INTO `app_tokens` VALUES (9, 69, 'patient', '6007f841386b53.88803830', 0, NULL);
INSERT INTO `app_tokens` VALUES (10, 69, 'patient', '6007f85cc50460.58888690', 0, NULL);
INSERT INTO `app_tokens` VALUES (11, 69, 'patient', '6007f885cf5872.80844804', 0, NULL);
INSERT INTO `app_tokens` VALUES (12, 69, 'patient', '6007fbb1bb9f90.36367099', 0, NULL);
INSERT INTO `app_tokens` VALUES (13, 69, 'patient', '6008012a03ebb5.46305682', 0, NULL);
INSERT INTO `app_tokens` VALUES (14, 69, 'patient', '600801b1cedd17.56629266', 0, NULL);
INSERT INTO `app_tokens` VALUES (15, 69, 'patient', '600801c12573e8.31827022', 0, NULL);
INSERT INTO `app_tokens` VALUES (16, 69, 'patient', '600801d598b773.80772374', 0, NULL);
INSERT INTO `app_tokens` VALUES (17, 69, 'patient', '600801fc6654d2.73224032', 0, NULL);
INSERT INTO `app_tokens` VALUES (18, 69, 'patient', '600802080e7877.74952727', 0, NULL);
INSERT INTO `app_tokens` VALUES (19, 69, 'patient', '6008022dba5a66.93148430', 0, NULL);
INSERT INTO `app_tokens` VALUES (20, 69, 'patient', '600802d8060bd8.40616116', 0, NULL);
INSERT INTO `app_tokens` VALUES (21, 69, 'patient', '600803030bc479.88385057', 0, NULL);
INSERT INTO `app_tokens` VALUES (22, 69, 'patient', '600e6971c63061.54139852', 0, NULL);
INSERT INTO `app_tokens` VALUES (23, 78, 'patient', '600e7be9d71130.42254532', 0, NULL);
INSERT INTO `app_tokens` VALUES (24, 79, 'patient', '600e7e0e80a828.55266761', 0, NULL);
INSERT INTO `app_tokens` VALUES (25, 80, 'patient', '600e7eacaedae5.91556082', 0, NULL);
INSERT INTO `app_tokens` VALUES (26, 80, 'patient', '600e7f88359c10.51545136', 0, NULL);
INSERT INTO `app_tokens` VALUES (27, 81, 'patient', '600e866a102b80.92423340', 0, NULL);
INSERT INTO `app_tokens` VALUES (28, 82, 'patient', '600e876a9171f3.92151617', 0, NULL);
INSERT INTO `app_tokens` VALUES (29, 83, 'patient', '600e9c37da45c6.23866570', 0, NULL);
INSERT INTO `app_tokens` VALUES (30, 84, 'patient', '600e9ce99668d1.38810721', 0, NULL);
INSERT INTO `app_tokens` VALUES (31, 85, 'patient', '600e9d05300489.58778282', 0, NULL);
INSERT INTO `app_tokens` VALUES (32, 86, 'patient', '600e9d1a9369c1.50752040', 0, NULL);
INSERT INTO `app_tokens` VALUES (33, 87, 'patient', '600e9d394d17d0.80724465', 0, NULL);
INSERT INTO `app_tokens` VALUES (34, 88, 'patient', '600e9da88faeb4.17479942', 0, NULL);
INSERT INTO `app_tokens` VALUES (35, 89, 'patient', '600e9e274cb8e9.67887180', 0, NULL);
INSERT INTO `app_tokens` VALUES (36, 90, 'patient', '600e9fbee70d44.84733065', 0, NULL);
INSERT INTO `app_tokens` VALUES (37, 91, 'patient', '600e9fe15581d9.16246168', 0, NULL);
INSERT INTO `app_tokens` VALUES (38, 92, 'patient', '600ea01b8db0b2.68078131', 0, NULL);
INSERT INTO `app_tokens` VALUES (39, 93, 'patient', '600ea091948a39.02326135', 0, NULL);
INSERT INTO `app_tokens` VALUES (40, 94, 'patient', '600ea0a8dfadb5.11449442', 0, NULL);
INSERT INTO `app_tokens` VALUES (41, 95, 'patient', '600ea0d45f2524.36614298', 0, NULL);
INSERT INTO `app_tokens` VALUES (42, 96, 'patient', '600ea1ee2ad702.12109917', 0, NULL);
INSERT INTO `app_tokens` VALUES (43, 97, 'patient', '600ea27505f510.85253255', 0, NULL);
INSERT INTO `app_tokens` VALUES (44, 98, 'patient', '600ea32f4e0549.29893734', 0, NULL);
INSERT INTO `app_tokens` VALUES (45, 99, 'patient', '600ea34c223cf2.69718942', 0, NULL);
INSERT INTO `app_tokens` VALUES (46, 100, 'patient', '600ea356edcf91.60337891', 0, NULL);
INSERT INTO `app_tokens` VALUES (47, 101, 'patient', '600ea3bda410b1.60620765', 0, NULL);
INSERT INTO `app_tokens` VALUES (48, 102, 'patient', '600ea49b4bb3b7.94267887', 0, NULL);
INSERT INTO `app_tokens` VALUES (49, 103, 'patient', '600ea51e970e43.82171839', 0, NULL);
INSERT INTO `app_tokens` VALUES (50, 104, 'patient', '600ea5350d9795.83221535', 0, NULL);
INSERT INTO `app_tokens` VALUES (51, 105, 'patient', '600ea547450327.25953568', 0, NULL);
INSERT INTO `app_tokens` VALUES (52, 106, 'patient', '600ea59e3260c9.87630624', 0, NULL);
INSERT INTO `app_tokens` VALUES (53, 107, 'patient', '600ea5c8e27f06.22538584', 0, NULL);
INSERT INTO `app_tokens` VALUES (54, 108, 'patient', '600ea5dcd4b0d9.34245827', 0, NULL);
INSERT INTO `app_tokens` VALUES (55, 109, 'patient', '600ea5ea43eb63.21063270', 0, NULL);
INSERT INTO `app_tokens` VALUES (56, 110, 'patient', '600ea5f5197f48.93269198', 0, NULL);
INSERT INTO `app_tokens` VALUES (57, 111, 'patient', '600ea61ec92517.18052559', 0, NULL);
INSERT INTO `app_tokens` VALUES (58, 112, 'patient', '600ea64f0aa3a5.82220993', 0, NULL);
INSERT INTO `app_tokens` VALUES (59, 113, 'patient', '600ea6a9411e78.88692297', 0, NULL);
INSERT INTO `app_tokens` VALUES (60, 114, 'patient', '600ea7223f1719.53299790', 0, NULL);
INSERT INTO `app_tokens` VALUES (61, 115, 'patient', '600ea72bd7a0d9.89677178', 0, NULL);
INSERT INTO `app_tokens` VALUES (62, 116, 'patient', '600ea742a6a0f9.37966923', 0, NULL);
INSERT INTO `app_tokens` VALUES (63, 117, 'patient', '600ea75ab28186.63436199', 0, NULL);
INSERT INTO `app_tokens` VALUES (64, 118, 'patient', '600ea884e5dd80.01744250', 0, NULL);
INSERT INTO `app_tokens` VALUES (65, 119, 'patient', '600ea8c80c0494.19228176', 0, NULL);
INSERT INTO `app_tokens` VALUES (66, 120, 'patient', '600ea8e97686a9.79363785', 0, NULL);
INSERT INTO `app_tokens` VALUES (67, 121, 'patient', '600ea8f76daa82.89942797', 0, NULL);
INSERT INTO `app_tokens` VALUES (68, 122, 'patient', '600ea9131c57a7.45321171', 0, NULL);
INSERT INTO `app_tokens` VALUES (69, 123, 'patient', '600ea93023a881.39478396', 0, NULL);
INSERT INTO `app_tokens` VALUES (70, 124, 'patient', '600ea95485a4d3.55261167', 0, NULL);
INSERT INTO `app_tokens` VALUES (71, 125, 'patient', '600ea99f67ebe5.12028306', 0, NULL);
INSERT INTO `app_tokens` VALUES (72, 69, 'patient', '600eaa89110919.67384384', 0, NULL);
INSERT INTO `app_tokens` VALUES (73, 69, 'patient', '600eab085a3c95.57377967', 0, NULL);
INSERT INTO `app_tokens` VALUES (74, 69, 'patient', '600eaba0b20e19.76304815', 0, NULL);
INSERT INTO `app_tokens` VALUES (75, 69, 'patient', '600eabe5541bc1.23256313', 0, NULL);
INSERT INTO `app_tokens` VALUES (76, 69, 'patient', '600eac116945d5.02197839', 0, NULL);
INSERT INTO `app_tokens` VALUES (77, 69, 'patient', '600eac56c821a0.57312663', 0, NULL);
INSERT INTO `app_tokens` VALUES (78, 69, 'patient', '600eacb8b6b369.95489736', 0, NULL);
INSERT INTO `app_tokens` VALUES (79, 69, 'patient', '600ead038e6a91.40538728', 0, NULL);
INSERT INTO `app_tokens` VALUES (80, 69, 'patient', '600ead49540120.17691695', 0, NULL);
INSERT INTO `app_tokens` VALUES (81, 69, 'patient', '600eadd5834e42.75422136', 0, NULL);
INSERT INTO `app_tokens` VALUES (82, 69, 'patient', '600eae09b18b39.49628868', 0, NULL);
INSERT INTO `app_tokens` VALUES (83, 69, 'patient', '600eae30cf1f34.41965464', 0, NULL);
INSERT INTO `app_tokens` VALUES (84, 69, 'patient', '600eae8b0e80b5.67100103', 0, NULL);
INSERT INTO `app_tokens` VALUES (85, 69, 'patient', '600eaf1ba463b9.90592216', 0, NULL);
INSERT INTO `app_tokens` VALUES (86, 69, 'patient', '600eaf8dc02fd4.44599208', 0, NULL);
INSERT INTO `app_tokens` VALUES (87, 69, 'patient', '600eafc4978cb0.67264933', 0, NULL);
INSERT INTO `app_tokens` VALUES (88, 69, 'patient', '600eaff3c73921.07003160', 0, NULL);
INSERT INTO `app_tokens` VALUES (89, 69, 'patient', '600eb00954f2b1.60791512', 0, NULL);
INSERT INTO `app_tokens` VALUES (90, 69, 'patient', '600eb01c7de866.45175253', 0, NULL);
INSERT INTO `app_tokens` VALUES (91, 69, 'patient', '600eb069447bd9.34778341', 0, NULL);
INSERT INTO `app_tokens` VALUES (92, 69, 'patient', '600eb0b1d9bae1.47514482', 0, NULL);
INSERT INTO `app_tokens` VALUES (93, 69, 'patient', '600eb10217e606.32972149', 0, NULL);
INSERT INTO `app_tokens` VALUES (94, 69, 'patient', '600eb158b84840.26134700', 0, NULL);
INSERT INTO `app_tokens` VALUES (95, 69, 'patient', '600eb19a3a1ff4.17459362', 0, NULL);
INSERT INTO `app_tokens` VALUES (96, 69, 'patient', '600eb1f1b77b00.43918749', 0, NULL);
INSERT INTO `app_tokens` VALUES (97, 69, 'patient', '600eb20e7367e1.02808789', 0, NULL);
INSERT INTO `app_tokens` VALUES (98, 69, 'patient', '600eb241445f80.14969452', 0, NULL);
INSERT INTO `app_tokens` VALUES (99, 69, 'patient', '600eb285d8ba75.25150291', 0, NULL);
INSERT INTO `app_tokens` VALUES (100, 69, 'patient', '600eb2ce7076b9.74810383', 0, NULL);
INSERT INTO `app_tokens` VALUES (101, 69, 'patient', '600eb309ebcd10.65230523', 0, NULL);
INSERT INTO `app_tokens` VALUES (102, 69, 'patient', '600eb34788b089.39475930', 0, NULL);
INSERT INTO `app_tokens` VALUES (103, 69, 'patient', '600eb375a8d878.04479203', 0, NULL);
INSERT INTO `app_tokens` VALUES (104, 69, 'patient', '600eb3918ec981.32833304', 0, NULL);
INSERT INTO `app_tokens` VALUES (105, 69, 'patient', '600eb3dee087c7.21438869', 0, NULL);
INSERT INTO `app_tokens` VALUES (106, 69, 'patient', '600eb4b01dfdf7.03613446', 0, NULL);
INSERT INTO `app_tokens` VALUES (107, 69, 'patient', '600eb4cf3ee615.89043131', 0, NULL);
INSERT INTO `app_tokens` VALUES (108, 69, 'patient', '600eb58dcfe747.46248960', 0, NULL);
INSERT INTO `app_tokens` VALUES (109, 69, 'patient', '600eb700d30470.54057034', 0, NULL);
INSERT INTO `app_tokens` VALUES (110, 69, 'patient', '600eb74dc76de9.33078445', 0, NULL);
INSERT INTO `app_tokens` VALUES (111, 69, 'patient', '600eb7970415b6.08498361', 0, NULL);
INSERT INTO `app_tokens` VALUES (112, 69, 'patient', '600eb7f4812b35.25406119', 0, NULL);
INSERT INTO `app_tokens` VALUES (113, 69, 'patient', '600eb844a11f12.62337079', 0, NULL);
INSERT INTO `app_tokens` VALUES (114, 69, 'patient', '600eb8604b12b1.13141419', 0, NULL);
INSERT INTO `app_tokens` VALUES (115, 69, 'patient', '600eb87522bce1.43391053', 0, NULL);
INSERT INTO `app_tokens` VALUES (116, 69, 'patient', '600eb8be4746d0.01327848', 0, NULL);
INSERT INTO `app_tokens` VALUES (117, 69, 'patient', '600eb98fb3d7f6.45466316', 0, NULL);
INSERT INTO `app_tokens` VALUES (118, 69, 'patient', '600eb9bf6509f4.00158304', 0, NULL);
INSERT INTO `app_tokens` VALUES (119, 69, 'patient', '600ebd5f47fe85.84919240', 0, NULL);
INSERT INTO `app_tokens` VALUES (120, 69, 'patient', '600ebda706c6d9.43839152', 0, NULL);
INSERT INTO `app_tokens` VALUES (121, 126, 'patient', '600ec27b0eccf1.55914312', 0, NULL);
INSERT INTO `app_tokens` VALUES (122, 127, 'patient', '600ec3c6dbe8f2.44583134', 0, NULL);
INSERT INTO `app_tokens` VALUES (123, 128, 'patient', '600ec4d0ea92f5.55947167', 0, NULL);
INSERT INTO `app_tokens` VALUES (124, 129, 'patient', '600ecaf3f2f028.34921799', 0, NULL);
INSERT INTO `app_tokens` VALUES (125, 69, 'patient', '600eccfb9a7468.32389592', 0, NULL);
INSERT INTO `app_tokens` VALUES (126, 69, 'patient', '600ecd739c4fc2.85839519', 0, NULL);
INSERT INTO `app_tokens` VALUES (127, 69, 'patient', '600ecdf313eb08.07525479', 0, NULL);
INSERT INTO `app_tokens` VALUES (128, 69, 'patient', '600ece1695b8a3.77893625', 0, NULL);
INSERT INTO `app_tokens` VALUES (129, 69, 'patient', '600ed593eb2215.30434788', 0, NULL);
INSERT INTO `app_tokens` VALUES (130, 69, 'patient', '600ed60e5c3004.13518385', 0, NULL);
INSERT INTO `app_tokens` VALUES (131, 69, 'patient', '600ed633e03859.37954634', 0, NULL);
INSERT INTO `app_tokens` VALUES (132, 69, 'patient', '600ed665c982a4.19638911', 0, NULL);
INSERT INTO `app_tokens` VALUES (133, 69, 'patient', '600ed68e702943.88077566', 0, NULL);
INSERT INTO `app_tokens` VALUES (134, 69, 'patient', '600ed6ab83b301.68075708', 0, NULL);
INSERT INTO `app_tokens` VALUES (135, 69, 'patient', '600ed757222fa1.39720074', 0, NULL);
INSERT INTO `app_tokens` VALUES (136, 69, 'patient', '600ed777cbce19.17501992', 0, NULL);
INSERT INTO `app_tokens` VALUES (137, 69, 'patient', '600ed7bf7717d3.17357416', 0, NULL);
INSERT INTO `app_tokens` VALUES (138, 69, 'patient', '600efa7f42c870.68368534', 0, NULL);
INSERT INTO `app_tokens` VALUES (139, 130, 'patient', '600efacb9747e8.65993877', 0, NULL);
INSERT INTO `app_tokens` VALUES (140, 69, 'patient', '600efb462b4ef6.65495789', 0, NULL);
INSERT INTO `app_tokens` VALUES (141, 69, 'patient', '600efb60b077f6.68602142', 0, NULL);
INSERT INTO `app_tokens` VALUES (142, 69, 'patient', '600efb91e985d5.70529706', 0, NULL);
INSERT INTO `app_tokens` VALUES (143, 131, 'patient', '600f09636cb0d2.39378268', 0, NULL);
INSERT INTO `app_tokens` VALUES (144, 132, 'patient', '600f09ae27bb66.35647670', 0, NULL);
COMMIT;

-- ----------------------------
-- Table structure for cat_questions
-- ----------------------------
DROP TABLE IF EXISTS `cat_questions`;
CREATE TABLE `cat_questions` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `deleted` int(1) unsigned DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Records of cat_questions
-- ----------------------------
BEGIN;
INSERT INTO `cat_questions` VALUES (1, 'Are you pregnant or Breastfeeding or trying to become pregnant?', 0);
INSERT INTO `cat_questions` VALUES (2, 'Are you taking any antibiotics or have any current or active infections, or past infections within the last 30 days?', 0);
INSERT INTO `cat_questions` VALUES (3, 'Have you or are you currently taking Accutane (Isotretinoin)? Less than 3 Months?', 0);
INSERT INTO `cat_questions` VALUES (4, 'Do you have any allergies or sensitivity to albumin, sulfa, calcium, or vitamin c?', 0);
INSERT INTO `cat_questions` VALUES (5, 'Have you ever had a reaction to any past botox, Dysport, Xeomin, Jeuveau or Filler treatment?', 0);
INSERT INTO `cat_questions` VALUES (6, 'Do you suffer from any neurological disorders?', 0);
INSERT INTO `cat_questions` VALUES (7, 'Do you have any light sensitivity concerns or history of seizures?', 0);
INSERT INTO `cat_questions` VALUES (8, 'Do you have a clotting disorder or take any blood thinners?', 0);
INSERT INTO `cat_questions` VALUES (9, 'Are you allergic to lidocaine, or any of the “Cain” Drugs?', 0);
INSERT INTO `cat_questions` VALUES (10, 'Do you have any facial implants or rods/pins/ screws in your body we should be aware of? ', 0);
INSERT INTO `cat_questions` VALUES (11, 'Any important medical history that is cause of concern related to your treatments you are seeking?', 0);
INSERT INTO `cat_questions` VALUES (12, 'Do you suffer from any nerve injuries or weakness in face or extremities?', 0);
INSERT INTO `cat_questions` VALUES (13, 'Are you under the care of a Dermatologist or actively taking any Retin-A products or prescribed lightening cream?', 0);
INSERT INTO `cat_questions` VALUES (14, 'Have you had any history of Herpes Type 1 or Type 2?', 0);
INSERT INTO `cat_questions` VALUES (15, 'Do you have any keloid troubles, or any delayed healing concerns?', 0);
COMMIT;

-- ----------------------------
-- Table structure for cat_states
-- ----------------------------
DROP TABLE IF EXISTS `cat_states`;
CREATE TABLE `cat_states` (
  `id` int(11) NOT NULL,
  `uid` varchar(55) NOT NULL,
  `name` varchar(50) NOT NULL,
  `postal_abv` varchar(4) NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifieby` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ----------------------------
-- Records of cat_states
-- ----------------------------
BEGIN;
INSERT INTO `cat_states` VALUES (1, 'ee03296-696a-4187-8ec8-ba091b93146f', 'Alabama', 'AL', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (2, 'ee03296-696a-4187-8ec8-ba091b93147f', 'Alaska', 'AK', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (3, 'ee03296-696a-4187-8ec8-ba091b93148f', 'Arizona', 'AZ', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (4, '98329ac-5226-4391-bac3-c435d455c72c', 'Arkansas', 'AR', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (5, '03ed7b4-f166-48cb-8be9-2b1184ad82ge', 'California', 'CA', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (6, '1a49e6b-601b-41fd-9d5c-0db4ec734gf6', 'Colorado', 'CO', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (7, '93fd92d-81f5-4dc6-acee-9445f962gs26', 'Connecticut', 'CT', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (8, 'c655b9b-1ae2-4f59-9aba-4e3cb45g24g7', 'Delaware', 'DE', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (9, 'cf722e0-d6d6-48f6-961d-0066bt234557', 'Florida', 'FL', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (10, 'b3c84e8-5ce5-4665-bd96-c0af0lk2342f', 'Georgia', 'GA', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (11, 'b66ebfb-4b0b-4527-aa2d-699f545asdfk6e', 'Hawaii', 'HI', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (12, '28d0b57f-f1ee-47ab-9cb6-06b98e234440', 'Idaho', 'ID', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (13, '62045d75-d7af-40bc-b560-c1aaccewrrc4', 'Illinois', 'IL', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (14, 'de331e4f-ebd4-4db1-8101-a6e45g4g5bc6e', 'Indiana', 'IN', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (15, '717b9d9f-10b0-49b5-b0f5-99a34mi22feed', 'Iowa', 'IA', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (16, '60adc0b0-3a35-4690-bd21-f3f1d234234ab', 'Kansas', 'KS', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (17, 'b9c82f93-342e-40b4-b655-3b837d323e2d3', 'Kentucky', 'KY', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (18, '64755b76-9ed4-43be-a36d-c34rw9fwfw22', 'Louisiana', 'LA', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (19, 'ed744160-fee1-4f21-9f8a-9828823ekjk2', 'Maine', 'ME', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (20, '4d6c0a12-af86-412a-a4b9-441ojo234oo1', 'Maryland', 'MD', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (21, 'f09a06f1-8a59-4329-b042-099qwekjkj99', 'Massachusetts', 'MA', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (22, 'e05ff0b8-899c-4bc2-99bf-9293h23ihijj', 'Michigan', 'MI', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (23, 'fc601c12-eb58-45ec-af8c-9u1293139ja9', 'Minnesota', 'MN', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (24, 'f09a06f1-8a59-4329-b542-23je2iej23e', 'Mississippi', 'MS', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (25, 'f09a06f1-8a59-4329-b442-9u239eu9jw', 'Missouri', 'MO', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (26, 'f09a06f1-8a59-4329-b342-92u93eu92i', 'Montana', 'MT', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (27, 'ee03296-696a-4187-8e18-0k0djhhg9qj', 'Nebraska', 'NE', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (28, '98329ac-5226-4391-ba13-9qjejd291jd92', 'Nevada', 'NV', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (29, '03ed7b4-f166-48cb-8b49-9jvcisnidj01', 'New Hampshire', 'NH', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (30, '1a49e6b-601b-41fd-9d3c-029jeajskdja', 'New Jersey', 'NJ', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (31, '93fd92d-81f5-4dc6-ac3e-92392jd9jdjm', 'New Mexico', 'NM', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (32, 'c655b9b-1ae2-4f59-9afa-nvuabd2929h9q', 'New York', 'NY', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (33, 'cf722e0-d6d6-48f6-96ad-006622675627', 'North Carolina', 'NC', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (34, 'b3c84e8-5ce5-4665-bdv6-c0af0ff8e82f', 'North Dakota', 'ND', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (35, 'b66ebfb-4b0b-4527-aahd-699f545a456e', 'Ohio', 'OH', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (36, '28d0b57f-f1ee-47ab-94b6-06b98e5b9be0', 'Oklahoma', 'OK', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (37, '62045d75-d7af-40bc-bf60-c1aaccde00c4', 'Oregon', 'OR', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (38, 'de331e4f-ebd4-4db1-8g01-a6eef7c4bc6e', 'Pennsylvania[E', 'PA', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (39, '717b9d9f-10b0-49b5-brf5-99a23f23eeed', 'Rhode Island', 'RI', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (40, '60adc0b0-3a35-4690-b821-f3f1d59ce7ab', 'South Carolina', 'SC', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (41, 'b9c82f93-342e-40b4-bk55-3b837fa814a3', 'South Dakota', 'SD', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (42, '64755b76-9ed4-43be-aj6d-cf9eqwed316d2', 'Tennessee', 'TN', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (43, 'ed744160-fee1-4f21-9n8a-dcff5g55gg81d', 'Texas', 'TX', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (44, '4d6c0a12-af86-412a-amb9-11d57ada55eef', 'Utah', 'UT', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (45, 'f09a06f1-8a59-4329-bs52-f76e8asd2a82', 'Vermont', 'VT', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (46, 'e05ff0b8-899c-4bc2-9xbf-917c3czxa260', 'Virginia', 'VA', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (47, 'fc601c12-eb58-45ec-az3c-8aa069ceccc92', 'Washington', 'WA', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (48, 'f09a06f1-8a59-4329-bp72-f76e87g5tga99', 'West Virginia', 'WV', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (49, 'f09a06f1-8a59-4329-bl62-f76e8nnrbrbs100', 'Wisconsin', 'WI', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
INSERT INTO `cat_states` VALUES (50, 'f09a06f1-8a59-4329-bw52-f76e87fefr4551', 'Wyoming', 'WY', 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 0);
COMMIT;

-- ----------------------------
-- Table structure for cat_treatments
-- ----------------------------
DROP TABLE IF EXISTS `cat_treatments`;
CREATE TABLE `cat_treatments` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `details` int(1) DEFAULT 0,
  `haschild` int(1) unsigned DEFAULT 0,
  `deleted` int(1) unsigned DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=82 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Records of cat_treatments
-- ----------------------------
BEGIN;
INSERT INTO `cat_treatments` VALUES (1, 0, 'Botox', 0, 1, 0);
INSERT INTO `cat_treatments` VALUES (2, 1, 'Botox', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (3, 1, 'Dysport', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (4, 1, 'Xeomin', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (5, 1, 'Jeuveau', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (6, 1, 'General Neuromodulators\n', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (7, 0, 'Filler', 0, 1, 0);
INSERT INTO `cat_treatments` VALUES (8, 7, 'Juvederm Fillers', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (9, 7, 'Restylane Fillers', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (10, 7, 'Versa Fillers', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (11, 7, 'Revanesse Fillers', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (12, 7, 'Belotero', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (13, 7, 'Radiesse Fillers', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (14, 7, 'General Hyaluronic Acid Fillers', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (15, 7, 'Hylenex (Hyaluronidase)', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (16, 7, 'Hyaluronidase', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (17, 0, 'Kybella - Sculptra', 0, 1, 0);
INSERT INTO `cat_treatments` VALUES (18, 17, 'Kybella (Deoxycholic Acid)', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (19, 17, 'Sculptra (Poly-L Lactic Acid)', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (20, 0, 'Laser Hair Removal', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (21, 0, 'IPL', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (22, 0, 'Facials / Hydrafacials', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (23, 0, 'ChemicalPeels', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (24, 0, 'Body / Slimming Contouring / Coolsculpting - General SculptingSculpting', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (25, 0, 'Vitamins / Injections / IV Therapy', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (26, 0, 'Weight Loss / Overall Wellness', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (27, 0, 'SpecialtyRequests', 0, 1, 0);
INSERT INTO `cat_treatments` VALUES (28, 27, 'BellaFill (Permanent Filler)', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (29, 27, 'B12/ MicLipo (MIC) Injections', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (30, 27, 'Skinny Shots', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (31, 27, 'Myers Cocktail Shots', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (32, 27, 'P Shot/O Shot', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (33, 27, 'Glutathione Injections', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (34, 27, 'General Injections', 1, 0, 0);
INSERT INTO `cat_treatments` VALUES (35, 27, 'IV Therapy', 1, 0, 0);
INSERT INTO `cat_treatments` VALUES (36, 27, 'PDO Threads', 2, 0, 0);
INSERT INTO `cat_treatments` VALUES (37, 27, 'Plasma Pen', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (38, 27, 'Microneedling with PRP', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (39, 27, 'Microneedling', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (40, 27, 'Microdermabrasion', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (41, 27, 'Microchanneling', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (42, 27, 'Aqua Gold', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (43, 27, 'Meso Gold', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (44, 27, 'Sclerotherapy', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (45, 27, 'PRP', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (46, 27, 'PRP Hair Restoration', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (47, 27, 'Chemical Peels', 2, 0, 0);
INSERT INTO `cat_treatments` VALUES (48, 27, 'VI Peel', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (49, 27, 'HydraFacial', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (50, 27, 'Latisse', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (51, 27, 'Vaginal rejuvenation', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (52, 27, 'Skin Care Products ', 1, 0, 0);
INSERT INTO `cat_treatments` VALUES (53, 27, 'Laser Hair Removal Treatments Diode', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (54, 27, 'Laser Hair Removal IPL', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (55, 27, 'Laser Tattoo Removal', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (56, 27, 'Ablative Laser CO2', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (57, 27, 'Ablative Laser', 1, 0, 0);
INSERT INTO `cat_treatments` VALUES (58, 27, 'Laser Treatments', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (59, 27, 'IPL/BBL Treatments', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (60, 27, 'Skinfinity RF', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (61, 27, 'General RF / IR Treatments ', 1, 0, 0);
INSERT INTO `cat_treatments` VALUES (62, 27, 'LED Therapy', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (63, 27, 'LightStim LED', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (64, 27, 'CoolSculpting', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (65, 27, 'Emsculpt', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (66, 27, 'Exilis 360', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (67, 27, 'Vanquish ME', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (68, 27, 'Emsella', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (69, 27, 'Cellutone', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (70, 27, 'Venus legacy', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (71, 27, 'TruSculpt 3d', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (72, 27, 'Ultherapy', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (73, 27, 'Facials', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (74, 27, 'Microcurrent', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (75, 27, 'Fire and Ice Facials', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (76, 27, 'Weight Loss', 1, 0, 0);
INSERT INTO `cat_treatments` VALUES (77, 27, 'Hormone Therapy', 1, 0, 0);
INSERT INTO `cat_treatments` VALUES (78, 27, 'Peptides', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (79, 27, 'BioTE', 0, 0, 0);
INSERT INTO `cat_treatments` VALUES (80, 27, 'Specialty Treatments', 1, 0, 0);
INSERT INTO `cat_treatments` VALUES (81, 0, 'Don\'t Know', 0, 0, 0);
COMMIT;

-- ----------------------------
-- Table structure for data_certificates
-- ----------------------------
DROP TABLE IF EXISTS `data_certificates`;
CREATE TABLE `data_certificates` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) DEFAULT NULL,
  `consultation_id` bigint(20) DEFAULT NULL,
  `date_start` datetime DEFAULT NULL,
  `date_expiration` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ----------------------------
-- Records of data_certificates
-- ----------------------------
BEGIN;
COMMIT;

-- ----------------------------
-- Table structure for data_consultation
-- ----------------------------
DROP TABLE IF EXISTS `data_consultation`;
CREATE TABLE `data_consultation` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) NOT NULL,
  `patient_id` bigint(20) NOT NULL DEFAULT 0,
  `assistance_id` bigint(20) NOT NULL,
  `treatments` varchar(255) NOT NULL,
  `payment` varchar(255) NOT NULL,
  `meeting` varchar(255) NOT NULL,
  `meeting_pass` varchar(255) NOT NULL,
  `schedule_date` datetime NOT NULL,
  `schedule_by` bigint(255) NOT NULL,
  `deleted` int(1) NOT NULL,
  `created` datetime(6) NOT NULL,
  `modified` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Records of data_consultation
-- ----------------------------
BEGIN;
INSERT INTO `data_consultation` VALUES (13, '9c1ec861-87ce-4069-a4ef-f6f27107cbad', 132, 0, '1,7,21', '', '95605302569', 'TBCKYw0K', '2021-01-25 12:11:07', 0, 0, '2021-01-25 12:11:08.281110', '2021-01-25 12:11:08.281151');
INSERT INTO `data_consultation` VALUES (14, '72129330-4b43-4108-9826-7fd0e29214eb', 132, 0, '24,25,26', '', '94865111635', 'ZfJ3L2r1', '2021-01-25 12:14:44', 0, 0, '2021-01-25 12:14:45.088676', '2021-01-25 12:14:45.088699');
INSERT INTO `data_consultation` VALUES (15, '92f6b187-0e90-44ac-a716-2c40b77b68bc', 132, 0, '1,17,24,25', '', '94618578546', '6SYvfEe1', '2021-02-02 01:49:50', 0, 0, '2021-02-02 01:49:50.673183', '2021-02-02 01:49:50.673207');
INSERT INTO `data_consultation` VALUES (16, '650542ae-6acf-42df-beda-02c05db4ac5d', 132, 0, '1,17,24,25', '', '95623724360', 'vG7QFJhR', '2021-02-02 01:50:33', 0, 0, '2021-02-02 01:50:33.926448', '2021-02-02 01:50:33.926472');
COMMIT;

-- ----------------------------
-- Table structure for data_consultation_answers
-- ----------------------------
DROP TABLE IF EXISTS `data_consultation_answers`;
CREATE TABLE `data_consultation_answers` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) DEFAULT NULL,
  `consultation_id` bigint(20) DEFAULT NULL,
  `question_id` int(2) DEFAULT NULL,
  `response` int(1) DEFAULT NULL,
  `findings` text DEFAULT NULL,
  `deleted` int(1) NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=76 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Records of data_consultation_answers
-- ----------------------------
BEGIN;
INSERT INTO `data_consultation_answers` VALUES (1, 'c1f953d6-5898-11eb-a872-dd43b3bb8e98', 1, 1, 1, NULL, 0, '2021-01-12 20:44:56');
INSERT INTO `data_consultation_answers` VALUES (2, 'c1f95638-5898-11eb-a872-dd43b3bb8e98', 1, 2, 1, 'this is the finding', 0, '2021-01-12 20:44:56');
INSERT INTO `data_consultation_answers` VALUES (3, 'c1f956c4-5898-11eb-a872-dd43b3bb8e98', 1, 3, 1, 'this is the finding', 0, '2021-01-12 20:44:56');
INSERT INTO `data_consultation_answers` VALUES (4, 'c1f9571e-5898-11eb-a872-dd43b3bb8e98', 1, 1, 1, NULL, 0, '2021-01-12 20:46:42');
INSERT INTO `data_consultation_answers` VALUES (5, 'c1f95782-5898-11eb-a872-dd43b3bb8e98', 1, 2, 0, NULL, 0, '2021-01-12 20:46:42');
INSERT INTO `data_consultation_answers` VALUES (6, 'c1f957e6-5898-11eb-a872-dd43b3bb8e98', 1, 3, 1, 'this is the finding', 0, '2021-01-12 20:46:42');
INSERT INTO `data_consultation_answers` VALUES (7, 'c1f95836-5898-11eb-a872-dd43b3bb8e98', 1, 1, 1, 'this is the finding', 0, '2021-01-16 06:56:04');
INSERT INTO `data_consultation_answers` VALUES (8, 'c1f95890-5898-11eb-a872-dd43b3bb8e98', 1, 2, 0, 'this is the finding', 0, '2021-01-16 06:56:04');
INSERT INTO `data_consultation_answers` VALUES (9, 'c1f958ea-5898-11eb-a872-dd43b3bb8e98', 1, 3, 1, 'this is the finding', 0, '2021-01-16 06:56:04');
INSERT INTO `data_consultation_answers` VALUES (10, 'a9157936-5dbc-4474-a655-9d2093159116', 3, 1, 1, NULL, 0, '2021-01-17 16:08:50');
INSERT INTO `data_consultation_answers` VALUES (11, '9cadf5f0-c2af-4c4c-895a-9b3c51fe15cd', 3, 2, 0, NULL, 0, '2021-01-17 16:08:50');
INSERT INTO `data_consultation_answers` VALUES (12, '2610ed0b-84e7-40f1-9cc3-26ad3da89b7d', 3, 3, 1, NULL, 0, '2021-01-17 16:08:50');
INSERT INTO `data_consultation_answers` VALUES (13, 'aed7f1a5-4206-4e8c-a0a1-6de8e0b44ff1', 4, 1, 1, NULL, 0, '2021-01-17 16:21:20');
INSERT INTO `data_consultation_answers` VALUES (14, '1ca62fe7-1737-448b-9d6e-46bfc14b017c', 4, 2, 0, NULL, 0, '2021-01-17 16:21:20');
INSERT INTO `data_consultation_answers` VALUES (15, '014a9162-1062-4e8f-92c4-e89ad4663665', 4, 3, 1, NULL, 0, '2021-01-17 16:21:20');
INSERT INTO `data_consultation_answers` VALUES (16, 'ff8b779c-103a-40df-936b-0ba882eeaabc', 5, 1, 1, NULL, 0, '2021-01-25 06:41:18');
INSERT INTO `data_consultation_answers` VALUES (17, '581882d6-b051-458f-b95f-d6e054e325b5', 5, 2, 0, NULL, 0, '2021-01-25 06:41:18');
INSERT INTO `data_consultation_answers` VALUES (18, '04a12554-8dbf-4b91-b5c8-bdc0e761fe8a', 5, 3, 1, NULL, 0, '2021-01-25 06:41:18');
INSERT INTO `data_consultation_answers` VALUES (19, 'eeae7f07-a0a0-4518-abe5-5e34083734bd', 6, 1, 1, NULL, 0, '2021-01-25 06:45:42');
INSERT INTO `data_consultation_answers` VALUES (20, '2c58996d-c3c5-457b-8369-b10e54f990f5', 6, 2, 0, NULL, 0, '2021-01-25 06:45:42');
INSERT INTO `data_consultation_answers` VALUES (21, '34c9675d-7f6b-4922-8541-ee616d8a0731', 6, 3, 1, NULL, 0, '2021-01-25 06:45:42');
INSERT INTO `data_consultation_answers` VALUES (22, '7a999e21-01cd-408f-a91a-7bf17cb8bf17', 7, 1, 1, NULL, 0, '2021-01-25 06:46:39');
INSERT INTO `data_consultation_answers` VALUES (23, 'd33a7636-fee4-42f9-ac1e-657d3c94b6e1', 7, 2, 0, NULL, 0, '2021-01-25 06:46:39');
INSERT INTO `data_consultation_answers` VALUES (24, '53ea89d3-3016-4852-b88b-42d10e510273', 7, 3, 1, NULL, 0, '2021-01-25 06:46:39');
INSERT INTO `data_consultation_answers` VALUES (25, 'a95c3f7c-96a1-47c6-85e1-d27e8fa7c0e8', 8, 1, 1, NULL, 0, '2021-01-25 07:17:29');
INSERT INTO `data_consultation_answers` VALUES (26, '3a79889f-0f61-4e57-b1fe-5e67a18b71a9', 8, 2, 0, NULL, 0, '2021-01-25 07:17:29');
INSERT INTO `data_consultation_answers` VALUES (27, '4069883f-edef-4441-9d60-0353516ef026', 8, 3, 1, NULL, 0, '2021-01-25 07:17:29');
INSERT INTO `data_consultation_answers` VALUES (28, 'af33c947-4a78-4fdc-9755-375ba530af4f', 9, 1, 1, NULL, 0, '2021-01-25 07:43:04');
INSERT INTO `data_consultation_answers` VALUES (29, '694ba7ad-0091-4a23-afda-371a2db746d9', 9, 2, 0, NULL, 0, '2021-01-25 07:43:04');
INSERT INTO `data_consultation_answers` VALUES (30, '73b65597-bbac-4abc-a80c-9a8adf168ba4', 9, 3, 1, NULL, 0, '2021-01-25 07:43:04');
INSERT INTO `data_consultation_answers` VALUES (31, '621ffff5-533a-456e-885e-43f487e92614', 10, 1, 1, NULL, 0, '2021-01-25 07:43:42');
INSERT INTO `data_consultation_answers` VALUES (32, 'ab6c078e-c82b-44ea-b68a-ba880b63d854', 10, 2, 0, NULL, 0, '2021-01-25 07:43:42');
INSERT INTO `data_consultation_answers` VALUES (33, 'dc431474-1d6f-4a34-80bc-2aee20a5125c', 10, 3, 1, NULL, 0, '2021-01-25 07:43:42');
INSERT INTO `data_consultation_answers` VALUES (34, 'a74ac7ea-3dc4-4a09-b387-fd502c56e1be', 11, 1, 1, NULL, 0, '2021-01-25 07:44:01');
INSERT INTO `data_consultation_answers` VALUES (35, '73c78905-6ab8-4400-8540-fff4e8f130a2', 11, 2, 0, NULL, 0, '2021-01-25 07:44:01');
INSERT INTO `data_consultation_answers` VALUES (36, 'a56d737e-9371-442a-8b09-9c6ad996d942', 11, 3, 1, NULL, 0, '2021-01-25 07:44:01');
INSERT INTO `data_consultation_answers` VALUES (37, '5464cb4f-827b-4b7b-b644-d18611634eae', 12, 1, 1, NULL, 0, '2021-01-25 11:08:25');
INSERT INTO `data_consultation_answers` VALUES (38, '6da6af06-1a05-4caf-8f1b-5ee2a7683d9e', 12, 2, 0, NULL, 0, '2021-01-25 11:08:25');
INSERT INTO `data_consultation_answers` VALUES (39, 'affab969-64a0-487b-aa65-e6092163ed94', 12, 3, 1, NULL, 0, '2021-01-25 11:08:25');
INSERT INTO `data_consultation_answers` VALUES (40, 'a1063c34-e0d5-4885-a3cc-1b940fa56013', 13, 1, 1, NULL, 0, '2021-01-25 12:11:08');
INSERT INTO `data_consultation_answers` VALUES (41, 'bab6c004-31b2-4c03-ada1-21d0d1554ce4', 13, 2, 0, NULL, 0, '2021-01-25 12:11:08');
INSERT INTO `data_consultation_answers` VALUES (42, '89f61d01-d421-4771-9499-053035540814', 13, 3, 1, NULL, 0, '2021-01-25 12:11:08');
INSERT INTO `data_consultation_answers` VALUES (43, '41179826-b307-4897-805f-9bcab37e0120', 14, 1, 1, NULL, 0, '2021-01-25 12:14:45');
INSERT INTO `data_consultation_answers` VALUES (44, '43bedbb6-0ca0-430f-861a-3e157568d961', 14, 2, 0, NULL, 0, '2021-01-25 12:14:45');
INSERT INTO `data_consultation_answers` VALUES (45, '93e06e65-fe67-4ccc-ab20-4a9c4f156539', 14, 3, 1, NULL, 0, '2021-01-25 12:14:45');
INSERT INTO `data_consultation_answers` VALUES (46, '0efa93d3-5108-422a-af53-c10d347c134e', 15, 10, 1, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (47, '167ce7b4-8ad1-4401-87f7-1f7999a9bf93', 15, 2, 0, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (48, '601b9e70-e422-432a-9f35-dea92c861a6e', 15, 15, 1, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (49, '93208aa2-16de-4fac-8fa0-128541527017', 15, 3, 0, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (50, '39815190-b2c2-4863-949b-df7bd8c1a7f1', 15, 11, 1, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (51, 'abc31870-92bc-4328-8462-9eb0ff732940', 15, 4, 0, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (52, '6fde6637-4c5e-4a82-ad61-a34698c06da7', 15, 5, 0, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (53, '0c32ab81-7b96-4d4e-b44f-7a89e3427bd9', 15, 12, 1, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (54, '34c7a974-116a-44f4-ba15-aa490e5e92e2', 15, 6, 0, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (55, 'be36e47a-fb8b-4f66-932f-4d756140059f', 15, 13, 1, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (56, '1263b0b3-a730-404b-a270-5303fa29c1b6', 15, 7, 0, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (57, '272df477-7467-432e-857c-fc2424af4b29', 15, 8, 1, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (58, '8cf44fb9-f98b-45b7-9800-04a1025d16b7', 15, 14, 1, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (59, '93529d44-18ff-4dd6-bae1-78379b2e18d4', 15, 1, 0, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (60, '81467086-ca9d-4c16-9e1c-ac2d16376a45', 15, 9, 1, NULL, 0, '2021-02-02 01:49:50');
INSERT INTO `data_consultation_answers` VALUES (61, 'eae18cd2-b077-4453-b957-4be931b61389', 16, 10, 1, NULL, 0, '2021-02-02 01:50:33');
INSERT INTO `data_consultation_answers` VALUES (62, '48f36f4c-1df6-4102-9686-338e91efb310', 16, 2, 0, NULL, 0, '2021-02-02 01:50:33');
INSERT INTO `data_consultation_answers` VALUES (63, 'f09a4cb5-f2b8-4651-bd05-ba05b0bfcfa9', 16, 15, 1, NULL, 0, '2021-02-02 01:50:33');
INSERT INTO `data_consultation_answers` VALUES (64, '5eab29cd-2dfd-4e7a-86b1-7b3f70b8791f', 16, 3, 0, NULL, 0, '2021-02-02 01:50:33');
INSERT INTO `data_consultation_answers` VALUES (65, '5bb4057e-9431-4098-9ccc-22e19449bb20', 16, 11, 1, NULL, 0, '2021-02-02 01:50:33');
INSERT INTO `data_consultation_answers` VALUES (66, 'c9c18143-c96c-4fd0-a269-b8d59fd6310d', 16, 4, 0, NULL, 0, '2021-02-02 01:50:33');
INSERT INTO `data_consultation_answers` VALUES (67, '1725187c-dbd1-4d1f-9e8f-5564657b651a', 16, 5, 0, NULL, 0, '2021-02-02 01:50:33');
INSERT INTO `data_consultation_answers` VALUES (68, '5e5db6fa-cedd-4788-bd44-d75b98008d2e', 16, 12, 1, NULL, 0, '2021-02-02 01:50:33');
INSERT INTO `data_consultation_answers` VALUES (69, '191e945e-1b32-4e2e-8ba9-217063258cc1', 16, 6, 0, NULL, 0, '2021-02-02 01:50:33');
INSERT INTO `data_consultation_answers` VALUES (70, '4c5158c8-b0e6-4846-9635-ac0e5fec251d', 16, 13, 1, NULL, 0, '2021-02-02 01:50:33');
INSERT INTO `data_consultation_answers` VALUES (71, '7c9b837d-c95a-48e8-aa20-03667b16679d', 16, 7, 0, NULL, 0, '2021-02-02 01:50:33');
INSERT INTO `data_consultation_answers` VALUES (72, 'ee1ded97-b803-4d95-8af6-e31eabfd6a59', 16, 8, 1, NULL, 0, '2021-02-02 01:50:33');
INSERT INTO `data_consultation_answers` VALUES (73, '32d6ff4c-aa32-4215-a721-2357b676ec66', 16, 14, 1, NULL, 0, '2021-02-02 01:50:33');
INSERT INTO `data_consultation_answers` VALUES (74, '76a0470d-496a-4473-9b1a-49e6a4af2bca', 16, 1, 0, NULL, 0, '2021-02-02 01:50:33');
INSERT INTO `data_consultation_answers` VALUES (75, '0d2a636c-1284-435f-9139-ed94b4b84867', 16, 9, 1, NULL, 0, '2021-02-02 01:50:33');
COMMIT;

-- ----------------------------
-- Table structure for data_consultation_plan
-- ----------------------------
DROP TABLE IF EXISTS `data_consultation_plan`;
CREATE TABLE `data_consultation_plan` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) DEFAULT NULL,
  `consultation_id` bigint(20) DEFAULT NULL,
  `treatment_id` int(10) DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `plan` text DEFAULT NULL,
  `proceed` int(1) unsigned DEFAULT NULL,
  `deleted` int(1) NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Records of data_consultation_plan
-- ----------------------------
BEGIN;
INSERT INTO `data_consultation_plan` VALUES (1, 'asdf', 1, 1, 'Detail of the treatment', 'tplan 1', 1, 0, '2021-01-17 08:06:28');
INSERT INTO `data_consultation_plan` VALUES (2, '55tgggg', 1, 2, '', 'not proceed', 0, 0, '2021-01-17 08:29:23');
INSERT INTO `data_consultation_plan` VALUES (3, '3asdf234sadf', 1, 1, 'Detail of the treatment', 'tplan 1', 1, 0, '2021-01-17 09:20:08');
INSERT INTO `data_consultation_plan` VALUES (4, 'asdf234sadfsad', 1, 2, '', 'not proceed', 0, 0, '2021-01-17 09:20:08');
INSERT INTO `data_consultation_plan` VALUES (5, NULL, 1, NULL, 'Detail of the treatment', 'tplan 1', 1, 0, '2021-01-17 09:30:54');
INSERT INTO `data_consultation_plan` VALUES (6, NULL, 1, NULL, '', 'not proceed', 0, 0, '2021-01-17 09:30:54');
INSERT INTO `data_consultation_plan` VALUES (7, NULL, 1, NULL, 'Detail of the treatment', 'tplan 1', 1, 0, '2021-01-17 09:31:10');
INSERT INTO `data_consultation_plan` VALUES (8, NULL, 1, NULL, '', 'not proceed', 0, 0, '2021-01-17 09:31:10');
INSERT INTO `data_consultation_plan` VALUES (9, NULL, 1, NULL, 'Detail of the treatment', 'tplan 1', 1, 0, '2021-01-17 09:31:38');
INSERT INTO `data_consultation_plan` VALUES (10, NULL, 1, NULL, '', 'not proceed', 0, 0, '2021-01-17 09:31:38');
INSERT INTO `data_consultation_plan` VALUES (11, 'f45c353b-6855-4221-84c7-e509427d4575', 1, 1, 'Detail of the treatment', 'tplan 1', 1, 0, '2021-01-17 09:32:17');
INSERT INTO `data_consultation_plan` VALUES (12, 'e9b21158-d9f4-4383-81ca-31a926f3c4ff', 1, 2, '', 'not proceed', 0, 0, '2021-01-17 09:32:17');
COMMIT;

-- ----------------------------
-- Table structure for sys_logs
-- ----------------------------
DROP TABLE IF EXISTS `sys_logs`;
CREATE TABLE `sys_logs` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `model` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `model_id` int(11) NOT NULL,
  `type` enum('Login','Logout','Create','Delete','Modify','Upload','Reset','Move','Baja','Destino') COLLATE utf8_unicode_ci NOT NULL,
  `json` text COLLATE utf8_unicode_ci NOT NULL,
  `ip` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(1) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `createdby` (`createdby`) USING BTREE,
  KEY `created` (`created`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Records of sys_logs
-- ----------------------------
BEGIN;
INSERT INTO `sys_logs` VALUES (1, 'Acceso', 0, 'Login', '', '::1', '2020-12-02 01:42:25', 1);
INSERT INTO `sys_logs` VALUES (2, 'Acceso', 0, 'Login', '', '::1', '2020-12-07 23:48:41', 1);
INSERT INTO `sys_logs` VALUES (3, 'Acceso', 0, 'Login', '', '::1', '2020-12-08 10:07:35', 1);
INSERT INTO `sys_logs` VALUES (4, 'Acceso', 0, 'Login', '', '::1', '2020-12-09 23:46:51', 1);
INSERT INTO `sys_logs` VALUES (5, 'Acceso', 0, 'Login', '', '::1', '2020-12-10 02:55:25', 1);
INSERT INTO `sys_logs` VALUES (6, 'Acceso', 0, 'Login', '', '::1', '2020-12-10 02:57:56', 1);
INSERT INTO `sys_logs` VALUES (7, 'Acceso', 0, 'Login', '', '::1', '2020-12-10 02:58:34', 1);
INSERT INTO `sys_logs` VALUES (8, 'Acceso', 0, 'Login', '', '::1', '2020-12-14 02:40:54', 1);
COMMIT;

-- ----------------------------
-- Table structure for sys_menus
-- ----------------------------
DROP TABLE IF EXISTS `sys_menus`;
CREATE TABLE `sys_menus` (
  `id` int(4) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `parent_id` int(4) unsigned NOT NULL,
  `nombre` varchar(255) CHARACTER SET utf8 NOT NULL,
  `descripcion` varchar(255) CHARACTER SET utf8 NOT NULL,
  `order` int(2) NOT NULL,
  `icono` varchar(255) CHARACTER SET utf8 NOT NULL,
  `module_id` int(11) NOT NULL,
  `module` varchar(255) CHARACTER SET utf8 NOT NULL,
  `script` text CHARACTER SET utf8 NOT NULL,
  `permisos` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `permiso` int(11) NOT NULL,
  `active` int(1) unsigned NOT NULL,
  `deleted` int(1) NOT NULL,
  `lft` int(4) NOT NULL,
  `rght` int(4) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE,
  KEY `parent_id` (`parent_id`) USING BTREE,
  KEY `lft` (`lft`) USING BTREE,
  KEY `rght` (`rght`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE,
  KEY `active` (`active`) USING BTREE,
  KEY `order` (`order`) USING BTREE,
  KEY `modulo_id` (`module_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Records of sys_menus
-- ----------------------------
BEGIN;
INSERT INTO `sys_menus` VALUES (1, '56ef4f23b8f988.37385160', 0, 'Menú', '', 1, '', 0, '', '', '', 0, 1, 0, 1, 42);
INSERT INTO `sys_menus` VALUES (3, '56ef4f23b8f988.37385162', 1, 'Nueva Solicitud', '', 1, '', 1, '', '', '', 0, 1, 0, 2, 3);
INSERT INTO `sys_menus` VALUES (12, '56ef4f23b8f988.37385171', 1, 'Seguimiento', '', 3, '', 2, '', '', '', 0, 1, 0, 6, 7);
INSERT INTO `sys_menus` VALUES (13, '56ef4f23b8f988.37385172', 1, 'Reportes', '', 4, '', 16, '', '', '', 0, 1, 0, 8, 15);
INSERT INTO `sys_menus` VALUES (18, '56ef4f23b8f988.37385173', 13, 'Administrar Encuesta', '', 2, '', 4, '', '', '', 0, 1, 1, 11, 12);
INSERT INTO `sys_menus` VALUES (19, '56ef4f23b8f988.37385174', 13, 'Tomar Encuesta', '', 3, '', 8, '', '', '', 0, 1, 1, 13, 14);
INSERT INTO `sys_menus` VALUES (20, '59de4b4c1557c0.03944257', 1, 'Panel', '', 6, 'ddd', 0, '', '', '', 0, 1, 0, 26, 41);
INSERT INTO `sys_menus` VALUES (21, '59de4f12def614.97690702', 20, 'Usuarios', '', 2, '', 5, '', '', '', 0, 1, 0, 29, 30);
INSERT INTO `sys_menus` VALUES (22, '59de4faee57872.19746356', 20, 'Permisos', '', 4, '', 12, '', '', '', 0, 1, 0, 33, 34);
INSERT INTO `sys_menus` VALUES (23, '59de4fe97b66b1.35101899', 20, 'Menús', '', 3, '', 9, '', '', '', 0, 1, 0, 31, 32);
INSERT INTO `sys_menus` VALUES (24, '59de4ff605c145.18527200', 20, 'Roles', '', 5, '', 11, '', '', '', 0, 1, 0, 35, 36);
INSERT INTO `sys_menus` VALUES (25, '59de50012da9b4.04142960', 20, 'Módulos', '', 7, '', 10, '', '', '', 0, 1, 0, 39, 40);
INSERT INTO `sys_menus` VALUES (26, '59ef53f6a37f55.88933610', 20, 'Dependencias y Servicios', '', 1, '', 13, '', '', '', 0, 1, 0, 27, 28);
INSERT INTO `sys_menus` VALUES (27, '59f0c4a94dc869.87650479', 1, 'Encuestas', '', 5, '', 0, '', '', '', 0, 1, 0, 16, 25);
INSERT INTO `sys_menus` VALUES (28, '59f0c4ff4e6460.14122554', 27, 'Administrar Encuestas', '', 1, '', 4, '', '', '', 0, 1, 0, 17, 18);
INSERT INTO `sys_menus` VALUES (29, '59f0c516d94897.71252708', 13, 'Realizar Encuesta', '', 1, '', 8, '', '', '', 0, 0, 1, 9, 10);
COMMIT;

-- ----------------------------
-- Table structure for sys_mimetypes
-- ----------------------------
DROP TABLE IF EXISTS `sys_mimetypes`;
CREATE TABLE `sys_mimetypes` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `mimetype` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `mimetype` (`mimetype`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Records of sys_mimetypes
-- ----------------------------
BEGIN;
INSERT INTO `sys_mimetypes` VALUES (1, 'application/msword', '2017-08-18 14:23:00', 1, '2017-08-18 14:23:00', 1);
INSERT INTO `sys_mimetypes` VALUES (2, 'image/jpeg', '2017-08-18 14:23:00', 1, '2017-08-18 14:23:00', 1);
INSERT INTO `sys_mimetypes` VALUES (3, 'application/vnd.ms-excel', '2017-08-18 14:23:00', 1, '2017-08-18 14:23:00', 1);
INSERT INTO `sys_mimetypes` VALUES (4, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', '2017-08-18 14:23:00', 1, '2017-08-18 14:23:00', 1);
INSERT INTO `sys_mimetypes` VALUES (5, 'application/pdf', '2017-08-18 14:23:00', 1, '2017-08-18 14:23:00', 1);
INSERT INTO `sys_mimetypes` VALUES (6, 'image/png', '2017-08-18 14:23:00', 1, '2017-08-18 14:23:00', 1);
INSERT INTO `sys_mimetypes` VALUES (7, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', '2017-08-18 14:23:00', 1, '2017-08-18 14:23:00', 1);
INSERT INTO `sys_mimetypes` VALUES (8, 'audio/mp3', '2018-04-30 17:49:54', 1, '2018-04-30 17:49:54', 1);
INSERT INTO `sys_mimetypes` VALUES (9, 'video/mp4', '2018-05-17 14:23:41', 4, '2018-05-17 14:23:41', 4);
INSERT INTO `sys_mimetypes` VALUES (10, 'image/jpg', '2019-09-11 09:19:19', 0, '2019-09-11 09:19:19', 0);
INSERT INTO `sys_mimetypes` VALUES (11, '', '2019-09-26 12:17:08', 50, '2019-09-26 12:17:08', 50);
INSERT INTO `sys_mimetypes` VALUES (12, 'application/octet-stream', '2019-10-07 18:15:39', 1, '2019-10-07 18:15:39', 1);
INSERT INTO `sys_mimetypes` VALUES (13, 'text/plain', '2019-10-30 17:49:42', 1, '2019-10-30 17:49:42', 1);
COMMIT;

-- ----------------------------
-- Table structure for sys_modulos
-- ----------------------------
DROP TABLE IF EXISTS `sys_modulos`;
CREATE TABLE `sys_modulos` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `nombre` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `descripcion` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `permiso_id` int(11) NOT NULL,
  `permisos` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `file` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `active` int(1) NOT NULL,
  `deleted` int(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`),
  KEY `deleted` (`deleted`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci ROW_FORMAT=COMPACT;

-- ----------------------------
-- Records of sys_modulos
-- ----------------------------
BEGIN;
INSERT INTO `sys_modulos` VALUES (1, '56ef4f23b8f988.37385159', 'Captura', '', 33, '60,57,33,32,1', 'captura.js', 'Tab.Captura', 1, 0, '2017-06-12 13:32:43', 0, '2017-10-11 22:22:55', 1);
INSERT INTO `sys_modulos` VALUES (2, '56ef4f23b8f988.37385160', 'Seguimiento', '', 34, '61,60,59,58,57,56,55,54,53,52,51,34,32,1', 'seguimiento.js', 'Tab.Seguimiento', 1, 0, '2017-06-21 16:18:14', 0, '2017-10-11 22:22:51', 1);
INSERT INTO `sys_modulos` VALUES (4, '56ef4f23b8f988.37385162', 'Administrador de Encuestas', '', 43, '43,42,1', 'preguntas.js', 'Tab.Preguntas', 1, 0, '2017-06-21 16:18:14', 0, '2017-11-02 17:52:23', 1);
INSERT INTO `sys_modulos` VALUES (5, '56ef4f23b8f988.37385163', 'Usuarios', '', 37, '37,36,1', 'usuarios.js', 'Tab.Usuarios', 1, 0, '2017-06-21 16:18:14', 0, '2017-10-11 22:23:06', 1);
INSERT INTO `sys_modulos` VALUES (8, '56ef4f23b8f988.37385166', 'Encuestas', '', 44, '44,42,1', 'encuestas.js', 'Tab.NuevaEncuesta', 1, 0, '2017-06-21 16:18:14', 0, '2017-11-02 17:52:05', 1);
INSERT INTO `sys_modulos` VALUES (9, '56ef4f23b8f988.37385176', 'Menús', '', 39, '39,36,1', 'menu.js', 'Tab.Menu', 1, 0, '2017-06-21 16:18:14', 0, '2017-10-11 22:23:16', 1);
INSERT INTO `sys_modulos` VALUES (10, '56ef4f23b8f988.37385178', 'Módulos', '', 41, '41,36,1', 'modulos.js', 'Tab.Modulos', 1, 0, '2017-06-21 16:18:14', 0, '2017-10-11 22:23:22', 1);
INSERT INTO `sys_modulos` VALUES (11, '59de6de2a9aa71.87777604', 'Roles', '', 40, '40,36,1', 'roles.js', 'Tab.Roles', 1, 0, '2017-10-11 14:15:46', 1, '2017-10-11 22:22:38', 1);
INSERT INTO `sys_modulos` VALUES (12, '59de6e89a96f89.57806725', 'Permisos', '', 38, '38,36,1', 'permisos.js', 'Tab.Permisos', 1, 0, '2017-10-11 14:18:33', 1, '2017-10-11 22:22:33', 1);
COMMIT;

-- ----------------------------
-- Table structure for sys_permisos
-- ----------------------------
DROP TABLE IF EXISTS `sys_permisos`;
CREATE TABLE `sys_permisos` (
  `id` int(1) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `parent_id` int(1) unsigned NOT NULL,
  `nombre` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `descripcion` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `order` int(11) NOT NULL,
  `active` int(1) unsigned NOT NULL,
  `deleted` int(1) NOT NULL,
  `lft` int(1) NOT NULL,
  `rght` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`),
  KEY `parent_id` (`parent_id`),
  KEY `tree` (`lft`,`rght`),
  KEY `active` (`active`),
  KEY `orden` (`order`),
  KEY `deleted` (`deleted`),
  KEY `lft` (`lft`),
  KEY `rght` (`rght`)
) ENGINE=InnoDB AUTO_INCREMENT=62 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci ROW_FORMAT=COMPACT;

-- ----------------------------
-- Records of sys_permisos
-- ----------------------------
BEGIN;
INSERT INTO `sys_permisos` VALUES (1, '56ef4f23b8f988.37385160', 0, 'Todos los Permisos', 'Todos los Permisos', 1, 1, 0, 1, 60, '2017-06-21 16:18:14', 1, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_permisos` VALUES (32, '59de7319187667.57431293', 1, 'Coordinador de Solicitudes', '', 1, 1, 0, 2, 31, '2017-10-11 14:38:01', 1, '2017-10-19 17:13:55', 1);
INSERT INTO `sys_permisos` VALUES (33, '59de732f837462.58341572', 32, 'Captura de Solicitud', '', 1, 1, 0, 3, 4, '2017-10-11 14:38:23', 1, '2017-10-11 14:38:23', 1);
INSERT INTO `sys_permisos` VALUES (34, '59de733ce59072.28643435', 32, 'Seguimiento de Solicitudes', '', 2, 1, 0, 5, 28, '2017-10-11 14:38:36', 1, '2017-10-11 14:38:36', 1);
INSERT INTO `sys_permisos` VALUES (35, '59de7348a771d0.49964345', 1, 'Reportes', '', 3, 1, 0, 40, 41, '2017-10-11 14:38:48', 1, '2017-10-11 14:38:48', 1);
INSERT INTO `sys_permisos` VALUES (36, '59de735a314576.70752896', 1, 'Panel', 'Administración de Usuarios, Permisos, Menús y Roles', 4, 1, 0, 42, 59, '2017-10-11 14:39:06', 1, '2017-10-11 14:39:06', 1);
INSERT INTO `sys_permisos` VALUES (37, '59de736232dca3.88440743', 36, 'Usuarios', '', 2, 1, 0, 47, 48, '2017-10-11 14:39:14', 1, '2017-10-11 14:54:47', 1);
INSERT INTO `sys_permisos` VALUES (38, '59de736ab3a6f3.64239272', 36, 'Permisos', '', 3, 1, 0, 49, 50, '2017-10-11 14:39:22', 1, '2017-10-11 14:54:50', 1);
INSERT INTO `sys_permisos` VALUES (39, '59de737415dd98.38204145', 36, 'Menús', '', 4, 1, 0, 51, 52, '2017-10-11 14:39:32', 1, '2017-10-11 15:03:52', 1);
INSERT INTO `sys_permisos` VALUES (40, '59de737a48abb1.28019337', 36, 'Roles', '', 5, 1, 0, 53, 54, '2017-10-11 14:39:38', 1, '2017-10-11 15:05:28', 1);
INSERT INTO `sys_permisos` VALUES (41, '59de7382d6a862.45681141', 36, 'Módulos', '', 6, 1, 0, 55, 56, '2017-10-11 14:39:46', 1, '2017-10-11 14:54:56', 1);
INSERT INTO `sys_permisos` VALUES (42, '59e7e360bc7348.95116903', 1, 'Encuestas', '', 2, 0, 0, 32, 39, '2017-10-18 18:27:28', 1, '2017-10-18 18:27:56', 1);
INSERT INTO `sys_permisos` VALUES (43, '59e7e3e6295033.08949395', 42, 'Administración de Encuestas', '', 2, 1, 0, 35, 36, '2017-10-18 18:29:42', 1, '2017-10-18 18:29:42', 1);
INSERT INTO `sys_permisos` VALUES (44, '59e7e4b0580241.02527451', 42, 'Captura de Encuesta', '', 3, 1, 0, 37, 38, '2017-10-18 18:33:04', 1, '2017-10-18 18:33:04', 1);
INSERT INTO `sys_permisos` VALUES (45, '59e8fa6b66aca7.28079140', 32, 'Rechazar Borradores', 'Habilita el botón de rechazar, cuando se ingresa al detalle de una solicitud en borrador.', 3, 1, 0, 29, 30, '2017-10-19 14:18:03', 1, '2017-10-19 17:13:43', 1);
INSERT INTO `sys_permisos` VALUES (46, '59fbaec51d9a22.55326563', 36, 'Dependencias y Servicios', 'Permite administrar las Dependencias y Servicios', 1, 1, 0, 43, 46, '2017-11-02 17:48:21', 1, '2017-11-02 17:48:58', 1);
INSERT INTO `sys_permisos` VALUES (47, '59fbaf8f5de7f1.62349214', 42, 'Reportes de Encuestas', 'Reportes de Encuestas', 1, 1, 0, 33, 34, '2017-11-02 17:51:43', 1, '2017-11-02 17:51:50', 1);
INSERT INTO `sys_permisos` VALUES (48, '59fbaec51d9a22.55326564', 46, 'Solo lectura', 'Solo consulta de servicios y dependencias', 1, 1, 0, 44, 45, '2017-06-21 16:18:14', 1, '2017-06-21 16:18:14', 1);
INSERT INTO `sys_permisos` VALUES (49, '59de7382d6a862.45681142', 36, 'Bitacora', '', 7, 1, 0, 57, 58, '2017-10-11 14:39:46', 1, '2017-10-11 14:54:56', 1);
INSERT INTO `sys_permisos` VALUES (51, '59de7382d6a862.45681150', 34, 'Procesar solicitud', 'Habilita el botón de procesar solicitud', 2, 1, 0, 8, 9, '2017-06-21 16:18:14', 1, '2017-06-21 16:18:14', 1);
INSERT INTO `sys_permisos` VALUES (52, '59de7382d6a862.45681151', 34, 'Terminar solicitud', 'Habilita el botón de terminar solicitud', 3, 1, 0, 10, 11, '2017-06-21 16:18:14', 1, '2017-06-21 16:18:14', 1);
INSERT INTO `sys_permisos` VALUES (53, '59de7382d6a862.45681152', 34, 'Cancelar solicitud', 'Habilita el botón de cancelar solicitud', 4, 1, 0, 12, 13, '2017-06-21 16:18:14', 1, '2017-06-21 16:18:14', 1);
INSERT INTO `sys_permisos` VALUES (54, '59de7382d6a862.45681153', 34, 'Rechazar solicitud', 'Habilita el botón de rechazar solicitud', 5, 1, 0, 14, 15, '2017-06-21 16:18:14', 1, '2017-06-21 16:18:14', 1);
INSERT INTO `sys_permisos` VALUES (55, '59de7382d6a862.45681154', 34, 'Reasignar solicitud', 'Habilita el botón de reasignar solicitud', 6, 1, 0, 16, 17, '2017-06-21 16:18:14', 1, '2017-06-21 16:18:14', 1);
INSERT INTO `sys_permisos` VALUES (56, '59de7382d6a862.45681155', 34, 'Reprocesar solicitud', 'Habilita el botón de reprocesar solicitud', 7, 1, 0, 18, 19, '2017-06-21 16:18:14', 1, '2017-06-21 16:18:14', 1);
INSERT INTO `sys_permisos` VALUES (57, '59de7382d6a862.45681156', 34, 'Editar solicitud', 'Habilita el botón de editar solicitud en todos los status de la solicitud, excepto en TERMINADO y CANCELADO', 8, 1, 0, 20, 23, '2017-06-21 16:18:14', 1, '2017-06-21 16:18:14', 1);
INSERT INTO `sys_permisos` VALUES (58, '59de7382d6a862.45681157', 34, 'Encuesta 072', 'Habilita el botón de encuesta del 072 para las solicitudes que lo requieran', 9, 1, 0, 24, 25, '2017-06-21 16:18:14', 1, '2017-06-21 16:18:14', 1);
INSERT INTO `sys_permisos` VALUES (59, '59fbaec51d9a22.55326565', 34, 'Solo lectura', 'Permite visualizar el módulo de seguimiento y el detalle, sin ninguna acción sobre la solicitud', 1, 1, 0, 6, 7, '2017-06-21 16:18:14', 1, '2017-06-21 16:18:14', 1);
INSERT INTO `sys_permisos` VALUES (60, '59fbaec51d9a22.55326566', 57, 'Edición básica', 'Habilita el botón de editar solicitud en los status de BORRADOR, BUZÓN y RECHAZADO.', 1, 1, 0, 21, 22, '2017-06-21 16:18:14', 1, '2017-06-21 16:18:14', 1);
INSERT INTO `sys_permisos` VALUES (61, '59de7319187667.57431223', 34, 'Desasignar borrador', 'Habilita el botón de desasignar, en el detalle del borrador', 10, 1, 0, 26, 27, '2019-11-04 13:55:50', 1, '2019-11-04 13:55:58', 1);
COMMIT;

-- ----------------------------
-- Table structure for sys_reportes
-- ----------------------------
DROP TABLE IF EXISTS `sys_reportes`;
CREATE TABLE `sys_reportes` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `tipo` enum('Solicitudes') COLLATE utf8_unicode_ci NOT NULL,
  `filename` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `pid` int(11) NOT NULL,
  `status` enum('Pendiente','Proceso','Terminado','Cancelado') COLLATE utf8_unicode_ci NOT NULL,
  `inicio` datetime NOT NULL,
  `fin` datetime NOT NULL,
  `observaciones` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `avance` int(3) NOT NULL,
  `params` text COLLATE utf8_unicode_ci NOT NULL,
  `error` text COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Records of sys_reportes
-- ----------------------------
BEGIN;
COMMIT;

-- ----------------------------
-- Table structure for sys_roles
-- ----------------------------
DROP TABLE IF EXISTS `sys_roles`;
CREATE TABLE `sys_roles` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `nombre` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `descripcion` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `active` int(1) NOT NULL,
  `deleted` int(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(1) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(1) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE,
  KEY `created` (`created`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Records of sys_roles
-- ----------------------------
BEGIN;
INSERT INTO `sys_roles` VALUES (1, '59dec8838ae058.27180439', 'Master', 'Todos los permisos', 1, 0, '2017-10-11 20:42:27', 1, '2018-04-16 10:47:02', 1);
INSERT INTO `sys_roles` VALUES (2, '59dedf6f0981e6.77952496', 'Administrador', 'Administrar del Sistema', 1, 0, '2017-10-11 22:20:15', 1, '2019-10-29 14:23:05', 1);
INSERT INTO `sys_roles` VALUES (3, '59e7dc333894d9.32622984', 'Coordinación', 'Coordinación de Captura', 1, 0, '2017-10-18 17:56:51', 1, '2019-11-11 17:17:42', 1);
INSERT INTO `sys_roles` VALUES (4, '59e7dc333894d9.32622983', 'Enlace', 'Enlace de dependencias', 1, 0, '2017-10-18 17:56:51', 1, '2020-02-20 17:25:45', 1);
INSERT INTO `sys_roles` VALUES (5, '59e7dc333894d9.32622985', 'Captura', 'Captura de Solicitudes', 1, 0, '2017-10-18 17:56:51', 1, '2019-10-30 18:20:17', 1);
INSERT INTO `sys_roles` VALUES (6, '5b18002402c736.97730629', 'Capturista 2', 'Captura otra vez', 0, 1, '2018-06-06 10:39:16', 8, '2019-05-30 20:06:42', 1);
INSERT INTO `sys_roles` VALUES (7, '5c5b3886f0f1c8.50436149', 'null', 'null', 0, 1, '2019-02-06 13:41:58', 1, '2019-05-30 20:06:22', 1);
COMMIT;

-- ----------------------------
-- Table structure for sys_roles_permisos
-- ----------------------------
DROP TABLE IF EXISTS `sys_roles_permisos`;
CREATE TABLE `sys_roles_permisos` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `rol_id` int(11) NOT NULL,
  `permiso_id` int(11) NOT NULL,
  `deleted` int(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(1) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(1) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `grupo_id` (`rol_id`) USING BTREE,
  KEY `permiso_id` (`permiso_id`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=615 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Records of sys_roles_permisos
-- ----------------------------
BEGIN;
INSERT INTO `sys_roles_permisos` VALUES (32, 1, 1, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (53, 7, 1, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (54, 7, 32, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (55, 7, 33, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (56, 7, 34, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (57, 7, 42, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (58, 7, 47, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (59, 7, 43, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (60, 7, 44, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (61, 7, 35, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (62, 7, 36, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (63, 7, 46, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (64, 7, 37, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (65, 7, 38, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (66, 7, 39, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (67, 7, 40, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (68, 7, 41, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (424, 2, 32, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (425, 2, 47, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (426, 2, 43, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (427, 2, 44, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (428, 2, 42, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (429, 2, 35, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (430, 2, 48, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (574, 5, 33, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (575, 5, 44, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (576, 5, 48, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (577, 5, 45, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (578, 5, 59, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (579, 5, 60, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (596, 3, 35, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (597, 3, 48, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (598, 3, 44, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (599, 3, 33, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (600, 3, 51, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (601, 3, 52, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (602, 3, 53, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (603, 3, 54, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (604, 3, 55, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (605, 3, 56, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (606, 3, 57, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (607, 3, 45, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (608, 3, 58, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
INSERT INTO `sys_roles_permisos` VALUES (614, 4, 54, 0, '2017-06-21 16:18:14', 0, '2017-06-21 16:18:14', 0);
COMMIT;

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
-- Records of sys_sessions
-- ----------------------------
BEGIN;
INSERT INTO `sys_sessions` VALUES ('3631a4e55e3f866530642a4808eefd25', 'Config|a:1:{s:4:\"time\";i:1607938633;}Usuario|a:6:{s:2:\"id\";i:1;s:3:\"uid\";s:23:\"56ef4f23b8f988.37385161\";s:8:\"username\";s:6:\"master\";s:15:\"nombre_completo\";s:6:\"Master\";s:14:\"dependencia_id\";i:0;s:6:\"rol_id\";i:1;}', 1607940073);
INSERT INTO `sys_sessions` VALUES ('5712cebf38dc770f7c305b2822b5c202', 'Config|a:1:{s:4:\"time\";i:1607885920;}', 1607887360);
COMMIT;

-- ----------------------------
-- Table structure for sys_users
-- ----------------------------
DROP TABLE IF EXISTS `sys_users`;
CREATE TABLE `sys_users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `email` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `type` enum('patient','clinic','provider') COLLATE utf8_unicode_ci NOT NULL,
  `active` int(1) NOT NULL,
  `confirm` int(1) NOT NULL,
  `confirm_code` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `deleted` int(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE,
  KEY `active` (`active`) USING BTREE,
  KEY `confirmado` (`confirm`) USING BTREE,
  KEY `rol_id` (`type`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=133 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Records of sys_users
-- ----------------------------
BEGIN;
INSERT INTO `sys_users` VALUES (1, '56ef4f23b8f988.37385161', 'Master', '@master', '$2y$10$jOx/QSSNE9195kcZ/SdWe.bLBcRb1usVYVGPM/.ZAD0V/jYDdB0Jq', 'provider', 1, 1, '', 0, '2012-07-21 16:33:54', 0, '2019-04-17 09:44:14', 1);
INSERT INTO `sys_users` VALUES (68, '56ef4f23b8f988.37385162', 'Luis Valdez', 'valdezfcluis@gmail.com', '809d539fb2f2e23ec5d0a0032b4f8cac48c24e26b42f099dee23279dd4466b9f', '', 1, 1, '', 0, '2012-07-21 16:33:54', 0, '2019-05-30 13:06:09', 1);
INSERT INTO `sys_users` VALUES (69, '3c261aff-7387-4c24-9507-fb70d7dae559', 'Luis Valdez', 'valdez@gmail.com', 'fbc58d6548f9f2b4f889821028137534c6a7b6544d32ec1f6f7ea54dc785b791', 'patient', 1, 1, '0', 0, '2021-01-11 16:32:25', 1, '2021-01-11 16:32:25', 1);
INSERT INTO `sys_users` VALUES (70, '719f651b-429b-4e2a-81fd-6dfa1786b20e', 'Luis Valdez', 'valdezz@gmail.com', '3d31c5ae61fdca7b8855ef711eade7a3445914a059ac3d96bdd6def732c75cb5', 'patient', 1, 1, '0', 0, '2021-01-11 16:35:03', 1, '2021-01-11 16:35:03', 1);
INSERT INTO `sys_users` VALUES (71, 'c92f77eb-7267-4e91-9770-e4b4a83ffa26', 'Luis ', 'valfdezzz@gmail.com', '3d31c5ae61fdca7b8855ef711eade7a3445914a059ac3d96bdd6def732c75cb5', 'patient', 1, 1, '0', 0, '2021-01-11 16:54:36', 0, '2021-01-11 16:54:36', 0);
INSERT INTO `sys_users` VALUES (72, 'a949ca89-a4b2-4229-9c0b-3e82849bec59', 'Luis ', 'valfdfezzz@gmail.com', '3d31c5ae61fdca7b8855ef711eade7a3445914a059ac3d96bdd6def732c75cb5', 'patient', 1, 1, '0', 0, '2021-01-11 16:56:43', 0, '2021-01-11 16:56:43', 0);
INSERT INTO `sys_users` VALUES (73, '7e408a8a-c514-4a39-882e-d10a92caf07a', 'Luis ', 'vaalfdfezzz@gmail.com', '3d31c5ae61fdca7b8855ef711eade7a3445914a059ac3d96bdd6def732c75cb5', 'patient', 1, 1, '0', 0, '2021-01-11 16:59:18', 0, '2021-01-11 16:59:18', 0);
INSERT INTO `sys_users` VALUES (74, '6310a4d6-6ff5-4633-a446-980584c14a45', 'Luis ', 'vaaalfdfezzz@gmail.com', '3d31c5ae61fdca7b8855ef711eade7a3445914a059ac3d96bdd6def732c75cb5', 'patient', 1, 1, '0', 0, '2021-01-11 17:07:20', 0, '2021-01-11 17:07:20', 0);
INSERT INTO `sys_users` VALUES (75, '0836fd38-8f0b-4513-8e37-4f670e32a9b3', 'Luis Valdez', 'khanzab2@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'patient', 1, 1, '0', 0, '2021-01-25 01:51:47', 0, '2021-01-25 01:51:47', 0);
INSERT INTO `sys_users` VALUES (76, '14e200e0-dcbb-4b19-9d0f-c2b4a2b7b118', 'Luis Valdez Gggggg', 'khaffnzab@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'patient', 1, 1, '0', 0, '2021-01-25 02:00:58', 0, '2021-01-25 02:00:58', 0);
INSERT INTO `sys_users` VALUES (77, 'eb211a88-3f8c-4b26-b257-a221f56d3f54', 'Lui valdez', 'valdeddz@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'patient', 1, 1, '0', 0, '2021-01-25 02:04:05', 0, '2021-01-25 02:04:05', 0);
INSERT INTO `sys_users` VALUES (78, 'a2ad2340-80e9-4e8b-b22d-6cc3a4dd62be', 'Lui valdez', 'zvffaldeddz@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'patient', 1, 1, '0', 0, '2021-01-25 02:06:01', 0, '2021-01-25 02:06:01', 0);
INSERT INTO `sys_users` VALUES (79, '744015b8-fd60-494a-a1f1-79f18f4c0dc7', 'Luis Valdez Gggggg', 'khanfzab@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'patient', 1, 1, '0', 0, '2021-01-25 02:15:10', 0, '2021-01-25 02:15:10', 0);
INSERT INTO `sys_users` VALUES (80, '89f7dc0d-14ac-4657-93ab-91ee98f29a7e', 'Luis Valdez Gggggg', 'khanffzab@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'patient', 1, 1, '0', 0, '2021-01-25 02:17:48', 0, '2021-01-25 02:17:48', 0);
INSERT INTO `sys_users` VALUES (81, '1bccdc0d-40ed-454e-9a13-bab92450f441', 'Dr', 'khanzasdfab@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 02:50:50', 0, '2021-01-25 02:50:50', 0);
INSERT INTO `sys_users` VALUES (82, 'aaf9c06d-9473-4f96-9b88-616ba9afdc7f', 'Lui', 'zvaldeddz@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 02:55:06', 0, '2021-01-25 02:55:06', 0);
INSERT INTO `sys_users` VALUES (83, '5cc7c2c4-e113-4b95-a26e-f36dee6f0daa', 'Lui', 'zvalxdeddz@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:23:51', 0, '2021-01-25 04:23:51', 0);
INSERT INTO `sys_users` VALUES (84, '5669dd62-6ab9-46d5-b829-cfb7e89a48bc', 'Luis Vvhh', 'valdefzcluis@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:26:49', 0, '2021-01-25 04:26:49', 0);
INSERT INTO `sys_users` VALUES (85, '1aa6b913-7946-4fb0-8c86-6a24fdff5b90', 'Luis Vvhh', 'valdfezcluis@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:27:17', 0, '2021-01-25 04:27:17', 0);
INSERT INTO `sys_users` VALUES (86, '2e7a48b7-0d3b-4851-b155-caa2afc253ac', 'Luis Vvhh', 'valdezclfuis@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:27:38', 0, '2021-01-25 04:27:38', 0);
INSERT INTO `sys_users` VALUES (87, '388c4c6d-8a83-4743-81d0-35308cda1c8b', 'Luis Vvhh', 'valdezcluis@gmail.comasdf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:28:09', 0, '2021-01-25 04:28:09', 0);
INSERT INTO `sys_users` VALUES (88, '3487becf-8e3b-4d1f-8c75-51e99d6ff5e4', 'Luis Vvhh', 'valdezcluis@gmailfff.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:30:00', 0, '2021-01-25 04:30:00', 0);
INSERT INTO `sys_users` VALUES (89, 'f5519bd5-99e0-4f38-832d-13f51f849964', 'Luis Vvhh', 'valdezcdasfluis@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:32:07', 0, '2021-01-25 04:32:07', 0);
INSERT INTO `sys_users` VALUES (90, 'b5776c8a-29a9-4794-9e09-ba0602601163', 'Bbb Jjj', 'khaffnzab@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:38:54', 0, '2021-01-25 04:38:54', 0);
INSERT INTO `sys_users` VALUES (91, 'a262ae79-ee3c-4e98-bd8c-d7eff7f2d9d9', 'Lui valdez', 'zvalxdfeddz@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:39:29', 0, '2021-01-25 04:39:29', 0);
INSERT INTO `sys_users` VALUES (92, 'd1b54be1-1dd4-4809-819d-34a0c72d7c79', 'Lui valdez', 'zvalxdfeddz@gdmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:40:27', 0, '2021-01-25 04:40:27', 0);
INSERT INTO `sys_users` VALUES (93, 'f493b674-477b-4992-a6d5-868d6c733dcc', 'Bbb Jjj', 'khanfzab@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:42:25', 0, '2021-01-25 04:42:25', 0);
INSERT INTO `sys_users` VALUES (94, '4b45d467-dea2-4b26-b121-f6f2a692965a', 'Lui valdez', 'zvaflxdfeddz@gdmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:42:48', 0, '2021-01-25 04:42:48', 0);
INSERT INTO `sys_users` VALUES (95, '34986fd9-cb1e-4fe7-b337-74ad6b8b98d6', 'Lui valdez', 'zvaflxdfeddz@gdmail.comf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:43:32', 0, '2021-01-25 04:43:32', 0);
INSERT INTO `sys_users` VALUES (96, '9c49e4c0-b22e-42b6-8b5e-0c8e07ecf0b7', 'Soa', 'valdezcluis@gmazil.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'clinic', 1, 1, '0', 0, '2021-01-25 04:48:14', 0, '2021-01-25 04:48:14', 0);
INSERT INTO `sys_users` VALUES (97, '7fef4cfd-8324-4014-bb9d-8ddf6cef99d7', 'Lui valdez', 'zvaflxdfeddz@gfdmail.comf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:50:29', 0, '2021-01-25 04:50:29', 0);
INSERT INTO `sys_users` VALUES (98, '3c87baa3-d9f6-43c7-b39c-77c2be326fb0', 'Lui valdez', 'zvaflxdfeddz@gfdmfail.comf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:53:35', 0, '2021-01-25 04:53:35', 0);
INSERT INTO `sys_users` VALUES (99, '547d9b67-2325-4d06-9ecf-e2cffaf91a48', 'Lui valdez', 'zvaflxdfefddz@gfdmfail.comf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:54:04', 0, '2021-01-25 04:54:04', 0);
INSERT INTO `sys_users` VALUES (100, '4d7ecdcc-d270-438f-9f8c-1ede223a5dcb', 'Lui valdez', 'zvaflxdfefddz@gfdmfail.codmf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:54:14', 0, '2021-01-25 04:54:14', 0);
INSERT INTO `sys_users` VALUES (101, '25e2a0b2-cbda-417d-9382-baf2a8a329b7', 'Hhhgg Cfgg', 'khanzab@gmffail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:55:57', 0, '2021-01-25 04:55:57', 0);
INSERT INTO `sys_users` VALUES (102, '1e70e7f2-fbea-47fa-b2b5-ebcd5da0478a', 'Hhhgg Cfgg', 'khanffzab@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 04:59:39', 0, '2021-01-25 04:59:39', 0);
INSERT INTO `sys_users` VALUES (103, '9f5b2333-1e90-4157-9abe-884ca4171535', 'Hhhgg Cfgg', 'khanzfab@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:01:50', 0, '2021-01-25 05:01:50', 0);
INSERT INTO `sys_users` VALUES (104, '8928528c-06c3-43fe-8620-821a700bf025', 'Hhhgg Cfgg', 'khanfzab@gmfail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:02:13', 0, '2021-01-25 05:02:13', 0);
INSERT INTO `sys_users` VALUES (105, 'bf7d7d8e-90e1-41d4-8262-d87a8d867b09', 'Lui valdez', 'zvaflxdfefddz@gfdmfail.cofdmf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:02:31', 0, '2021-01-25 05:02:31', 0);
INSERT INTO `sys_users` VALUES (106, '6f90841e-1e64-43c9-a731-e43770c0d16b', 'Lui valdez', 'zvaflxdfeffddz@gfdmfail.cofdmf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:03:58', 0, '2021-01-25 05:03:58', 0);
INSERT INTO `sys_users` VALUES (107, 'b9df3748-5e95-4659-9a8e-807bead693c5', 'Lui valdez', 'zvaflxdfeffddz@gfdfmfail.cofdmf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:04:40', 0, '2021-01-25 05:04:40', 0);
INSERT INTO `sys_users` VALUES (108, '1924ed9d-eda7-42ca-ab8e-14646d71772c', 'Lui valdez', 'zvaflxdfeffddz@gfdfmfail.cofdfmf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:05:00', 0, '2021-01-25 05:05:00', 0);
INSERT INTO `sys_users` VALUES (109, '5d95b8c3-be31-47f6-b5ea-d9ae26e240a8', 'Lui valdez', 'zvaflxdfeffdfdz@gfdfmfail.cofdfmf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:05:14', 0, '2021-01-25 05:05:14', 0);
INSERT INTO `sys_users` VALUES (110, '29d4a0f2-0dc3-47d8-b1cd-1a1990bcf69f', 'Lui valdez', 'zvaflxdfeffdfdz@fgfdfmfail.cofdfmf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:05:25', 0, '2021-01-25 05:05:25', 0);
INSERT INTO `sys_users` VALUES (111, 'b4fe9c70-ff41-4936-9427-304a05972983', 'Lui valdez', 'zvaflxfdfeffdfdz@fgfdfmfail.cofdfmf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:06:06', 0, '2021-01-25 05:06:06', 0);
INSERT INTO `sys_users` VALUES (112, 'bf0388db-2327-4cc3-8ee8-97ba39a3494e', 'Lui valdez', 'zvaflxffdfeffdfdz@fgfdfmfail.cofdfmf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:06:55', 0, '2021-01-25 05:06:55', 0);
INSERT INTO `sys_users` VALUES (113, 'c62ad07a-65e5-446a-851d-3fb4db433bf9', 'Lui valdez', 'zvaflxffdfeffdfdz@fgfdfmfail.coffdfmf', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:08:25', 0, '2021-01-25 05:08:25', 0);
INSERT INTO `sys_users` VALUES (114, '7b8faee9-4cbf-4750-a8fd-d2cfdc649046', 'Lui valdez', 'zvaflxffdfeffdfdz@fgfdfmfail.coffdfmff', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:10:26', 0, '2021-01-25 05:10:26', 0);
INSERT INTO `sys_users` VALUES (115, 'ae31b090-f2f1-4831-8515-9332cf26bd30', 'Lui valdez', 'zvaflfxffdfeffdfdz@fgfdfmfail.coffdfmff', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:10:35', 0, '2021-01-25 05:10:35', 0);
INSERT INTO `sys_users` VALUES (116, 'cfa62ad5-8e48-4879-9739-e972fc6db8fe', 'Lui valdez', 'zvaflffxffdfeffdfdz@fgfdfmfail.coffdfmff', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:10:58', 0, '2021-01-25 05:10:58', 0);
INSERT INTO `sys_users` VALUES (117, '4aa7bf78-fb50-49a3-a0ed-640a2204ecff', 'Lui valdez', 'zvaflffxffdfeffdfdz@fgfdfmfail.coffdfmff', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:11:22', 0, '2021-01-25 05:11:22', 0);
INSERT INTO `sys_users` VALUES (118, '0ef0d4dc-74bc-4ac3-8ba2-0cf1302c24ed', 'Lui valdez', 'zvaflffxffdfeffdfdz@fgfdfmfail.coffdfmff', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:16:20', 0, '2021-01-25 05:16:20', 0);
INSERT INTO `sys_users` VALUES (119, '6a849ee9-75cd-4e76-8149-6dc1526c7ad4', 'Lui valdez', 'zvaflffxffdfeffdfdz@fgfdfmfail.coffdfmff', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:17:28', 0, '2021-01-25 05:17:28', 0);
INSERT INTO `sys_users` VALUES (120, '21e36904-d651-4be4-bba5-ac9d03ba0c52', 'Lui valdez', 'zvaflffxffdfeffdfdz@fgfdfmfail.coffdfmff', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:18:01', 0, '2021-01-25 05:18:01', 0);
INSERT INTO `sys_users` VALUES (121, '9a865538-3646-4b6c-bdd9-61f4db8d18e5', 'Lui valdez', 'zvaflffxffdfeffdfdz@fgfdfmfail.coffdfmff', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:18:15', 0, '2021-01-25 05:18:15', 0);
INSERT INTO `sys_users` VALUES (122, '53ccab4f-6d79-42bf-b167-0f2d387e48e9', 'Hhhgg Cfgg', 'khanfzab@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:18:43', 0, '2021-01-25 05:18:43', 0);
INSERT INTO `sys_users` VALUES (123, 'f34e156c-5747-43df-86c6-384e2ede3e15', 'Hhhgg Cfgg', 'khafnzab@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:19:12', 0, '2021-01-25 05:19:12', 0);
INSERT INTO `sys_users` VALUES (124, 'c12ec665-3056-4e0d-902b-aad10e4c5463', 'Lui valdez', 'zvaflffxffdfeffdffdz@fgfdfmfail.coffdfmff', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:19:48', 0, '2021-01-25 05:19:48', 0);
INSERT INTO `sys_users` VALUES (125, 'dd2db128-b96d-48de-b69b-2e49aafee46e', 'Hhhgg Cfgg', 'khanzab@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'provider', 1, 1, '0', 0, '2021-01-25 05:21:03', 0, '2021-01-25 05:21:03', 0);
INSERT INTO `sys_users` VALUES (126, 'ecf43113-0d23-40a6-bd88-5270953a0198', 'Nate Mayberry', 'Nate@gmail.com', '88af3f75f4ea723a9c2a51ba84740b9d5fffa1e2ca8fbdcb8792920894d35899', 'patient', 1, 1, '0', 0, '2021-01-25 07:07:07', 0, '2021-01-25 07:07:07', 0);
INSERT INTO `sys_users` VALUES (127, 'c82b82ae-c238-4b80-a86f-c33c61734722', 'Lui valdez', 'zvaflffxfffdfeffdffdz@fgfdfmfail.coffdfmff', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'patient', 1, 1, '0', 0, '2021-01-25 07:12:38', 0, '2021-01-25 07:12:38', 0);
INSERT INTO `sys_users` VALUES (128, 'ba64b14e-52ef-412a-a888-21a2986ad724', 'Nate Smith', 'Nates@gmail.com', 'c390f30b6a3493067e0f6b24b1e9601d08ab310110dee540f059f6eab2ddfe81', 'patient', 1, 1, '0', 0, '2021-01-25 07:17:04', 0, '2021-01-25 07:17:04', 0);
INSERT INTO `sys_users` VALUES (129, '19dc6f41-5ff6-4069-b883-c8348cb2477c', 'Lui valdez', 'zvaff', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'patient', 1, 1, '0', 0, '2021-01-25 07:43:15', 0, '2021-01-25 07:43:15', 0);
INSERT INTO `sys_users` VALUES (130, '80a2eee1-e905-48c5-8a42-95535e061afc', 'Huihjjj Jjgjfjfjf', 'Ggg@hg.jjj', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'patient', 1, 1, '0', 0, '2021-01-25 11:07:23', 0, '2021-01-25 11:07:23', 0);
INSERT INTO `sys_users` VALUES (131, '37c67527-eac4-4fab-a3a6-aec30cd218d8', 'Mark Smith', 'Mail@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'patient', 1, 1, '0', 0, '2021-01-25 12:09:39', 0, '2021-01-25 12:09:39', 0);
INSERT INTO `sys_users` VALUES (132, '767af6fd-613b-4402-b523-85e2eeca2d49', 'Mark Smith', 'g@gmail.com', '50b7d5a85479679a67836b45418804c08832be3feb8c29f816c103a06b464115', 'patient', 1, 1, '0', 0, '2021-01-25 12:10:54', 0, '2021-01-25 12:10:54', 0);
COMMIT;

-- ----------------------------
-- Table structure for sys_users_buyer
-- ----------------------------
DROP TABLE IF EXISTS `sys_users_buyer`;
CREATE TABLE `sys_users_buyer` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `lastname` varchar(255) DEFAULT NULL,
  `suffix` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `state` varchar(255) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `phone` varchar(11) DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `deleted` int(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ----------------------------
-- Records of sys_users_buyer
-- ----------------------------
BEGIN;
COMMIT;

-- ----------------------------
-- Table structure for sys_users_clinic
-- ----------------------------
DROP TABLE IF EXISTS `sys_users_clinic`;
CREATE TABLE `sys_users_clinic` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `ein` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `state` varchar(255) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `created` datetime(6) DEFAULT NULL,
  `deleted` int(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Records of sys_users_clinic
-- ----------------------------
BEGIN;
INSERT INTO `sys_users_clinic` VALUES (1, 96, 'Soa', 'Hhhh', 'Ccgg', 'City', 'Georgia', 'US', NULL, 0);
COMMIT;

-- ----------------------------
-- Table structure for sys_users_doctor
-- ----------------------------
DROP TABLE IF EXISTS `sys_users_doctor`;
CREATE TABLE `sys_users_doctor` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `lastname` varchar(255) DEFAULT NULL,
  `suffix` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `state` varchar(255) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `phone` varchar(11) DEFAULT NULL,
  `created` datetime(6) DEFAULT NULL,
  `administrator` int(1) DEFAULT 0,
  `dob` date DEFAULT NULL,
  `credentials` enum('PRACTICIONER','REGISTERED','ESTHETICIAN','PHYSICIAN ASSISTANT') DEFAULT NULL,
  `i1099` varchar(255) DEFAULT '',
  `i9` varchar(255) DEFAULT '',
  `signature` varchar(255) DEFAULT NULL,
  `deleted` int(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Records of sys_users_doctor
-- ----------------------------
BEGIN;
INSERT INTO `sys_users_doctor` VALUES (1, 120, 'Lui', 'valdez', 'mr', 'street', 'city', 'Kansas', 'US', '555f', '2021-01-25 05:18:01.490768', 0, '2021-01-25', 'ESTHETICIAN', NULL, NULL, NULL, 0);
INSERT INTO `sys_users_doctor` VALUES (2, 121, 'Lui', 'valdez', 'mr', 'street', 'city', 'Kansas', 'US', '555f', '2021-01-25 05:18:15.457995', 0, '2021-01-25', 'ESTHETICIAN', NULL, NULL, NULL, 0);
INSERT INTO `sys_users_doctor` VALUES (3, 125, 'Hhhgg', 'Cfgg', 'Cc', 'Vbvbb', 'Vbbv', 'Georgia', 'US', '36255', '2021-01-25 05:21:03.436702', 0, '2021-01-25', 'PRACTICIONER', '', '', 'Lllll', 0);
COMMIT;

-- ----------------------------
-- Table structure for sys_users_doctor_licences
-- ----------------------------
DROP TABLE IF EXISTS `sys_users_doctor_licences`;
CREATE TABLE `sys_users_doctor_licences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `type` enum('DOCTOR','NURSE PRACTICIONER') DEFAULT NULL,
  `number` varchar(255) DEFAULT NULL,
  `state` varchar(30) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `exp_date` date DEFAULT NULL,
  `front` int(10) DEFAULT NULL,
  `back` int(10) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ----------------------------
-- Records of sys_users_doctor_licences
-- ----------------------------
BEGIN;
COMMIT;

-- ----------------------------
-- Table structure for sys_users_patient
-- ----------------------------
DROP TABLE IF EXISTS `sys_users_patient`;
CREATE TABLE `sys_users_patient` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `lastname` varchar(255) DEFAULT NULL,
  `suffix` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `state` varchar(255) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `phone` varchar(11) DEFAULT NULL,
  `weight` double DEFAULT NULL,
  `current_medications` text DEFAULT NULL,
  `known_allergies` text DEFAULT NULL,
  `known_mconditions` text DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `deleted` int(1) DEFAULT 0,
  PRIMARY KEY (`id`,`user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Records of sys_users_patient
-- ----------------------------
BEGIN;
INSERT INTO `sys_users_patient` VALUES (1, 74, 'Luis ', 'Valdez', '', '', '', '3', 'US', '', NULL, '', '', '', NULL, '2021-01-11 17:07:20', 0);
INSERT INTO `sys_users_patient` VALUES (2, 75, 'Luis', 'Valdez', 'Mr', 'Street', 'City', NULL, 'US', '55555555', 200, 'Med', 'All', 'Con', NULL, '2021-01-25 01:51:47', 0);
INSERT INTO `sys_users_patient` VALUES (3, 76, 'Luis Valdez', 'Gggggg', 'Mr', 'Add', 'City', NULL, 'US', '222222', 200, 'Med', 'All', 'Cond', '2021-01-25', '2021-01-25 02:00:58', 0);
INSERT INTO `sys_users_patient` VALUES (4, 77, 'Lui', 'valdez', '', '', '', NULL, 'US', '', NULL, '', '', '', NULL, '2021-01-25 02:04:05', 0);
INSERT INTO `sys_users_patient` VALUES (5, 78, 'Lui', 'valdez', '', '', '', NULL, 'US', '', NULL, '', '', '', NULL, '2021-01-25 02:06:01', 0);
INSERT INTO `sys_users_patient` VALUES (6, 79, 'Luis Valdez', 'Gggggg', 'Mr', 'Add', 'City', NULL, 'US', '222222', 200, 'Med', 'All', 'Cond', NULL, '2021-01-25 02:15:10', 0);
INSERT INTO `sys_users_patient` VALUES (7, 80, 'Luis Valdez', 'Gggggg', 'Mr', 'Add', 'City', NULL, 'US', '222222', 200, 'Med', 'All', 'Cond', '2016-01-25', '2021-01-25 02:17:48', 0);
INSERT INTO `sys_users_patient` VALUES (8, 128, 'Nate', 'Smith', 'Mr', '12 street', 'City', 'Louisiana', 'US', '52543166458', 180, 'My medications', 'Allergies', 'Medical conditions', '1998-03-01', '2021-01-25 07:17:04', 0);
INSERT INTO `sys_users_patient` VALUES (9, 129, 'Lui', 'valdez', '', '', '', 'Kansas', 'US', '', NULL, '', '', '', '2021-01-25', '2021-01-25 07:43:16', 0);
INSERT INTO `sys_users_patient` VALUES (10, 130, 'Huihjjj', 'Jjgjfjfjf', 'Jc', 'Hjjj', 'Bbjb', 'Delaware', 'US', '5464545787', 200, 'Jcjcjx', 'Ucufjx', 'Ugufuf', '2010-02-25', '2021-01-25 11:07:23', 0);
INSERT INTO `sys_users_patient` VALUES (11, 131, 'Mark', 'Smith', 'Mr', '12 Street', 'City', 'Connecticut', 'US', '123456789', 100, 'Med', 'All', 'Cond', '2021-01-25', '2021-01-25 12:09:39', 0);
INSERT INTO `sys_users_patient` VALUES (12, 132, 'Mark', 'Smith', 'Jjj', 'Nbjh', 'Cury', 'Connecticut', 'US', '6666666', 99999, 'Njjjjjj', 'Jjjjjj', 'Bjjhjj', '2014-01-25', '2021-01-25 12:10:54', 0);
COMMIT;

-- ----------------------------
-- Table structure for sys_usuarios
-- ----------------------------
DROP TABLE IF EXISTS `sys_usuarios`;
CREATE TABLE `sys_usuarios` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `nombre_completo` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `username` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `password2` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8_unicode_ci DEFAULT '',
  `rol_id` int(11) NOT NULL,
  `active` int(1) NOT NULL,
  `confirmado` int(1) NOT NULL,
  `confirmacion` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `dependencia_id` int(11) NOT NULL,
  `deleted` int(1) NOT NULL,
  `created` datetime NOT NULL,
  `createdby` int(11) NOT NULL,
  `modified` datetime NOT NULL,
  `modifiedby` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`) USING BTREE,
  KEY `deleted` (`deleted`) USING BTREE,
  KEY `username` (`username`) USING BTREE,
  KEY `password` (`password2`) USING BTREE,
  KEY `active` (`active`) USING BTREE,
  KEY `confirmado` (`confirmado`) USING BTREE,
  KEY `rol_id` (`rol_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=69 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ----------------------------
-- Records of sys_usuarios
-- ----------------------------
BEGIN;
INSERT INTO `sys_usuarios` VALUES (1, '56ef4f23b8f988.37385161', 'Master', 'master', '', '$2y$10$jOx/QSSNE9195kcZ/SdWe.bLBcRb1usVYVGPM/.ZAD0V/jYDdB0Jq', '20a7fc8c093d9e7fbe476d2ad481f8ae', '123', 1, 1, 1, '', 0, 0, '2012-07-21 16:33:54', 0, '2019-04-17 09:44:14', 1);
COMMIT;

-- ----------------------------
-- Table structure for sys_zoom_tokens
-- ----------------------------
DROP TABLE IF EXISTS `sys_zoom_tokens`;
CREATE TABLE `sys_zoom_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token` text DEFAULT NULL,
  `refresh_token` text DEFAULT NULL,
  `expires_in` int(255) DEFAULT NULL,
  `created` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Records of sys_zoom_tokens
-- ----------------------------
BEGIN;
INSERT INTO `sys_zoom_tokens` VALUES (1, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI4ZWU0ZDc5YS00N2I5LTQ4MDMtYTYyMi0xYTE2ZmUwYjQ0NzEifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiIwSFFrUEdrUFdNX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjowLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwNzk1Mjk4LCJleHAiOjE2MTA3OTg4OTgsImlhdCI6MTYxMDc5NTI5OCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImE4N2Y5ODY2LTAzYzctNDkwMi04ZTQxLThmODkwYTJhNDNkMyJ9.pEUZJpu_ls8r7Q_ofopUOflFFmMvXkiapqn1aRvGZl5u9oUMTo5N2sEDkCM1AWlkR8Gx_32oZWnMFWEXjpCSDA', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJjMzA2MGMyYy00MzgyLTQxNjctODg0NS0xZGEyMzg3MDg3YzIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiIwSFFrUEdrUFdNX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjowLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwNzk1Mjk4LCJleHAiOjIwODM4MzUyOTgsImlhdCI6MTYxMDc5NTI5OCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjgzNDQ1NzE4LWRlZDEtNGZkMy04NGIyLTZlOTI4OTQ3MDFkOSJ9.r58KxuBgfAbv7AARa80ODAVbpcIutM02akUfn1BG9c1BGM9WAN338mGBKJGkLhZ1zThnSUnoaW33T6vKyzGKyQ', 3599, NULL);
INSERT INTO `sys_zoom_tokens` VALUES (2, NULL, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJhNDY3Mzc1NC1lNzc4LTQ5MGMtYjIzNi1mYmI1Njk2ZTVlMDQifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJ6N3NPNzVQUWJ6X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjowLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwNzk2NjYwLCJleHAiOjIwODM4MzY2NjAsImlhdCI6MTYxMDc5NjY2MCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjFlZDA4ZDAzLWNhNWMtNDU5Yi04MzY3LWYwM2QxYTY4YTQ0OSJ9.bDFj9jpuC1LpO4pdiNMNtQtH3bWLmoT2Je4wUenvmKpPszdSBFFTd8ZX5BwpUUvGdD6q6eZsrLNIc4LEqGmw2w', NULL, NULL);
INSERT INTO `sys_zoom_tokens` VALUES (3, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJmMDQzYTJlMC1iYjlmLTQ2NGYtOTgxMS1mZGYzMTJmM2I2YTcifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJ6N3NPNzVQUWJ6X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwNzk2Njg4LCJleHAiOjE2MTA4MDAyODgsImlhdCI6MTYxMDc5NjY4OCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjcxMmI2NDQyLTY0NDYtNDRhNC04YzViLWY3ZTY3MWI5MGQ1OSJ9.iSNG__afzVwI7xpO_9wY3_OShpRpbQ-ggR1FakR1vYhyAAMFWCMdm9icyrOVWceoA8lmY4SSVep_05PjP2-_iw', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI3MmMwYmExNi02OGEyLTRkYzAtOGEwNC01N2Q1ZmI5MTQyZWIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJ6N3NPNzVQUWJ6X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwNzk2Njg4LCJleHAiOjIwODM4MzY2ODgsImlhdCI6MTYxMDc5NjY4OCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjY4NmM1ZjZlLThlYjItNGZjZS04YTc3LTAyMTVhNDZjZDk2ZiJ9.hW8R91UBrBW2FS5-_vGLK91zsGI40xk45qEQItyRc-mAG4bCE9HPrv-w4bgFmV-BXxKme5gmoPDMv_KZIqlwfg', NULL, NULL);
INSERT INTO `sys_zoom_tokens` VALUES (4, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJmMDQzYTJlMC1iYjlmLTQ2NGYtOTgxMS1mZGYzMTJmM2I2YTcifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJ6N3NPNzVQUWJ6X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwNzk2Njg4LCJleHAiOjE2MTA4MDAyODgsImlhdCI6MTYxMDc5NjY4OCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjcxMmI2NDQyLTY0NDYtNDRhNC04YzViLWY3ZTY3MWI5MGQ1OSJ9.iSNG__afzVwI7xpO_9wY3_OShpRpbQ-ggR1FakR1vYhyAAMFWCMdm9icyrOVWceoA8lmY4SSVep_05PjP2-_iw', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI3MmMwYmExNi02OGEyLTRkYzAtOGEwNC01N2Q1ZmI5MTQyZWIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJ6N3NPNzVQUWJ6X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwNzk2Njg4LCJleHAiOjIwODM4MzY2ODgsImlhdCI6MTYxMDc5NjY4OCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjY4NmM1ZjZlLThlYjItNGZjZS04YTc3LTAyMTVhNDZjZDk2ZiJ9.hW8R91UBrBW2FS5-_vGLK91zsGI40xk45qEQItyRc-mAG4bCE9HPrv-w4bgFmV-BXxKme5gmoPDMv_KZIqlwfg', 3599, NULL);
INSERT INTO `sys_zoom_tokens` VALUES (5, NULL, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIzMjNhMTRiZS0wYTU3LTQ1ZmYtYjgzMS1lN2ViMzdiZWRiYWEifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiIyVk4zOVRkc0swX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjowLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwNzk3NjIxLCJleHAiOjIwODM4Mzc2MjEsImlhdCI6MTYxMDc5NzYyMSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImE4YTA3ZjY5LTcwYzQtNGVhOS05ZTAzLTAxMTNlMTc4ZDNiMiJ9.1w10mfPnOE6bwQIIjaNuYWhOVw4voIHsnSV6D3AdAI0RMRMX0dkzkUpnmn8Afn9Hah9i-RhnEdNrHlPrsoMs-g', NULL, NULL);
INSERT INTO `sys_zoom_tokens` VALUES (6, NULL, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI3N2RjMDgyMi0zMTgyLTQwYzYtODMyZi0yYzBmMTBlMTk2YTcifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiIyVk4zOVRkc0swX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwNzk3Njc1LCJleHAiOjIwODM4Mzc2NzUsImlhdCI6MTYxMDc5NzY3NSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjIxNzU3ZDE1LWQzNWItNDVlYi04NzVlLTg0YjIzMGM2YjU4YSJ9.D6HgaeL_adiUZhkQDqAIYSaoM5DrIhcjmJXiisqDfkxd-BouEJPR8-uM6G_KGSTigakuisqMubYkE76krCtHjg', NULL, NULL);
INSERT INTO `sys_zoom_tokens` VALUES (7, NULL, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJjNmM5OWJiNy00NzRhLTRlNWItODBhNy1kMTA4MGFiN2Q3MDEifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjowLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwNzk5MjQyLCJleHAiOjIwODM4MzkyNDIsImlhdCI6MTYxMDc5OTI0MiwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjBlOGQ0NTE3LTEyZDYtNDYxNi04ZmI2LThhNmY4YzkwMDAyOSJ9.UK3Kc1V2LybptJHjjH2wLqkXSVjUnY4vX3sX89ZFKHAmqlduGHJPdu5ikO5Y8kIZRmyhOCCJs06uv7AWtfNb-g', NULL, NULL);
INSERT INTO `sys_zoom_tokens` VALUES (8, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI0YjRkYWJjMi0xNjRkLTQ4MDAtYmZlOC1hYzQwY2VjZDc5ZmIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwNzk5MjczLCJleHAiOjE2MTA4MDI4NzMsImlhdCI6MTYxMDc5OTI3MywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImFjYjM3YWQ3LWY2ZjUtNDIzZi1iMzU0LTkxMmM0YTA5Y2Q4YyJ9.sie23BGzH4vraSGR9JrJ1m73_4-JZwnigQU6lHIZtkH6qLRcyirxAAU8U1S-jx9AsJ91s1xBf4ZMc2oBjoAu4w', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJjMzlmNjYwNC0xODU2LTQyM2YtYjkxZi02ZDVjZWIwM2VmNDcifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwNzk5MjczLCJleHAiOjIwODM4MzkyNzMsImlhdCI6MTYxMDc5OTI3MywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImRkYWIzOGJhLTIzNzEtNDU3Yy1iOGRlLTZkYzY5OTA4M2QxZiJ9.RyPdZkYpHzor16krIMNNSwoDVKs76woYMFNf2m6NVlg6Sj4LZDbYDdpH_OgwmTIFtYtuNxGLsKCJK8glg40N9g', 3599, '2021-01-16 06:14:32.082649');
INSERT INTO `sys_zoom_tokens` VALUES (9, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJiZDdmYzM2MC04ZDVjLTQ0ZTEtODhhZC03ZWQyZGY0NGVjMTMifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoyLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAwNDA1LCJleHAiOjE2MTA4MDQwMDUsImlhdCI6MTYxMDgwMDQwNSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImJhZjQxNmQ0LWI3NmItNDM4Mi1hMGFmLTEyZWMxM2M4Y2JmZCJ9.kZnxsQ40P14vDaYggn4_R5jKsoud2yaXiGPZu-7wT7XuEgGa3rO4-aiJxxn5yiNk4jWITmX7Hw9L7ra2aj29Tw', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIxNTQ0MTU4Ni1jZWIzLTRmZDMtYjU5ZC0wZjJiZmQ2Zjg0ZTEifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoyLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAwNDA1LCJleHAiOjIwODM4NDA0MDUsImlhdCI6MTYxMDgwMDQwNSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImMyNDcwMTk0LTY2NDgtNDE3Ny1hNTg1LTIwYTZiZDBhOWNkNCJ9.Nj3b8QAbglmHwSzx19MIwEG-do21hOorau6QXwO6r62U79UxddB_yqNrvDAYjjHMB7dmk61pamcBna87dqJ8nA', 3599, '2021-01-16 06:33:24.205342');
INSERT INTO `sys_zoom_tokens` VALUES (10, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI1M2YxZTY5OS1jNDQyLTQ3MzQtYjU0OS0zZTZmMTcwNDBiMTUifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjozLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAwNTI1LCJleHAiOjE2MTA4MDQxMjUsImlhdCI6MTYxMDgwMDUyNSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjQ4NmI4YWYyLThkM2MtNDQ1Yy05YzQ4LTA2ODE0MDY1MWM0ZSJ9.hAbcTkl20FtwOGDnKQWvwK6M_A91rXt45Rkn-OqtXH9ziMle-RVGnz_0a0xRcy5CQTk5TRRNMzqqLMujNjuA3Q', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIzY2E5MGRiMC1lNDBmLTRmZDctODYyZi0zN2Y1NzcyYTkzMTkifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjozLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAwNTI1LCJleHAiOjIwODM4NDA1MjUsImlhdCI6MTYxMDgwMDUyNSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjA1YmUzMDQ3LWI3Y2UtNDg3MC1hZDI5LTViOGE2Y2ZjNTBmMSJ9.-IYWJYmrdS-q3WRUQxzG6L-zJdEo6du4n-JMFEizHcRJhzXts4HSALckK730fpHDqcdJjpV83GOvO4HrYrQMtA', 3599, '2021-01-16 06:35:23.920075');
INSERT INTO `sys_zoom_tokens` VALUES (11, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIyYjMzZjQ4YS02YTMzLTQwODMtOTQwOS04NmFkYWY5MGEwNzEifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo0LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAwOTE4LCJleHAiOjE2MTA4MDQ1MTgsImlhdCI6MTYxMDgwMDkxOCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImY3ZjkwNGE5LTdjZTMtNGFlYy04NmUyLTI0YjY3ODQ2YzBjMiJ9.OerQ4MqurlbA2mk_cPjwOG2c6lJt5ynSyVHVOpJ-9kIm_rLBs8SQ5IXxwSR8zIJPqM8hPEoX7Xb8aGIt1dekJQ', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI4NzgwMmFhYi05MWUwLTQyM2ItOTZhNC0wYTg5Y2Y0Njg1MzkifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo0LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAwOTE4LCJleHAiOjIwODM4NDA5MTgsImlhdCI6MTYxMDgwMDkxOCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjJjNmY5MzRiLTUzODgtNGY5MC1hNThkLTAwMDk4ZmEwODNmMSJ9.Lq1hZCwm-uKWl9eZ0EULQ_yXYZ3uI5tna9_5_RQwtCN7-cckYtJCGkmLuKphPvmYBuMM4BhotaFH3aXQrTUfMw', 3599, '2021-01-16 06:41:57.175599');
INSERT INTO `sys_zoom_tokens` VALUES (12, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIyNWU0NmNmYS05ODllLTRjYzktODJhYS04MjM3OTkxMTg3MjIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo1LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAwOTQyLCJleHAiOjE2MTA4MDQ1NDIsImlhdCI6MTYxMDgwMDk0MiwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImE3YmViOGJhLTNjMGEtNGViYy1iZjM0LTBhZTkzOWJlYjFkOCJ9.5atm0qTRp2l_4D5jAYeCnB162hl2sLd-AQCsfGtp_WauK9ySAugXE8tPBNX9YEdB9eF7THPKr5798XKpV0nzCQ', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI2M2EwYTRlOC1iMzFjLTQ2YmQtYTc4Mi1lMWM3NjFkODFlY2YifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo1LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAwOTQyLCJleHAiOjIwODM4NDA5NDIsImlhdCI6MTYxMDgwMDk0MiwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjVjZTJiODM1LTJmYzctNGVhZi1iYzk5LTM4MjY2MTVkYTBhZSJ9.tGH6gxnPQ4_UdSjA2_4DKjGO_2L_WiP5clay2DxOqGPFh17eeyqnRyfDl_cZtR-XehKgpP9zOA3pt-gDbN68Ow', 3599, '2021-01-16 06:42:21.167690');
INSERT INTO `sys_zoom_tokens` VALUES (13, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI5NjlmZWEzYi0xZjA0LTQ3ZjMtOGRlNi1iY2Y1YjBmYjVmZDUifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo2LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAwOTYyLCJleHAiOjE2MTA4MDQ1NjIsImlhdCI6MTYxMDgwMDk2MiwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImNiOGJiM2FjLTc4OGYtNDkyOS05YjQ5LTdhOWRmYTkwODY0ZCJ9.o6rFL7DFnm9SOmZIPed4Su7WJSXlZE70A5ZdaLaU0AY1kUepqU2IQIVI59eiJXvrdZqjKPaznWN5b898I8qK7g', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI2M2MwZDdkNy0yYmM0LTQxOWQtODNhOC1kNTA3NjhhMjJjZGIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo2LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAwOTYyLCJleHAiOjIwODM4NDA5NjIsImlhdCI6MTYxMDgwMDk2MiwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjBjZGExN2NkLTQwNWMtNGQzZi1hMmZkLWViYWFhMTIyYjUyZCJ9.3p_UOgiqvf7Tspci5W-U-_akKi2NmWGtRU0eFO0AvspDwnX8A4K12nsPUttx9f_7ezQk5l3olwJcG-Zk6EKP_Q', 3599, '2021-01-16 06:42:41.153323');
INSERT INTO `sys_zoom_tokens` VALUES (14, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIxZWQ4YmYwNC01YzA1LTRhZmMtYTA0MC04ZTcxZDQ5NzIwMmQifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo3LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAwOTkzLCJleHAiOjE2MTA4MDQ1OTMsImlhdCI6MTYxMDgwMDk5MywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImZhOWMzOTVmLWU2MjAtNDVkMy1hMGJkLWVhM2M0MTNkMGMyNyJ9.WSImHhOfR2lYpGGmMnU0PgzFXSg4mmoNCkbsm7fR6aeu2ClD9gEFKD4OG9vBLKb3fNHv7RvfzE-XuCeUx2bSlw', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI0ZGE4NWZjNi0zY2FkLTRjY2EtYjFkMi01YjA0NzAzZDgyMjUifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo3LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAwOTkzLCJleHAiOjIwODM4NDA5OTMsImlhdCI6MTYxMDgwMDk5MywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImE3NDg3NWZkLWUxN2EtNDJiMi1iOWQyLWM3OWNjNzMyOTgyYSJ9.YGLwXGKTm3QL0j49Ny_fPhc7DvHxFKqQUCFHwgjyYWuzInZfduVCHjnHUlV3wobohWqdbFv2xl47-kiVs6946Q', 3599, '2021-01-16 06:43:11.932483');
INSERT INTO `sys_zoom_tokens` VALUES (15, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJhYWE2ZWIzMi04YmRkLTRhMmQtYjZjZS03ZjM4Y2Y4ZmExNzIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo4LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxMDE5LCJleHAiOjE2MTA4MDQ2MTksImlhdCI6MTYxMDgwMTAxOSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImNjNmM1MGI1LTE3ZTEtNGJjMS04NjZhLWRmYjQyNDgyZTU1OSJ9.Go_rlM4cIqkzeqr4tAUp-ns7QV5XYHJCi6icpEMThbk1K6V3kg4Pqb6R5T1Ucye30OuRDINxfFr8AW-XXddrOg', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJjNWIwZTAyNS1iZDQ4LTQ3M2EtOGY1OS1hZDY2YjU1NjVmYTIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo4LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxMDE5LCJleHAiOjIwODM4NDEwMTksImlhdCI6MTYxMDgwMTAxOSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImY4YjcxY2I1LWVlZWMtNDlhMy04YThkLTc0MTNiZDMyMWQzZSJ9.yoJhDn7n21JDPJtODbVS1mW8yX9rExbzN2-xHJLRSqr2Fcu2KCw8LNbk4uQA7lKHjp9LKE1br7JmRPEywhqyug', 3599, '2021-01-16 06:43:38.678614');
INSERT INTO `sys_zoom_tokens` VALUES (16, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI5ZjQ5Y2YyYy03Yzk5LTQyMWItYTI0OC1lODhjYjE1MWVlOGUifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo5LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxMDc1LCJleHAiOjE2MTA4MDQ2NzUsImlhdCI6MTYxMDgwMTA3NSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjVmODNlNTczLWE3OWYtNDkxYS05ZjBlLTBmMmE5ZDFhNTE0NiJ9.8VwODBZf58p2Wcxv5SttMNOmOthwtfj7umADstnf02MtSt1TmwUyxyjUKJfYi-KXLyZWQqtGCIwwkTZzTRD2ng', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJlNmM3MTZlYi01MzVhLTQwZjItODM4Mi0yMTZhYmFiMzcwMTIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo5LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxMDc1LCJleHAiOjIwODM4NDEwNzUsImlhdCI6MTYxMDgwMTA3NSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6Ijc5NzA2ZWRjLTI3ZGUtNDI5NC04YmY1LWI5NGVmYjJlYTgxNSJ9.bt7qy5fjqghDj87ilY3pSFh5Jg_Ks0Iuxao9f8_sASSHf7R-c8DNP6EWcOTz9BOtA0DMlWon4g-YWOxIwKadJQ', 3599, '2021-01-16 06:44:34.031463');
INSERT INTO `sys_zoom_tokens` VALUES (17, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJhODUyMDE4My0zNjgzLTRkN2MtYTY1NS00MTcyZjlhYzgxYjUifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoxMCwiYXVkIjoiaHR0cHM6Ly9vYXV0aC56b29tLnVzIiwidWlkIjoid1Y4UnlSWnRSaENSTFFJSWNMcUF0USIsIm5iZiI6MTYxMDgwMTA5NywiZXhwIjoxNjEwODA0Njk3LCJpYXQiOjE2MTA4MDEwOTcsImFpZCI6Imhhd3FKRVJ5UmVXMVl6Q3k3WmVMZGciLCJqdGkiOiJhOGQ5NjFlZi04NmZjLTRmZTItOThhNy1hMjkwMDYxNGEwOWMifQ.XCDNq4BE-ClSOHkv-vJ3ncYta6aYn_pGDWhf2DYRXz_qMmf-QXMuL9OmIv4g8_lqkuF0_wlTv5Khiil9buvzbg', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJiYTRlMmYwOS0xMDYzLTRlNDUtOWU1OS1lYzk2NjQ2ZDEzZWYifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoxMCwiYXVkIjoiaHR0cHM6Ly9vYXV0aC56b29tLnVzIiwidWlkIjoid1Y4UnlSWnRSaENSTFFJSWNMcUF0USIsIm5iZiI6MTYxMDgwMTA5NywiZXhwIjoyMDgzODQxMDk3LCJpYXQiOjE2MTA4MDEwOTcsImFpZCI6Imhhd3FKRVJ5UmVXMVl6Q3k3WmVMZGciLCJqdGkiOiI3Yzk3M2YyOS1iZmZmLTRlNDQtYjBmMS0zOTk1MzVjODk2MjcifQ.Etx9voovnCzF-iSdAOBnqHbby3JYYMv21eBCZpeieye1hCOzzB9HmoQAY4Kr9OBMvgvv0IrpBPNls9Dx_3rjEQ', 3599, '2021-01-16 06:44:56.222745');
INSERT INTO `sys_zoom_tokens` VALUES (18, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIyZmYyODAxNy0yMGVhLTQ0Y2ItODZkZi02M2Y1ZTRkZjI2OTMifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoxMSwiYXVkIjoiaHR0cHM6Ly9vYXV0aC56b29tLnVzIiwidWlkIjoid1Y4UnlSWnRSaENSTFFJSWNMcUF0USIsIm5iZiI6MTYxMDgwMTExMywiZXhwIjoxNjEwODA0NzEzLCJpYXQiOjE2MTA4MDExMTMsImFpZCI6Imhhd3FKRVJ5UmVXMVl6Q3k3WmVMZGciLCJqdGkiOiI3NThjODI0Ny0yM2Q1LTQ1ZDItYjc3NS01NWI1ZWVkMmVhMmQifQ.BFgumxGNBDVK9NB8IgFvAvBeTdWAYeHQi9-LGZjPVJo3UL3LLAzq-iZX5Ls4uJCSAvseDKNRoHNF7pXhYf41Bw', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJmYzBhNzA3OC01NzdlLTRkZTctOWFlNi1kODUwM2Y3ZmJkMjIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoxMSwiYXVkIjoiaHR0cHM6Ly9vYXV0aC56b29tLnVzIiwidWlkIjoid1Y4UnlSWnRSaENSTFFJSWNMcUF0USIsIm5iZiI6MTYxMDgwMTExMywiZXhwIjoyMDgzODQxMTEzLCJpYXQiOjE2MTA4MDExMTMsImFpZCI6Imhhd3FKRVJ5UmVXMVl6Q3k3WmVMZGciLCJqdGkiOiJhOTE3NWM0My03NThhLTQ4YmYtYmRiNi1lNDU1MTU3ZGUzNjQifQ.f2nkcpHiTjl7KCz5txG8bLQg1D1sheptwzGK2uOx8dYeo8cx_37I_C32Uog76BS6iydFzNhBpb-glOVJuwRyDw', 3599, '2021-01-16 06:45:12.782249');
INSERT INTO `sys_zoom_tokens` VALUES (19, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIxNzNmMWJhMS03MWQxLTRhMjktYTBiNC1mMDc4ZmI3NDAxMmUifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJITVdjWUV2ZXBTX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoxMiwiYXVkIjoiaHR0cHM6Ly9vYXV0aC56b29tLnVzIiwidWlkIjoid1Y4UnlSWnRSaENSTFFJSWNMcUF0USIsIm5iZiI6MTYxMDgwMTMzOCwiZXhwIjoxNjEwODA0OTM4LCJpYXQiOjE2MTA4MDEzMzgsImFpZCI6Imhhd3FKRVJ5UmVXMVl6Q3k3WmVMZGciLCJqdGkiOiI0MWNkN2FiNi05ZmU4LTQwZWItOGVlMC01MGI5ZmFjZmI3NWQifQ.t61IrrrxD2_6MTBlc2Ysyrv0mUPm5cB5yxwGpt728UmqdMcObvYovUADxNnSKoG5lCqEA9sKzNvOERWSKihSRw', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJiMmVkZWYzMC1jNjBmLTQ1MDAtOGMxOS0yNmIzODg4YTJkZGQifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjowLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxMzU2LCJleHAiOjIwODM4NDEzNTYsImlhdCI6MTYxMDgwMTM1NiwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjNmNmYzYzEyLTQzNWQtNGEwYy1hYWVmLWVjMTBmMzg4YjcwNiJ9.w-Q9KkApfh7K3DBpAUIrxIQ9NNfoVzNH-3otBIHREvvpyDvkbktDTaj0868O_ddj2OM2K67ZccvEa9XmQYeDaw', 3599, '2021-01-16 06:48:57.441323');
INSERT INTO `sys_zoom_tokens` VALUES (20, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI1M2YwODI2MC1jMzM1LTQ0MTYtOGYxNy1mZGE5NDc3YzNkYTYifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxMzcyLCJleHAiOjE2MTA4MDQ5NzIsImlhdCI6MTYxMDgwMTM3MiwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjljODZjN2NmLThkZTktNDJjNi05MzI4LWZmZjc5M2RkZDk5ZiJ9.Mqhxyn95zyOtmZmdmsn3x_gFEdnFwnnPNc_2SROnaQGlwPFDtAqsxHvUAOSEjtnoQLDRW6FuKTStDIfQFbwoAw', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJiN2FhMGUzZi05MTA2LTRmYzEtYjZmZi1jMjI5MDMzNjlkNWIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxMzcyLCJleHAiOjIwODM4NDEzNzIsImlhdCI6MTYxMDgwMTM3MiwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImMyMzc2MGRkLTY2NDMtNGYzZS04N2YzLWUwODRlNDAzMWY1MyJ9._AG6YvUQNuuJ0XxnQwerBUyo-obJ5U7R8n2bAR2HsOHeqEqRgPGcwO7Ptic_qyRGy4NZ8oL-KtpF3nJT32l9VA', 3599, '2021-01-16 06:49:31.701559');
INSERT INTO `sys_zoom_tokens` VALUES (21, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI2ZmRiMDQ1NC05ZTYzLTQ4OGItYmRjMS04Y2RlODQyNGVmNGQifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoyLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNDEyLCJleHAiOjE2MTA4MDUwMTIsImlhdCI6MTYxMDgwMTQxMiwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjY5YWYxMTRmLWNlNDktNDQ4YS1iZDk1LWM3MmZhNDFjZDVjMiJ9.QigbdfbFPTLdyfinuFTjo-q_vsGDtUHjeKEIvQ6908Hzuf30AFbLFEgy3K-eW6YyjLV1wdsL0e9xshNlC2_xkg', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI3MDNkZDdiOC0zYTQxLTQ3MTktYmE5Ny0wMDU3Y2E3ZjBkOTEifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoyLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNDEyLCJleHAiOjIwODM4NDE0MTIsImlhdCI6MTYxMDgwMTQxMiwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjY3Y2I4ZjgyLTNmOTAtNGYwMS05MGMyLWIwMjRiYTk2NDA1NCJ9.Zwqx-eW-1D_cYFbJGJDqGSc-8KarqQgAuVgLKLt4gL8aA1Pgsg4_RIeSUGdGBRqkVGvSnYVlPgt23MCQyH_ppA', 3599, '2021-01-16 06:50:11.773106');
INSERT INTO `sys_zoom_tokens` VALUES (22, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJjODcwNWYxNS0xMTc5LTQxNGItOGFiZi0wZGVkZTk5N2I2MDYifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjozLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNDM5LCJleHAiOjE2MTA4MDUwMzksImlhdCI6MTYxMDgwMTQzOSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjM4M2VlMTFhLTA3YWItNDk4OC1hNzFmLWFlNDJhZGNiYjJjYyJ9.ZwnajKiNQ3vsKMmwYkSV0uGlTxfUaJpCxLSzAq2AOO5rrDamPm_jYwMaMjEMyBrnmGwXZBu38t3NlinlDL9a5A', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJmNjljNTg5Ni1hNTY1LTQ2MDItYjljNS1jZDgyMDA1MmM3ZDcifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjozLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNDM5LCJleHAiOjIwODM4NDE0MzksImlhdCI6MTYxMDgwMTQzOSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImM4NWE1ODliLTQ2YzgtNGRmYi04NGFmLWFmN2I4ZWE3YzY2ZSJ9.by1ncqJWwXqJ8oGnxp6yRRL7FSYwMFGjD9_HUSqk9eQlDuG-64NhzJUVkddWbpCLdN0x83_5M28SOLxOIdGp7A', 3599, '2021-01-16 06:50:38.126990');
INSERT INTO `sys_zoom_tokens` VALUES (23, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJiYThmZWI1MS0xYmM3LTRkNGYtOWY1Ny03ZDIxNGE1MWU2MTkifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo0LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNDQ3LCJleHAiOjE2MTA4MDUwNDcsImlhdCI6MTYxMDgwMTQ0NywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjExMTkzMzVkLWEwMWEtNDMzZi04OWFiLTg5M2M0ZGYzZjhlYyJ9.v__cRISHvAJJQU22DQb7PGYGbTmkGxbt7cd10iwPLk89CrbAuWHkHGR81jL2YrNiorslw9r2Z5jHZvS-Wh-g_A', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJhZWViNWRmNy05YjQxLTRlMTYtYjY4Yy00NzA0NjRmNjVkODYifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo0LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNDQ3LCJleHAiOjIwODM4NDE0NDcsImlhdCI6MTYxMDgwMTQ0NywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjAzYzhjYzgwLWRmNmYtNGNkOC1iZGM5LTFkZjdiMzVjOTIyNiJ9.piUlAEAQXDUYkBoV_idgHmKs1Ml5VH_aDubIT3wz7aQiYnuCWQwv_NAe86obZZj_yKHtR3Yh0jMJ7JXfXZbXiQ', 3599, '2021-01-16 06:50:46.696873');
INSERT INTO `sys_zoom_tokens` VALUES (24, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI5MWE1NWJkMy04OWQxLTRiMmEtOTQzZi1kZWEzNGEzZWRiZTcifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo1LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNDcxLCJleHAiOjE2MTA4MDUwNzEsImlhdCI6MTYxMDgwMTQ3MSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjQ3OTEzNGUzLTVlZDYtNDk0NS05ZDMxLTZmZDQ0OWExMDFhNSJ9.KqMNUoZYRRfqmqs4i_NaQiOT01hnAsTrMhcPuG8HxMNOxsUJLJkbPBMOwfV-StwflEh4kxs96kibmMxLHK6Tow', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJjZmVjOGY3OC05ZWUyLTQ2YWItOTI0Mi1mM2FlZDIyN2IxN2QifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo1LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNDcxLCJleHAiOjIwODM4NDE0NzEsImlhdCI6MTYxMDgwMTQ3MSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjI0NzQzZTY0LTc2ZDYtNGJkZS1hNWRjLWU2MmYyZWNkZTMwOSJ9.Itgfz15FrKvZ70MXsEHYmEZUuhvKyTmx32a3tVXei3HJGp1aXTSECtw9GzTXB0ed9v_AUqZq-AmbogHUNGmbsw', 3599, '2021-01-16 06:51:10.033102');
INSERT INTO `sys_zoom_tokens` VALUES (25, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIzZWNiY2NkMi0zODc2LTQ4MjQtODA4Ni05MDAwNWZhNTlmNTUifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo2LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNDkwLCJleHAiOjE2MTA4MDUwOTAsImlhdCI6MTYxMDgwMTQ5MCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImQyZWIyOWFjLWZiY2YtNDlkYS05Nzg4LTBkOGEzOGY0MTEyNiJ9.ekAfiwvVTBURO3sFHlMgq_zZgxUihi1ibww3tUF6f9tiLQZs5xln1hW04es-RsG1e8gB4sXykxBMZNB9s9Jb5w', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJiN2ExMGFmZS0yOTA0LTQ0YzAtOGI1Ni1iNWExOWE2OTQ0MmYifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo2LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNDkwLCJleHAiOjIwODM4NDE0OTAsImlhdCI6MTYxMDgwMTQ5MCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImVjZWIwZGQ3LTgxYWMtNDM0Zi1hNzI4LTgyNjBlZTU1ZDMyMyJ9.H6Bm5ev2k6Al5N2AH6U7B5Vpzumc1SU32U4QCwgsHsm-th1EbUVimPV1BmT9uvvBHoFJZlmaVElNoVS5ZVJp5A', 3599, '2021-01-16 06:51:29.688336');
INSERT INTO `sys_zoom_tokens` VALUES (26, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJkZGQ2NDFhOS00MzRmLTQyMGQtODkwMi1kNDUyYjllN2I4NDMifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo3LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNTEwLCJleHAiOjE2MTA4MDUxMTAsImlhdCI6MTYxMDgwMTUxMCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjA3MmNiN2U1LWFjMmQtNDBiMi1iMmJjLTk0NjIxZDc4YWIxNCJ9.BQdyaJh1tm-40yM5--NmUT_ZHvrfXIMDuLF7yV7lRLIJQE_oRpHwbkVdsp4bX9YszkovVSjh5ypmxGI3znmvsA', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJhMTk5YTFiMC05ZjQ2LTQyMzEtYjMxMC1kZWMzOTVkMWU1ZTUifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo3LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNTEwLCJleHAiOjIwODM4NDE1MTAsImlhdCI6MTYxMDgwMTUxMCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImJlYThkMDM3LTFmYzItNDUzYS05NWYzLTNkYzU4ODBiMDg0MSJ9.zCfwx8uJH8TnbsXfLkx5Nm0FJaZDKgL7KqskWvhbs6dhcd69F2WrCcaRQOZl5P-ZNMvrBulYVjo_92fRTizdIQ', 3599, '2021-01-16 06:51:49.166182');
INSERT INTO `sys_zoom_tokens` VALUES (27, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJjYzkwOGU5MC1mNDdjLTQyMTYtYjZiYS00YmFkYmNmYWY0OTgifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo4LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNTY3LCJleHAiOjE2MTA4MDUxNjcsImlhdCI6MTYxMDgwMTU2NywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjJiOGQxMzJlLTNhY2UtNGRkZC04MWI1LWY4MDFhOGUxYzk0ZiJ9.Cux8EnGyPTXEdohhgtdflnttirNFxPI5c9aIHT30HJcZNq1bXSqHo2Iyb7WzoOidwOQvJZ-KZJYwDFbxfb52hA', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI4MzIzZWYxYS03ZGM3LTQxNGItODI0NS04MzU5NTY4YThiNTkifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo4LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNTY3LCJleHAiOjIwODM4NDE1NjcsImlhdCI6MTYxMDgwMTU2NywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjE2ZDQ1YzRlLTE0NmMtNGE3My04NjU0LTNmZWJkMjUxMTM3YiJ9.3pYqXkuzESRe5tD2NmnZLVybVz1TQ2FfF3fKYzEbgNULljSqG4o-L9WYF0PEiCtrlnqC-9mflMTxejHxaRLpOQ', 3599, '2021-01-16 06:52:46.321230');
INSERT INTO `sys_zoom_tokens` VALUES (28, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI5ZGY4MWVkMS05M2QwLTQ5OWYtOGI5Zi0yNjdkYTJjYjhlYzkifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo5LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNTkzLCJleHAiOjE2MTA4MDUxOTMsImlhdCI6MTYxMDgwMTU5MywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImJhZWNkYzExLWNhNmItNDVmMC1hOWJkLTMzOGMyYTgzMzJmYiJ9.fByOxSDWaIJ-Zezew3F497sS_4kaiA09OZtg5vNzZw3StpthBCke4-iOMa5ofzHHriC3Hsk15eskjeyXSYwptA', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJlMjM2ZTc5OC1iODAzLTRlODctOTA5Ni03MWVmNjk5NTFkZjYifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo5LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwODAxNTkzLCJleHAiOjIwODM4NDE1OTMsImlhdCI6MTYxMDgwMTU5MywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjZkNzAyMTE4LTlmNGMtNDAyYS05NzZjLWI1ZTI3YzFhZTgwMCJ9.ozssLrLR98k8UWDFQKdna-pC4WGUzIIkrJETIb87nHY42GdFGQn9HYRfsjN9pYIdYp1pSWPxgSlRdwkJErld8w', 3599, '2021-01-16 06:53:12.347259');
INSERT INTO `sys_zoom_tokens` VALUES (29, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIxNjZhMmNiNC01NGEzLTRkNmUtYjBiOC04MWQ4MzNkYzgyOTgifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoxMCwiYXVkIjoiaHR0cHM6Ly9vYXV0aC56b29tLnVzIiwidWlkIjoid1Y4UnlSWnRSaENSTFFJSWNMcUF0USIsIm5iZiI6MTYxMDgwMTYxMSwiZXhwIjoxNjEwODA1MjExLCJpYXQiOjE2MTA4MDE2MTEsImFpZCI6Imhhd3FKRVJ5UmVXMVl6Q3k3WmVMZGciLCJqdGkiOiI1OGU4ZWRkMi0wZjRjLTQxNDktOWZiNS1kMjVjNzc2ZDg4ZWQifQ.wprFL5Tv-a2Pidyxp_e0-LL44gqR_zINDq9JkwBQ47pfLp3XZ2FL1AuCvOR91NJKj8v9SIQ2xrYuLXetAQKLNw', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJmMGQzYmUzMy04ZjBmLTQzZmYtYTcwNS0yNWMwN2JjYjgyZWUifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoxMCwiYXVkIjoiaHR0cHM6Ly9vYXV0aC56b29tLnVzIiwidWlkIjoid1Y4UnlSWnRSaENSTFFJSWNMcUF0USIsIm5iZiI6MTYxMDgwMTYxMSwiZXhwIjoyMDgzODQxNjExLCJpYXQiOjE2MTA4MDE2MTEsImFpZCI6Imhhd3FKRVJ5UmVXMVl6Q3k3WmVMZGciLCJqdGkiOiI1YjdkOGU3OS1hYzUyLTRmYTQtODYxOS0zYjI4YWU5NjA1OGYifQ.cDM7SSRJ1yz-WXc-7ig-t6rUnfVo0KHQwdmdS6Z8QM_HWgwaVncDQN8GgFNxocq1CzzLzriZsTMCObMF2ZpEeA', 3599, '2021-01-16 06:53:31.425358');
INSERT INTO `sys_zoom_tokens` VALUES (30, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI2MWI2ZDYxMy03ZGViLTQ4ZjgtOTRjMi01NGQ5ZmVjOTQwMDAifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoxMSwiYXVkIjoiaHR0cHM6Ly9vYXV0aC56b29tLnVzIiwidWlkIjoid1Y4UnlSWnRSaENSTFFJSWNMcUF0USIsIm5iZiI6MTYxMDgwMTczMywiZXhwIjoxNjEwODA1MzMzLCJpYXQiOjE2MTA4MDE3MzMsImFpZCI6Imhhd3FKRVJ5UmVXMVl6Q3k3WmVMZGciLCJqdGkiOiIwZGE1ZmUyZS1lOGI3LTQ3NjQtOWYxMy1jZjBkZmJlOGFkYmMifQ.WiTyHpZ18BZE7BRtKm1H4gVLXZqooyhmKWwIfI0sRl08ITa9J3Ba2sX_c7lfe72QGxenEFp3rNl_5tW6adJWAA', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI0OTgwMzA3My02OGY1LTRiNzMtYTU4My05OTQwODljMGI1Y2MifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoxMSwiYXVkIjoiaHR0cHM6Ly9vYXV0aC56b29tLnVzIiwidWlkIjoid1Y4UnlSWnRSaENSTFFJSWNMcUF0USIsIm5iZiI6MTYxMDgwMTczMywiZXhwIjoyMDgzODQxNzMzLCJpYXQiOjE2MTA4MDE3MzMsImFpZCI6Imhhd3FKRVJ5UmVXMVl6Q3k3WmVMZGciLCJqdGkiOiJjYWY3NGZlZC1iYmRhLTQ1MWItYjIzMy1kNWJmM2E4ZmZlODkifQ.eOkyMeny-2eiKONdMS55WeQ9PJ-DZDBbPXDa7weURPmZn_x0SscIwuqyj0ZIT0HsscwvPpfbK9AOPcMRteAnzA', 3599, '2021-01-16 06:55:32.270229');
INSERT INTO `sys_zoom_tokens` VALUES (31, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI0OWM1MGU0NS1hZmVmLTRkYWItOTU0ZC1kYjE4OWM2NjI4OTYifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoxMiwiYXVkIjoiaHR0cHM6Ly9vYXV0aC56b29tLnVzIiwidWlkIjoid1Y4UnlSWnRSaENSTFFJSWNMcUF0USIsIm5iZiI6MTYxMDgwMTc2NCwiZXhwIjoxNjEwODA1MzY0LCJpYXQiOjE2MTA4MDE3NjQsImFpZCI6Imhhd3FKRVJ5UmVXMVl6Q3k3WmVMZGciLCJqdGkiOiI3ZDVkMDg3OC00ZWVkLTQ5NmMtYWIxYi0zNTdkMGZkNTQxNmYifQ.2WyX50ychu__UL7OsKxFFJfn52AqPPjnVC2wuIUJTt0jm3pxaz5W_Af34060biLuQM_aBqYGdiApQDx5DTViSg', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI0NDE0OWVmZS00MmRiLTQ0Y2EtOGVlYy1iNDdiOWRhYmRkYmEifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJzQTJWdDZRaDIyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoxMiwiYXVkIjoiaHR0cHM6Ly9vYXV0aC56b29tLnVzIiwidWlkIjoid1Y4UnlSWnRSaENSTFFJSWNMcUF0USIsIm5iZiI6MTYxMDgwMTc2NCwiZXhwIjoyMDgzODQxNzY0LCJpYXQiOjE2MTA4MDE3NjQsImFpZCI6Imhhd3FKRVJ5UmVXMVl6Q3k3WmVMZGciLCJqdGkiOiI5MWY2NDcwZC05ZjM0LTQyZDItOWM0Yy02NDQyZjkzNzI5MmEifQ.evx6zklX_YpNE3ZEK6ReFueIyhi80GQM8lCN-DOzaWeENjjeJqfAQNO4pC9-ij45qFyVi6qkIZh_De-1S-wH6g', 3599, '2021-01-16 06:56:03.716762');
INSERT INTO `sys_zoom_tokens` VALUES (32, NULL, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJmMWVkYzUzMy1iODkyLTQ0NTgtYmY0My01MTExZjNmNzE0ZDIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJHQ1d4Zml3OFFyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjowLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwOTIxMDcyLCJleHAiOjIwODM5NjEwNzIsImlhdCI6MTYxMDkyMTA3MiwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjJiNjhjMDg1LWQ2ODktNDA3Zi1iZmZhLTU2YmI2NzU0YTI2YSJ9.2Pw85pPrwVXaX6ioR2Yugqj_EziK8bUgOTXvEwcpChu5bcdaFmQTnsAH56xw0Acfr9YPK9vnuzrBMZX7GYExUw', NULL, NULL);
INSERT INTO `sys_zoom_tokens` VALUES (33, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIzYmJiZjJmZC1iOTFlLTQ1NjktYjZjMS0zYjhkNTZiZWNiOGMifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJHQ1d4Zml3OFFyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwOTIxMzMzLCJleHAiOjE2MTA5MjQ5MzMsImlhdCI6MTYxMDkyMTMzMywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjI1NTVhMDVkLWJkZDYtNDQ4Yi1hOTcyLTYyMzRjNDVjM2ExYiJ9.ydg_SYimr_fuC6YHt0lxkPYqM6KNhtV071dVAe7OyVkS6Rx16_QNpltIaXgSg665IQx6gLua5kflInqu9sYJ1Q', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI2YmNjYWFmNy0zN2MxLTRjMDQtYmQyYy04ZjAyMWM4ZDJlZWMifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJHQ1d4Zml3OFFyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwOTIxMzMzLCJleHAiOjIwODM5NjEzMzMsImlhdCI6MTYxMDkyMTMzMywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImY3NDczZWRkLTQwMjgtNGM0OS04NWZjLTU5NDhlYjQ5NjVkNSJ9.Kce1VEc6mQVn9VwCluxIV6hRD7Kxz89Uwc7iDPf3JmRLlA9eDfOK7FsXRo0xReOPXlEXJUgXPVNlzf98gDrxpw', 3599, '2021-01-17 16:08:49.859364');
INSERT INTO `sys_zoom_tokens` VALUES (34, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJhM2E4NjIxNy0wYjFjLTQxNTItOWM2Zi1iM2FiNmVhYjYwNjEifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJHQ1d4Zml3OFFyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoyLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwOTIyMDgxLCJleHAiOjE2MTA5MjU2ODEsImlhdCI6MTYxMDkyMjA4MSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjE3OWUyYzI0LTI4OTQtNGY1MC1hYzgxLTkyZmZkOWY5NmJkNCJ9.im-S7JkmWxua3S7gJXPL_KY7Z-JNBm4iVaKEQO-8txmR6GLdpDSdHfrWqxQJHYqsfn-dWYqZBvYUgf_2G-KwuA', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI2MGUyN2M5Yi1kOTY4LTQ0NzYtOTE2OC1hMzEyMDQ5M2YyYWEifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJHQ1d4Zml3OFFyX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoyLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEwOTIyMDgxLCJleHAiOjIwODM5NjIwODEsImlhdCI6MTYxMDkyMjA4MSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjZlODE3OTZlLWVlYjMtNGIwMy04MTc2LWJiYmQ4MDk3ZWFhYyJ9.8_OwCihTvDXjpuRvTNhzngiEiBNavSSzyO3AQRDbsc0NtFde1Iz2vobFPyrQP-_KblgxOqV9TmPbkrBsVOkp6Q', 3599, '2021-01-17 16:21:17.145861');
INSERT INTO `sys_zoom_tokens` VALUES (35, NULL, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIyYTc0YzU5Mi1kZmEwLTRkOWYtOTExZS05Mjg1Njc3NzBlZTAifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjowLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTc4NDQ3LCJleHAiOjIwODQ2MTg0NDcsImlhdCI6MTYxMTU3ODQ0NywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImRkNmVjYmU4LWJkNTMtNDAyNy04NjFjLWIyZTRhMTJmMDA2MiJ9.S47-KdjunefQ2mIhtRz12kYNZzyE_dcMFX7zIPl4KgoIc06pNXTCS5_ZRplN9OS96WzP_gHXdX908SK1JSyllQ', NULL, NULL);
INSERT INTO `sys_zoom_tokens` VALUES (36, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI0MzkyNWZkYy02YjM3LTRjMGUtYWE3ZS01MWRkMzQ2MTMwMGQifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTc4NDc4LCJleHAiOjE2MTE1ODIwNzgsImlhdCI6MTYxMTU3ODQ3OCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjU5YTBiYzk2LWVjNDItNDMzYS1iMmI2LTZiNjE1NTkwZDdiMCJ9.HgO39EP0ZyuWmO9iylAO1Sj24-o_xkvdbwCdgTn7g0fgbX17-vcWKYXmBaSOsooOvvyGy-nm7s6xwM9oEZ8RKA', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJmODhjMGFjZC0zNzc3LTQ1YmItOThmYS1mZWU2ZDMwZjExZDMifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTc4NDc4LCJleHAiOjIwODQ2MTg0NzgsImlhdCI6MTYxMTU3ODQ3OCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImZkN2VmYWJiLTNiY2EtNGFjNS1hMDcwLTIwOTQxYzg2ZGU5OCJ9.F1gxnvbt56-j5CDNqAj-7V2e9-4-5UKy4kf7iDjIqBOfOphesWsCrNIwg2IUVgpoN1AByZRbT5xGwM4hZM-96A', 3599, '2021-01-25 06:41:18.012160');
INSERT INTO `sys_zoom_tokens` VALUES (37, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI5NDcxN2RjZS1kNzRkLTQ0MzItODc0Mi00YTg3MmUzZWVjYjMifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoyLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTc4NzQxLCJleHAiOjE2MTE1ODIzNDEsImlhdCI6MTYxMTU3ODc0MSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6Ijk3MTk5ZjI2LTgzZTMtNDcwMS05MzY3LWNiMGUwYTVjNTQ3OSJ9.Hix21WM5ee4ufISrQiT_i7MV0zI20yv7ZyVYAACCKQR655lw5v4pyISR_kmORcqhefAYL47U_TALUmyEubg3bw', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI4NjRmZTE1Mi1jNDhiLTQ4MDQtOTJkOC1lMmU2YTU4NDJiYmYifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoyLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTc4NzQxLCJleHAiOjIwODQ2MTg3NDEsImlhdCI6MTYxMTU3ODc0MSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjViNzIxZDhiLWM3Y2MtNDEwYi05M2I1LTNiY2ViMTg3ZjM1ZSJ9.1y8ZEF5jRT-ukBFlCYJwLnR_RaTZ0zhq5TxnQxTjBsDyDmi96oiP0IM3TAOjv6rziUZirv3Uo3dgQ_RX8SV5-A', 3599, '2021-01-25 06:45:41.602654');
INSERT INTO `sys_zoom_tokens` VALUES (38, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJhMmQxZGVhNy01NDQ0LTQ0MDMtOWQ5Mi1kMzg5OWY3MjYxOTAifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjozLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTc4Nzk5LCJleHAiOjE2MTE1ODIzOTksImlhdCI6MTYxMTU3ODc5OSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjAwODFmMzliLTBkZmMtNDJlYy04YjgxLWMxMzI1Mjc2Mzk4ZCJ9.dg5zyoAwj8BGQ98HBElsoq9nyA9BoUCU1lVp6cBC9vhG5zl58cgXpk7SlmJd9QD_tlWQUMCsvlT_C-LeD4WqOA', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI2ZWYyMGU2Ni0wZTcyLTRhZGMtOWUyNy01MWQ1N2MxZWQ1ZjQifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjozLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTc4Nzk5LCJleHAiOjIwODQ2MTg3OTksImlhdCI6MTYxMTU3ODc5OSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImM3YjJlNzVlLTdiNzItNGJhNi04YzQ2LWIwNGFhYzU1OWRkMSJ9.4Rt4fw-kqe6Z8DtqwdYIxjfIVwbgUitbp1E6XL6CE8EDjsamssJkZRH1bVBncSetw_sBW8IbTL2eU-2ff_Uqqg', 3599, '2021-01-25 06:46:38.877067');
INSERT INTO `sys_zoom_tokens` VALUES (39, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJhZjQwZGJiOS03ZDIxLTQzNmYtYjEzNy04YjRhMjQwYmU4NDQifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo0LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTgwNjQ3LCJleHAiOjE2MTE1ODQyNDcsImlhdCI6MTYxMTU4MDY0NywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjI1YTY3NTc1LTczODktNDM5YS1iOGQxLTNkYWZkNjk0ZTBjMCJ9.BW1KF46PStOwRlwGL-ptBt51MfpESF7UvYfgO2dcM3Q5ZyZJUAk4eTkc7eabEzn2evb_KReCbZPygzy7XSukdA', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI4YzQ0ZGEyOS0wNGY4LTQwYTQtOTc4My1mZGYwMWJhNmJhNGUifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo0LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTgwNjQ3LCJleHAiOjIwODQ2MjA2NDcsImlhdCI6MTYxMTU4MDY0NywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjdjYzRkYmQ1LTNhZDMtNGRmZi04OWMwLWFiOGJhMDE0ZmY5YSJ9.A7OUzrooyZuW2WXaF-1UW_S765VfUbXC_iuFwsCic7GAaN1NQf2RWaPzBk16DzcyhvkZG0hGKODRZy0paQVG4w', 3599, '2021-01-25 07:17:27.417460');
INSERT INTO `sys_zoom_tokens` VALUES (40, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI2MDZmZjUyYS1mODAzLTRlZWUtOWEyNC05NDQ5MGUwZjliZDcifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo1LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTgyMTgzLCJleHAiOjE2MTE1ODU3ODMsImlhdCI6MTYxMTU4MjE4MywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjYxZDZhYWJjLTQ3NmMtNGQ0OC04NzU1LTg2OGQxMDczMDk3NCJ9.Xy6Mhg150eS322avtXjthzFP4bXezxxcznN7mIIe91KzAqbp6kkXBGXZELcc-cqsBKtnpkx5j5fNijkxUV3NfQ', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIxNzVlZGM1MS0xOTA1LTRiZjYtODBmZC0xMGE2ODlkZDUwZDYifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo1LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTgyMTgzLCJleHAiOjIwODQ2MjIxODMsImlhdCI6MTYxMTU4MjE4MywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImVkOTAxZTAxLTUwZjktNDdiZC05ZTU3LTdmZGY3ODg3YTllOSJ9.-AeDmDdFf31A5t39nnPm0ibfsY1kCzcW7iCLkopzx0RTKExl3KPB_GSUIEnIqT7IHH94O2ADcgznTWJ9BBI85w', 3599, '2021-01-25 07:43:03.584649');
INSERT INTO `sys_zoom_tokens` VALUES (41, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJiMGI0M2E1Mi1hNTgzLTQ4NjgtYTJlMy04ZDhjOGM1ZjE2NTIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo2LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTgyMjIxLCJleHAiOjE2MTE1ODU4MjEsImlhdCI6MTYxMTU4MjIyMSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6Ijc1YThhZDAzLThlMzktNDgzYy04OWU5LTQ0YjJjYzdmZGZkYyJ9.0V0dzwv0J35vJixJFko1w-YQoyOrKPBbvKO6GX9LP7YzGsxYEFuW9jQDGYgyn6JZLCJ_QFCwiD7s5iXdvlcKsA', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIwMDg0YzgxNi0wYzg3LTRiZTctYTE2NS04YmFkNjM0ZjJhMDEifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo2LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTgyMjIxLCJleHAiOjIwODQ2MjIyMjEsImlhdCI6MTYxMTU4MjIyMSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjEzMDU5MjEzLWU4NzEtNDM4Yi1iNTUwLWRhOGI2MmU3NjgwZCJ9.FdG2CiMUbeh39txYlG9oTBiePBod73LWU5Nq27IpcRqmRGrfW_4iHGL6nhWaWarcSMuU6nVoHO1VUk0ccmVXKg', 3599, '2021-01-25 07:43:41.636755');
INSERT INTO `sys_zoom_tokens` VALUES (42, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJjZDZjMDQ5Ni1kZDVmLTRiYzItOGFhNi1iY2ZmNzk4ZDhmMTUifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo3LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTgyMjQxLCJleHAiOjE2MTE1ODU4NDEsImlhdCI6MTYxMTU4MjI0MSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjdkYzFkODVkLTliZDktNGJhNy1hMjk3LWE5ODRlNWE5Zjk1YiJ9.eAMvwfjSCUHVK3qx4WLPudMZN4WmTx2PsBIqqDqKl2ABam9pEI7GefQIBd4YEeLg1nUkhXLp-qEwjt9lUKfU8Q', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJiOTM2ZjI4NC1jYzlmLTQzYzctYTQ2Ny0yZTU5MTgzNmU1ZGEifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo3LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTgyMjQxLCJleHAiOjIwODQ2MjIyNDEsImlhdCI6MTYxMTU4MjI0MSwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImM4M2U5ZDFmLWI4YjItNDg0Mi05ZTNjLWE4ZmFlOWNmNmNkMCJ9.YyzLGI5K0BvQa6MBMXS9-j6QZegB3PfyD7QON5u9onudgCX57P1P3yGWN4dP1l4o14PYycjTiJktX0dGgQndxg', 3599, '2021-01-25 07:44:00.849922');
INSERT INTO `sys_zoom_tokens` VALUES (43, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJhNDI5YmZiYS0xN2MwLTQzYjQtYjNkZC0yMTllYjNkNTYxMTIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo4LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTk0NTA0LCJleHAiOjE2MTE1OTgxMDQsImlhdCI6MTYxMTU5NDUwNCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImYwMjg5NTExLWY0ZmQtNGY0ZS05ZGJkLWJlZTVmOGVmM2VjMCJ9.7_rAt0HSxh2DwxgyStqiVilpKmPvcR906-JN1KQVFXqMHYNXRsiTImzh-SAlI3w0ERcIgjam1uhganXlpxu9Ig', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJjYmU2NDViOC0yNWNmLTQ5MDUtYWQ4ZS0wN2U5MTFlOTAxYTIifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo4LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTk0NTA0LCJleHAiOjIwODQ2MzQ1MDQsImlhdCI6MTYxMTU5NDUwNCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjczNWQ4YmViLTM3NjctNDg4NS1hNTcxLTdmMzA2MGE2N2U1ZiJ9.lgnHdpcM5Jx2FUQz88SjV378GUmcIihA18mF5w4SH71YqR7nFM-pROJ-BNaOr7a4NKvc02go4Az-NI87pXYmew', 3599, '2021-01-25 11:08:24.937179');
INSERT INTO `sys_zoom_tokens` VALUES (44, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJkN2JiMjQzZS02ODNjLTRjMzUtODBlMy01ZTA4Y2UzZTRkMDcifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo5LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTk4MjY4LCJleHAiOjE2MTE2MDE4NjgsImlhdCI6MTYxMTU5ODI2OCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjUwMmY2ZGZlLWQ0ZjUtNDhjZS04MjRkLWMzZmJhMzk0MTViMCJ9.96_JkxWoRlNSMSi7NqOIdApfxm5-5olljwz2wAj-LL9f1iJKNpHIzDJwoHK94oN8i7DVaK859gTbBFFYJZYcxg', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJkYWIwYTg2ZS1iZjYwLTQ2NTYtYTU4MC1jODBkNDU0OTZlMjMifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjo5LCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjExNTk4MjY4LCJleHAiOjIwODQ2MzgyNjgsImlhdCI6MTYxMTU5ODI2OCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6Ijg2ODcwN2JkLTI4NTAtNDU2NS1hNjA2LWU3ZDE1NWE5YTc1MSJ9.dVNthS3cx4po0vo7onE_7iqSq1uYKnzSSyygfDhp5Sjiixfh6Tg252Z9NtlcOMB6MZWz_5-Auct-NVOTv2RDug', 3599, '2021-01-25 12:11:08.045082');
INSERT INTO `sys_zoom_tokens` VALUES (45, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJlN2JmMDRjMC00NTYzLTRlZTgtYWQ5OS01OWY4NWJhZGM1ZjUifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoxMCwiYXVkIjoiaHR0cHM6Ly9vYXV0aC56b29tLnVzIiwidWlkIjoid1Y4UnlSWnRSaENSTFFJSWNMcUF0USIsIm5iZiI6MTYxMTU5ODQ4NCwiZXhwIjoxNjExNjAyMDg0LCJpYXQiOjE2MTE1OTg0ODQsImFpZCI6Imhhd3FKRVJ5UmVXMVl6Q3k3WmVMZGciLCJqdGkiOiIwZTk0N2ExOS03MWQ3LTQ4YzMtODNhNC02Y2ExOWYwYjVjZjYifQ.2tnclAOPvDQdBO0D9ZRD33adtV3pjCFbd0VrTNrGR0BReosezq9wowhHRfJZ4r_rIy4QgtIKV3ONTLWjxDlMLQ', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIzYTRlYjBkMi0wYTkyLTQ0M2YtYWNmNi0xNWRkMTZkZTJjMDYifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiI4VmZYdUpTa0E4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoxMCwiYXVkIjoiaHR0cHM6Ly9vYXV0aC56b29tLnVzIiwidWlkIjoid1Y4UnlSWnRSaENSTFFJSWNMcUF0USIsIm5iZiI6MTYxMTU5ODQ4NCwiZXhwIjoyMDg0NjM4NDg0LCJpYXQiOjE2MTE1OTg0ODQsImFpZCI6Imhhd3FKRVJ5UmVXMVl6Q3k3WmVMZGciLCJqdGkiOiI4NDA4MjY4YS1hZGVmLTQxYmEtYmJjNy1iNjRiM2FlZGIwOWYifQ.OlOU5s6FGcipRwooMNvWB2Uw9o6OiQV-JrDlGbhLRhRw3K1B_qT1oUNUepsNjmWOHUu-a5xWqS0H-0zziZWDRQ', 3599, '2021-01-25 12:14:44.839868');
INSERT INTO `sys_zoom_tokens` VALUES (46, NULL, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJlMDkwZmIyZi03NWU5LTRhZmEtYTI2MC0xMGRmNmNhNGM0NzgifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJoRnM1VnlZeUE4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjowLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEyMjUyMTU0LCJleHAiOjIwODUyOTIxNTQsImlhdCI6MTYxMjI1MjE1NCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImJlOWU4MmEzLTY4NDQtNDBkMy05ZGE0LWQyYzExZmQ4MTA2OSJ9.5H41U5NWde60cjSsKRQez2D40t6sww53G2xO3aAbOd3ROLq4wCiWtf2MstYWgLroeRs26qhpR0mPk9QLH3jFBQ', NULL, NULL);
INSERT INTO `sys_zoom_tokens` VALUES (47, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI3NTc5OTI1OS1mZDQwLTRiMzItODRlYi0zMWVkYzRjZWQxOTEifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJoRnM1VnlZeUE4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEyMjUyMTkwLCJleHAiOjE2MTIyNTU3OTAsImlhdCI6MTYxMjI1MjE5MCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjRjNGIzNGJmLWU4NmQtNDhmNi04NWFlLWNmNzEyZDg4M2I0ZiJ9.aFBiewqyKGG-9QSUXl8SFlR-uQLbhef_9dv1A3ldXQSAbSFEpWO8FEUANt1E8NLh8LpVPeV_CBV2P2sSPfHv8Q', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJiNzMwZjcyZS1mM2U2LTRhZGMtOWI5Yi02N2U5M2EzMGQ0NzAifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJoRnM1VnlZeUE4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoxLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEyMjUyMTkwLCJleHAiOjIwODUyOTIxOTAsImlhdCI6MTYxMjI1MjE5MCwiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6ImQyYzVlMjRmLTVmYjQtNGZjYi04NjhhLTM5NWNkYjAzMTdiYyJ9.EX24lsvi1-CXkcfQ1EwnWORt18yKAoKTLpa6dukBz9XP7B5WvAqB9OH7ZCbiPKnRqCTXo-zSKMLWv6k5FPUsfg', 3599, '2021-02-02 01:49:50.430392');
INSERT INTO `sys_zoom_tokens` VALUES (48, 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiJmMjAzNTdiYS0xMDczLTQzNTgtYTEzMi1mM2Y2YTY4YTRmYWYifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJoRnM1VnlZeUE4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjoyLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEyMjUyMjMzLCJleHAiOjE2MTIyNTU4MzMsImlhdCI6MTYxMjI1MjIzMywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjEzMzJkNGRjLWIwN2QtNGUxOS05OWViLTZkY2QwYzEwNzZkYyJ9.I4e7dTPJEIB__bTAu0alo3MdbAz-0Yk6yzijfVegGb_YB_jlbceZTct3ZpYjb9zc8Uszq04qlXEfVDeC_8WzTA', 'eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiI4MGQ0ZWJmMS1hNGVhLTRlOGQtOWJmZS0wM2M4YmYwMjVlY2IifQ.eyJ2ZXIiOjcsImF1aWQiOiIxNGNkYjhmNWQwNzBiY2NjZTA4ZjI5ZWIzOTYxOGY0MSIsImNvZGUiOiJoRnM1VnlZeUE4X3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MSwidGlkIjoyLCJhdWQiOiJodHRwczovL29hdXRoLnpvb20udXMiLCJ1aWQiOiJ3VjhSeVJadFJoQ1JMUUlJY0xxQXRRIiwibmJmIjoxNjEyMjUyMjMzLCJleHAiOjIwODUyOTIyMzMsImlhdCI6MTYxMjI1MjIzMywiYWlkIjoiaGF3cUpFUnlSZVcxWXpDeTdaZUxkZyIsImp0aSI6IjY5ODQxZTZjLThjMDAtNGUyMS1iYWIzLTNkNDNlMjdhY2JmZSJ9.feDaRlXI-D06aAsTjl5s_SwS9SLdWaJSGVB3LW-DDV2Rd8M8-jnWjGkgcpyvB2Xv-hphNdm0DvxDPqQphgP0qw', 3599, '2021-02-02 01:50:33.757671');
COMMIT;

SET FOREIGN_KEY_CHECKS = 1;
