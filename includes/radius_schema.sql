-- FreeRADIUS standard MySQL/MariaDB schema for UISP ROS Plugin
-- Compatible with FreeRADIUS 3.x and MariaDB 10.x / MySQL 5.7+ / MySQL 8.x

CREATE DATABASE IF NOT EXISTS `radius` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `radius`;

-- 1. Client Table (NAS) - MikroTik Routers
CREATE TABLE IF NOT EXISTS `nas` (
    `id` int(10) NOT NULL AUTO_INCREMENT,
    `nasname` varchar(128) NOT NULL,
    `shortname` varchar(32) DEFAULT NULL,
    `type` varchar(30) DEFAULT 'other',
    `ports` int(5) DEFAULT NULL,
    `secret` varchar(60) NOT NULL DEFAULT 'secret',
    `server` varchar(64) DEFAULT NULL,
    `community` varchar(50) DEFAULT NULL,
    `description` varchar(200) DEFAULT 'RADIUS Client',
    PRIMARY KEY (`id`),
    KEY `nasname` (`nasname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. User Check Attributes (Authentication, e.g. Cleartext-Password, Auth-Type)
CREATE TABLE IF NOT EXISTS `radcheck` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `username` varchar(64) NOT NULL DEFAULT '',
    `attribute` varchar(64) NOT NULL DEFAULT '',
    `op` char(2) NOT NULL DEFAULT '==',
    `value` varchar(253) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `username` (`username`(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. User Reply Attributes (Authorization, e.g. Framed-IP-Address, Mikrotik-Rate-Limit)
CREATE TABLE IF NOT EXISTS `radreply` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `username` varchar(64) NOT NULL DEFAULT '',
    `attribute` varchar(64) NOT NULL DEFAULT '',
    `op` char(2) NOT NULL DEFAULT '=',
    `value` varchar(253) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `username` (`username`(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. User Group Mapping
CREATE TABLE IF NOT EXISTS `radusergroup` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `username` varchar(64) NOT NULL DEFAULT '',
    `groupname` varchar(64) NOT NULL DEFAULT '',
    `priority` int(11) NOT NULL DEFAULT '1',
    PRIMARY KEY (`id`),
    KEY `username` (`username`(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Group Check Attributes
CREATE TABLE IF NOT EXISTS `radgroupcheck` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `groupname` varchar(64) NOT NULL DEFAULT '',
    `attribute` varchar(64) NOT NULL DEFAULT '',
    `op` char(2) NOT NULL DEFAULT '==',
    `value` varchar(253) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `groupname` (`groupname`(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Group Reply Attributes (Plan level rate limits, address lists)
CREATE TABLE IF NOT EXISTS `radgroupreply` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `groupname` varchar(64) NOT NULL DEFAULT '',
    `attribute` varchar(64) NOT NULL DEFAULT '',
    `op` char(2) NOT NULL DEFAULT '=',
    `value` varchar(253) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `groupname` (`groupname`(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Accounting Table
CREATE TABLE IF NOT EXISTS `radacct` (
    `radacctid` bigint(21) NOT NULL AUTO_INCREMENT,
    `acctsessionid` varchar(64) NOT NULL DEFAULT '',
    `acctuniqueid` varchar(32) NOT NULL DEFAULT '',
    `username` varchar(64) NOT NULL DEFAULT '',
    `groupname` varchar(64) NOT NULL DEFAULT '',
    `realm` varchar(64) DEFAULT '',
    `nasipaddress` varchar(45) NOT NULL DEFAULT '',
    `nasportid` varchar(32) DEFAULT NULL,
    `nasporttype` varchar(32) DEFAULT NULL,
    `acctstarttime` datetime DEFAULT NULL,
    `acctupdatetime` datetime DEFAULT NULL,
    `acctstoptime` datetime DEFAULT NULL,
    `acctinterval` int(12) DEFAULT NULL,
    `acctsessiontime` int(12) unsigned DEFAULT NULL,
    `acctauthentic` varchar(32) DEFAULT NULL,
    `connectinfo_start` varchar(128) DEFAULT NULL,
    `connectinfo_stop` varchar(128) DEFAULT NULL,
    `acctinputoctets` bigint(20) DEFAULT NULL,
    `acctoutputoctets` bigint(20) DEFAULT NULL,
    `calledstationid` varchar(50) NOT NULL DEFAULT '',
    `callingstationid` varchar(50) NOT NULL DEFAULT '',
    `acctterminatecause` varchar(32) NOT NULL DEFAULT '',
    `servicetype` varchar(32) DEFAULT NULL,
    `framedprotocol` varchar(32) DEFAULT NULL,
    `framedipaddress` varchar(45) NOT NULL DEFAULT '',
    `framedipv6address` varchar(45) NOT NULL DEFAULT '',
    `framedipv6prefix` varchar(45) NOT NULL DEFAULT '',
    `framedinterfaceid` varchar(44) NOT NULL DEFAULT '',
    `delegatedipv6prefix` varchar(45) NOT NULL DEFAULT '',
    PRIMARY KEY (`radacctid`),
    UNIQUE KEY `acctuniqueid` (`acctuniqueid`),
    KEY `username` (`username`),
    KEY `framedipaddress` (`framedipaddress`),
    KEY `nasipaddress` (`nasipaddress`),
    KEY `acctsessionid` (`acctsessionid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Post-Auth Table
CREATE TABLE IF NOT EXISTS `radpostauth` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `username` varchar(64) NOT NULL DEFAULT '',
    `pass` varchar(64) NOT NULL DEFAULT '',
    `reply` varchar(32) NOT NULL DEFAULT '',
    `authdate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `class` varchar(64) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `username` (`username`(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
