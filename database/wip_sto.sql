-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Sep 09, 2026 at 11:41 AM
-- Server version: 8.4.3
-- PHP Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `wip_sto`
--

-- --------------------------------------------------------

--
-- Table structure for table `bom`
--

CREATE TABLE `bom` (
  `id` bigint NOT NULL,
  `model` varchar(30) DEFAULT NULL,
  `katashiki` varchar(30) DEFAULT NULL,
  `material` varchar(50) DEFAULT NULL,
  `suffix` varchar(80) DEFAULT NULL,
  `component` varchar(60) DEFAULT NULL,
  `part_number` varchar(60) DEFAULT NULL,
  `material_description` varchar(255) DEFAULT NULL,
  `qty` decimal(14,3) NOT NULL DEFAULT '0.000',
  `uom` varchar(10) DEFAULT NULL,
  `shop_code` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bom_upload_log`
--

CREATE TABLE `bom_upload_log` (
  `id` int NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mode` enum('append','replace') NOT NULL DEFAULT 'append',
  `total_rows` int NOT NULL DEFAULT '0',
  `inserted_rows` int NOT NULL DEFAULT '0',
  `skipped_rows` int NOT NULL DEFAULT '0',
  `status` enum('success','failed') NOT NULL DEFAULT 'success',
  `message` text,
  `user_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `part_list`
--
-- Uploaded from a wide "pivot" spreadsheet (Part No/Part Name/Shop/Model
-- + one column per Suffix) so it can be compared against Master BOM to
-- spot data differences. No Material/Katashiki columns — that pivot
-- format never carries them.
--

CREATE TABLE `part_list` (
  `id` bigint NOT NULL,
  `model` varchar(30) DEFAULT NULL,
  `suffix` varchar(80) DEFAULT NULL,
  `component` varchar(60) DEFAULT NULL,
  `part_number` varchar(60) DEFAULT NULL,
  `material_description` varchar(255) DEFAULT NULL,
  `qty` decimal(14,3) NOT NULL DEFAULT '0.000',
  `uom` varchar(10) DEFAULT NULL,
  `shop_code` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `part_list_upload_log`
--

CREATE TABLE `part_list_upload_log` (
  `id` int NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mode` enum('append','replace') NOT NULL DEFAULT 'append',
  `total_rows` int NOT NULL DEFAULT '0',
  `inserted_rows` int NOT NULL DEFAULT '0',
  `skipped_rows` int NOT NULL DEFAULT '0',
  `status` enum('success','failed') NOT NULL DEFAULT 'success',
  `message` text,
  `user_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$12$j8joswPkZ0vTc9GQxSK4YuBfFYETzwpVa9X3t.k3gj5smJLAvWNqi', 'Administrator', 'admin', 1, '2026-09-09 09:54:58', '2026-09-08 15:11:23', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `wip_calc_cutoff`
--

CREATE TABLE `wip_calc_cutoff` (
  `id` bigint NOT NULL,
  `shop_code` varchar(30) NOT NULL,
  `part_number` varchar(60) NOT NULL,
  `vin` varchar(30) NOT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wip_calc_cutoff_log`
--

CREATE TABLE `wip_calc_cutoff_log` (
  `id` int NOT NULL,
  `source` enum('kap1','kap2') NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `total_rows` int NOT NULL DEFAULT '0',
  `applied_rows` int NOT NULL DEFAULT '0',
  `skipped_rows` int NOT NULL DEFAULT '0',
  `status` enum('success','failed') NOT NULL DEFAULT 'success',
  `message` text,
  `user_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wip_data`
--

CREATE TABLE `wip_data` (
  `id` bigint NOT NULL,
  `source` enum('kap1','kap2') NOT NULL,
  `shop` varchar(20) NOT NULL,
  `vin` varchar(30) DEFAULT NULL,
  `sfx` varchar(20) DEFAULT NULL,
  `katashiki` varchar(30) DEFAULT NULL,
  `modelcode` varchar(30) DEFAULT NULL,
  `shopcode` varchar(30) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wip_data_log`
--

CREATE TABLE `wip_data_log` (
  `id` int NOT NULL,
  `source` enum('kap1','kap2') NOT NULL,
  `shop` varchar(20) DEFAULT NULL,
  `origin` enum('server','upload') NOT NULL DEFAULT 'server',
  `file_name` varchar(255) DEFAULT NULL,
  `mode` enum('append','replace') NOT NULL DEFAULT 'replace',
  `total_rows` int NOT NULL DEFAULT '0',
  `status` enum('success','failed') NOT NULL DEFAULT 'success',
  `message` text,
  `user_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bom`
--
ALTER TABLE `bom`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bom_material` (`material`),
  ADD KEY `idx_bom_component` (`component`),
  ADD KEY `idx_bom_part_number` (`part_number`),
  ADD KEY `idx_bom_shop_code` (`shop_code`),
  ADD KEY `idx_bom_model` (`model`),
  ADD KEY `idx_bom_katashiki` (`katashiki`),
  -- Speeds up Part_list_model::build_diff()'s Model+Suffix+Part Number
  -- join against `part_list` (see idx_part_list_msp below).
  ADD KEY `idx_bom_msp` (`model`, `suffix`, `part_number`);

--
-- Indexes for table `bom_upload_log`
--
ALTER TABLE `bom_upload_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_log_user` (`user_id`);

--
-- Indexes for table `part_list`
--
ALTER TABLE `part_list`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_part_list_component` (`component`),
  ADD KEY `idx_part_list_part_number` (`part_number`),
  ADD KEY `idx_part_list_shop_code` (`shop_code`),
  ADD KEY `idx_part_list_model` (`model`),
  -- Speeds up Part_list_model::build_diff()'s Model+Suffix+Part Number
  -- join against `bom` (see idx_bom_msp above) — without this, that join
  -- took 20+ seconds on the real ~150k/180k-row tables.
  ADD KEY `idx_part_list_msp` (`model`, `suffix`, `part_number`),
  -- A Part List row's identity. shop_code is part of it because the same
  -- part+model+suffix legitimately appears in different shops with
  -- different quantities. Uploads upsert against this key, so re-uploading
  -- refreshes a row instead of adding a duplicate of it.
  ADD UNIQUE KEY `uniq_part_list_row` (`model`, `suffix`, `part_number`, `shop_code`);

--
-- Indexes for table `part_list_upload_log`
--
ALTER TABLE `part_list_upload_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_part_list_log_user` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`);

--
-- Indexes for table `wip_calc_cutoff`
--
ALTER TABLE `wip_calc_cutoff`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_wip_calc_cutoff_shop_part` (`shop_code`,`part_number`);

--
-- Indexes for table `wip_calc_cutoff_log`
--
ALTER TABLE `wip_calc_cutoff_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wip_calc_cutoff_log_source` (`source`);

--
-- Indexes for table `wip_data`
--
ALTER TABLE `wip_data`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wip_data_source_shop` (`source`,`shop`),
  ADD KEY `idx_wip_data_vin` (`vin`),
  ADD KEY `idx_wip_data_katashiki` (`katashiki`);

--
-- Indexes for table `wip_data_log`
--
ALTER TABLE `wip_data_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wip_log_source` (`source`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bom`
--
ALTER TABLE `bom`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bom_upload_log`
--
ALTER TABLE `bom_upload_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `part_list`
--
ALTER TABLE `part_list`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `part_list_upload_log`
--
ALTER TABLE `part_list_upload_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `wip_calc_cutoff`
--
ALTER TABLE `wip_calc_cutoff`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wip_calc_cutoff_log`
--
ALTER TABLE `wip_calc_cutoff_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wip_data`
--
ALTER TABLE `wip_data`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wip_data_log`
--
ALTER TABLE `wip_data_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
