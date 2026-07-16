-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 11, 2026 at 05:00 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `supply_chain_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `demand_forecasts`
--

CREATE TABLE `demand_forecasts` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `forecast_period` date NOT NULL,
  `forecasted_quantity` int(11) NOT NULL,
  `confidence_level` decimal(5,2) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `demand_forecasts`
--

INSERT INTO `demand_forecasts` (`id`, `product_id`, `forecast_period`, `forecasted_quantity`, `confidence_level`, `created_by`, `created_at`) VALUES
(81, 261, '2026-01-09', 9, 80.00, 1, '2026-01-08 03:30:15'),
(82, 263, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:32:47'),
(83, 265, '2026-01-11', 43, 75.00, 1, '2026-01-11 13:32:47'),
(84, 278, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:32:47'),
(85, 257, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:32:47'),
(86, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 13:32:47'),
(87, 256, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:32:47'),
(88, 271, '2026-01-11', 36, 75.00, 1, '2026-01-11 13:32:47'),
(89, 260, '2026-01-11', 44, 75.00, 1, '2026-01-11 13:32:47'),
(90, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:32:47'),
(91, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:32:47'),
(92, 263, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:33:29'),
(93, 265, '2026-01-11', 43, 75.00, 1, '2026-01-11 13:33:29'),
(94, 278, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:33:29'),
(95, 257, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:33:29'),
(96, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 13:33:29'),
(97, 256, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:33:29'),
(98, 271, '2026-01-11', 36, 75.00, 1, '2026-01-11 13:33:29'),
(99, 260, '2026-01-11', 44, 75.00, 1, '2026-01-11 13:33:29'),
(100, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:33:29'),
(101, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:33:29'),
(102, 263, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:34:28'),
(103, 265, '2026-01-11', 43, 75.00, 1, '2026-01-11 13:34:28'),
(104, 278, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:34:28'),
(105, 257, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:34:28'),
(106, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 13:34:28'),
(107, 256, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:34:28'),
(108, 271, '2026-01-11', 36, 75.00, 1, '2026-01-11 13:34:28'),
(109, 260, '2026-01-11', 44, 75.00, 1, '2026-01-11 13:34:28'),
(110, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:34:28'),
(111, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:34:28'),
(112, 263, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:34:43'),
(113, 265, '2026-01-11', 43, 75.00, 1, '2026-01-11 13:34:43'),
(114, 278, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:34:43'),
(115, 257, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:34:43'),
(116, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 13:34:43'),
(117, 256, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:34:43'),
(118, 271, '2026-01-11', 36, 75.00, 1, '2026-01-11 13:34:43'),
(119, 260, '2026-01-11', 44, 75.00, 1, '2026-01-11 13:34:43'),
(120, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:34:43'),
(121, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:34:43'),
(122, 263, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:38:46'),
(123, 265, '2026-01-11', 43, 75.00, 1, '2026-01-11 13:38:46'),
(124, 278, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:38:46'),
(125, 257, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:38:46'),
(126, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 13:38:46'),
(127, 256, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:38:46'),
(128, 271, '2026-01-11', 36, 75.00, 1, '2026-01-11 13:38:46'),
(129, 260, '2026-01-11', 44, 75.00, 1, '2026-01-11 13:38:46'),
(130, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:38:46'),
(131, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:38:46'),
(132, 263, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:39:42'),
(133, 265, '2026-01-11', 43, 75.00, 1, '2026-01-11 13:39:42'),
(134, 278, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:39:42'),
(135, 257, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:39:42'),
(136, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 13:39:42'),
(137, 256, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:39:42'),
(138, 271, '2026-01-11', 36, 75.00, 1, '2026-01-11 13:39:42'),
(139, 260, '2026-01-11', 44, 75.00, 1, '2026-01-11 13:39:42'),
(140, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:39:42'),
(141, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:39:42'),
(142, 257, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:46:27'),
(143, 263, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:46:27'),
(144, 265, '2026-01-11', 43, 75.00, 1, '2026-01-11 13:46:27'),
(145, 256, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:46:27'),
(146, 278, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:46:27'),
(147, 260, '2026-01-11', 44, 75.00, 1, '2026-01-11 13:46:27'),
(148, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 13:46:27'),
(149, 271, '2026-01-11', 36, 75.00, 1, '2026-01-11 13:46:27'),
(150, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:46:27'),
(151, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:46:27'),
(152, 263, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:53:52'),
(153, 265, '2026-01-11', 43, 75.00, 1, '2026-01-11 13:53:52'),
(154, 278, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:53:52'),
(155, 257, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:53:52'),
(156, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 13:53:52'),
(157, 256, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:53:52'),
(158, 271, '2026-01-11', 36, 75.00, 1, '2026-01-11 13:53:52'),
(159, 260, '2026-01-11', 44, 75.00, 1, '2026-01-11 13:53:52'),
(160, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:53:52'),
(161, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:53:52'),
(162, 257, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:54:31'),
(163, 263, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:54:31'),
(164, 265, '2026-01-11', 43, 75.00, 1, '2026-01-11 13:54:31'),
(165, 256, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:54:31'),
(166, 278, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:54:31'),
(167, 260, '2026-01-11', 44, 75.00, 1, '2026-01-11 13:54:31'),
(168, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 13:54:31'),
(169, 271, '2026-01-11', 36, 75.00, 1, '2026-01-11 13:54:31'),
(170, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:54:31'),
(171, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:54:31'),
(172, 263, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:56:00'),
(173, 257, '2026-01-11', 30, 75.00, 1, '2026-01-11 13:56:00'),
(174, 265, '2026-01-11', 43, 75.00, 1, '2026-01-11 13:56:00'),
(175, 256, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:56:00'),
(176, 278, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:56:00'),
(177, 260, '2026-01-11', 44, 75.00, 1, '2026-01-11 13:56:00'),
(178, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 13:56:00'),
(179, 271, '2026-01-11', 36, 75.00, 1, '2026-01-11 13:56:00'),
(180, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 13:56:00'),
(181, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 13:56:00'),
(182, 263, '2026-01-11', 30, 75.00, 1, '2026-01-11 14:08:00'),
(183, 257, '2026-01-11', 30, 75.00, 1, '2026-01-11 14:08:00'),
(184, 265, '2026-01-11', 43, 75.00, 1, '2026-01-11 14:08:00'),
(185, 256, '2026-01-11', 32, 75.00, 1, '2026-01-11 14:08:00'),
(186, 278, '2026-01-11', 45, 75.00, 1, '2026-01-11 14:08:00'),
(187, 260, '2026-01-11', 44, 75.00, 1, '2026-01-11 14:08:00'),
(188, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 14:08:00'),
(189, 271, '2026-01-11', 36, 75.00, 1, '2026-01-11 14:08:00'),
(190, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 14:08:00'),
(191, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 14:08:00'),
(192, 263, '2026-01-11', 30, 75.00, 1, '2026-01-11 14:10:37'),
(193, 257, '2026-01-11', 30, 75.00, 1, '2026-01-11 14:10:37'),
(194, 265, '2026-01-11', 43, 75.00, 1, '2026-01-11 14:10:37'),
(195, 256, '2026-01-11', 32, 75.00, 1, '2026-01-11 14:10:37'),
(196, 278, '2026-01-11', 45, 75.00, 1, '2026-01-11 14:10:37'),
(197, 260, '2026-01-11', 44, 75.00, 1, '2026-01-11 14:10:37'),
(198, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 14:10:37'),
(199, 271, '2026-01-11', 36, 75.00, 1, '2026-01-11 14:10:37'),
(200, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 14:10:37'),
(201, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 14:10:37'),
(202, 263, '2026-01-11', 30, 75.00, 1, '2026-01-11 14:10:59'),
(203, 257, '2026-01-11', 30, 75.00, 1, '2026-01-11 14:10:59'),
(204, 265, '2026-01-11', 43, 75.00, 1, '2026-01-11 14:10:59'),
(205, 256, '2026-01-11', 32, 75.00, 1, '2026-01-11 14:10:59'),
(206, 278, '2026-01-11', 45, 75.00, 1, '2026-01-11 14:10:59'),
(207, 260, '2026-01-11', 44, 75.00, 1, '2026-01-11 14:10:59'),
(208, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 14:10:59'),
(209, 271, '2026-01-11', 36, 75.00, 1, '2026-01-11 14:10:59'),
(210, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 14:10:59'),
(211, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 14:10:59'),
(212, 263, '2026-01-11', 30, 75.00, 1, '2026-01-11 14:11:05'),
(213, 265, '2026-01-11', 43, 75.00, 1, '2026-01-11 14:11:05'),
(214, 278, '2026-01-11', 45, 75.00, 1, '2026-01-11 14:11:05'),
(215, 257, '2026-01-11', 30, 75.00, 1, '2026-01-11 14:11:05'),
(216, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 14:11:05'),
(217, 256, '2026-01-11', 32, 75.00, 1, '2026-01-11 14:11:05'),
(218, 271, '2026-01-11', 36, 75.00, 1, '2026-01-11 14:11:05'),
(219, 260, '2026-01-11', 44, 75.00, 1, '2026-01-11 14:11:05'),
(220, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 14:11:05'),
(221, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 14:11:05'),
(222, 265, '2026-01-11', 85, 85.00, 1, '2026-01-11 14:30:25'),
(223, 257, '2026-01-11', 68, 85.00, 1, '2026-01-11 14:30:25'),
(224, 263, '2026-01-11', 60, 80.00, 1, '2026-01-11 14:30:25'),
(225, 278, '2026-01-11', 56, 80.00, 1, '2026-01-11 14:30:25'),
(226, 256, '2026-01-11', 52, 85.00, 1, '2026-01-11 14:30:25'),
(227, 271, '2026-01-11', 36, 80.00, 1, '2026-01-11 14:30:25'),
(228, 260, '2026-01-11', 44, 80.00, 1, '2026-01-11 14:30:25'),
(229, 279, '2026-01-11', 34, 75.00, 1, '2026-01-11 14:30:25'),
(230, 272, '2026-01-11', 32, 75.00, 1, '2026-01-11 14:30:25'),
(231, 270, '2026-01-11', 45, 75.00, 1, '2026-01-11 14:30:25');

-- --------------------------------------------------------

--
-- Table structure for table `drivers`
--

CREATE TABLE `drivers` (
  `id` int(11) NOT NULL,
  `driver_code` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `license_number` varchar(50) DEFAULT NULL,
  `license_type` enum('A','B','C','D','E') NOT NULL DEFAULT 'B',
  `license_expiry` date DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('available','on_duty','off_duty','sick_leave','inactive') DEFAULT 'available',
  `hire_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `drivers`
--

INSERT INTO `drivers` (`id`, `driver_code`, `full_name`, `license_number`, `license_type`, `license_expiry`, `phone`, `email`, `address`, `status`, `hire_date`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'DRV-001', 'John Michael Santos', 'DL-2020-001234', 'B', '2027-05-15', '+63-912-345-6789', 'john.santos@company.com', '123 Main Street, Quezon City, Metro Manila', 'available', '2020-03-15', 'Experienced driver with 5+ years. Specializes in long-distance routes.', '2026-01-05 11:27:06', '2026-01-05 11:27:06'),
(2, 'DRV-002', 'Maria Cristina Reyes', 'DL-2019-005678', 'B', '2026-08-20', '+63-917-890-1234', 'maria.reyes@company.com', '456 Rizal Avenue, Makati City, Metro Manila', 'on_duty', '2019-06-01', 'Excellent safety record. Certified for hazardous materials transport.', '2026-01-05 11:27:06', '2026-01-05 11:27:06'),
(3, 'DRV-003', 'Roberto Dela Cruz', 'DL-2021-009012', 'C', '2028-11-30', '+63-918-234-5678', 'roberto.delacruz@company.com', '789 EDSA, Mandaluyong City, Metro Manila', 'available', '2021-01-10', 'New driver. Currently in training program. Good communication skills.', '2026-01-05 11:27:06', '2026-01-05 11:27:06'),
(4, 'DRV-004', 'Jennifer Ann Garcia', 'DL-2018-003456', 'B', '2026-03-10', '+63-919-456-7890', 'jennifer.garcia@company.com', '321 Taft Avenue, Manila City', 'off_duty', '2018-09-20', 'Senior driver with 8+ years experience. Team leader for delivery operations.', '2026-01-05 11:27:06', '2026-01-05 11:27:06'),
(5, 'DRV-005', 'Carlos Manuel Torres', 'DL-2022-007890', 'B', '2027-09-25', '+63-920-678-9012', 'carlos.torres@company.com', '654 Commonwealth Avenue, Quezon City, Metro Manila', 'available', '2022-05-05', 'Reliable driver. Good with GPS navigation and route optimization.', '2026-01-05 11:27:06', '2026-01-05 11:27:06');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `reserved_quantity` int(11) DEFAULT 0,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `product_id`, `warehouse_id`, `quantity`, `reserved_quantity`, `last_updated`) VALUES
(256, 256, 1, 122, 0, '2026-01-03 20:25:14'),
(257, 257, 1, 48, 0, '2026-01-03 20:25:14'),
(258, 258, 1, 162, 0, '2026-01-03 20:25:14'),
(260, 260, 1, 110, 0, '2026-01-11 15:34:04'),
(261, 261, 1, 184, 0, '2026-01-03 20:25:15'),
(262, 262, 1, 282, 0, '2026-01-11 11:28:56'),
(263, 263, 1, 168, 0, '2026-01-03 20:25:15'),
(264, 264, 1, 168, 0, '2026-01-03 20:25:15'),
(265, 265, 1, 194, 0, '2026-01-03 20:25:15'),
(266, 266, 1, 134, 0, '2026-01-08 03:18:02'),
(267, 267, 1, 162, 0, '2026-01-03 20:25:15'),
(268, 268, 1, 41, 0, '2026-01-11 12:30:35'),
(269, 269, 1, 182, 0, '2026-01-03 20:25:16'),
(270, 270, 1, 176, 0, '2026-01-08 03:18:02'),
(271, 271, 1, 72, 0, '2026-01-11 12:30:35'),
(272, 272, 1, 210, 0, '2026-01-11 11:28:56'),
(273, 273, 1, 112, 0, '2026-01-03 20:25:16'),
(274, 274, 1, 484, 0, '2026-01-11 11:30:13'),
(275, 275, 1, 158, 0, '2026-01-03 20:25:17'),
(276, 276, 1, 118, 0, '2026-01-03 20:25:17'),
(277, 277, 1, 182, 0, '2026-01-03 20:25:17'),
(278, 278, 1, 100, 0, '2026-01-11 11:34:11'),
(279, 279, 1, 38, 0, '2026-01-03 20:25:17'),
(280, 280, 1, 204, 0, '2026-01-04 12:36:38'),
(281, 272, 3, 149, 0, '2026-01-07 15:19:57'),
(282, 277, 2, 40, 0, '2026-01-04 12:58:35'),
(333, 328, 1, 100, 0, '2026-01-07 15:00:15'),
(337, 272, 2, 116, 0, '2026-01-11 11:10:19'),
(338, 274, 2, 69, 0, '2026-01-11 11:31:54'),
(339, 335, 1, 40, 0, '2026-01-08 03:28:25'),
(342, 262, 3, 60, 0, '2026-01-11 11:32:29'),
(343, 260, 2, 50, 0, '2026-01-08 01:27:58'),
(344, 335, 2, 110, 0, '2026-01-11 11:30:55'),
(345, 261, 3, 50, 0, '2026-01-11 11:32:08'),
(346, 278, 3, 54, 0, '2026-01-11 11:34:11'),
(347, 271, 3, 138, 0, '2026-01-11 11:36:14'),
(348, 268, 3, 62, 0, '2026-01-11 12:47:56');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'User who should receive the notification',
  `role` varchar(50) NOT NULL COMMENT 'Role of the user receiving the notification',
  `notification_type` varchar(50) NOT NULL COMMENT 'Type: shipment_scheduled, po_created, etc.',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'Type of reference: shipment, purchase_order, etc.',
  `reference_id` int(11) DEFAULT NULL COMMENT 'ID of the referenced record',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `role`, `notification_type`, `title`, `message`, `reference_type`, `reference_id`, `is_read`, `created_at`) VALUES
(1, 7, 'supplier', 'po_created', 'New Purchase Order Created', 'A new purchase order PO-20260110-29CDCA has been created for your company. Expected delivery date: Jan 17, 2026', 'purchase_order', 20, 1, '2026-01-10 15:10:26'),
(2, 7, 'supplier', 'po_created', 'New Purchase Order Created', 'A new purchase order PO-20260110-79B711 has been created for your company. Expected delivery date: Jan 16, 2026', 'purchase_order', 21, 1, '2026-01-10 15:12:55'),
(3, 1, 'admin', 'po_created', 'New Purchase Order Created', 'Purchase Order PO-20260110-79B711 has been created by Procurement Staff. Supplier: Incheon Food Export. Total Amount: ₱16,551.00. Expected delivery: Jan 16, 2026', 'purchase_order', 21, 1, '2026-01-10 15:12:55'),
(4, 7, 'supplier', 'po_approved', 'Purchase Order Approved', 'Purchase Order PO-20260110-79B711 has been approved. Please review and schedule shipment.', 'purchase_order', 21, 1, '2026-01-10 15:13:08'),
(5, 2, 'procurement_staff', 'po_approved', 'Purchase Order Approved', 'Purchase Order PO-20260110-79B711 that you created has been approved and is ready for supplier processing.', 'purchase_order', 21, 1, '2026-01-10 15:13:08'),
(6, 4, 'logistics_manager', 'shipment_scheduled', 'Shipment Scheduled', 'Shipment SHIP-20260110-E91E7D has been scheduled by supplier for Purchase Order PO-20260110-79B711. Scheduled delivery date: Jan 10, 2026', 'shipment', 35, 1, '2026-01-10 15:15:26'),
(7, 7, 'supplier', 'po_created', 'New Purchase Order Created', 'A new purchase order PO-20260110-68B023 has been created for your company. Expected delivery date: Jan 11, 2026', 'purchase_order', 22, 1, '2026-01-10 16:01:42'),
(8, 1, 'admin', 'po_created', 'New Purchase Order Created', 'Purchase Order PO-20260110-68B023 has been created by Procurement Staff. Supplier: Incheon Food Export. Total Amount: ₱147,190.00. Expected delivery: Jan 11, 2026', 'purchase_order', 22, 1, '2026-01-10 16:01:42'),
(9, 1, 'admin', 'po_created', 'New Purchase Order Created', 'Purchase Order PO-20260110-697A78 has been created by Procurement Staff. Supplier: KFOOD. Total Amount: ₱15,900.00. Expected delivery: Jan 11, 2026', 'purchase_order', 23, 1, '2026-01-10 16:01:42'),
(10, 7, 'supplier', 'po_admin_approved', 'Purchase Order Approved by Admin', 'Purchase Order PO-20260110-68B023 has been approved by admin. Please review and approve the order.', 'purchase_order', 22, 1, '2026-01-10 16:02:39'),
(11, 7, 'supplier', 'po_admin_approved', 'Purchase Order Approved by Admin', 'Purchase Order PO-20260110-68B023 has been approved by admin. Please review and approve the order.', 'purchase_order', 22, 1, '2026-01-10 16:02:51'),
(12, 4, 'logistics_manager', 'shipment_scheduled', 'Shipment Scheduled', 'Shipment SHIP-20260110-D0910F has been scheduled by supplier for Purchase Order PO-20260110-68B023. Scheduled delivery date: Jan 11, 2026', 'shipment', 36, 1, '2026-01-10 16:19:25'),
(13, 1, 'admin', 'shipment_in_transit', 'Shipment In Transit', 'Shipment SHIP-20260110-D0910F for Purchase Order PO-20260110-68B023 is now IN TRANSIT. Date: Jan 10, 2026', 'shipment', 36, 1, '2026-01-10 16:59:33'),
(14, 7, 'supplier', 'shipment_in_transit', 'Shipment In Transit', 'Shipment SHIP-20260110-D0910F for Purchase Order PO-20260110-68B023 is now IN TRANSIT. Date: Jan 10, 2026', 'shipment', 36, 1, '2026-01-10 16:59:33'),
(15, 2, 'procurement_staff', 'shipment_in_transit', 'Shipment In Transit', 'Shipment SHIP-20260110-D0910F for Purchase Order PO-20260110-68B023 is now IN TRANSIT. Date: Jan 10, 2026', 'shipment', 36, 1, '2026-01-10 16:59:33'),
(16, 1, 'admin', 'transfer_request_created', 'New Warehouse Transfer Request', 'A new warehouse transfer request TRF-20260110-C7F0EC has been created and requires your approval.', 'warehouse_transfer', 12, 1, '2026-01-10 18:44:28'),
(17, 3, 'warehouse_officer', 'transfer_approved', 'Transfer Request Approved', 'Your transfer request TRF-20260110-C7F0EC has been approved by admin. Logistics will now book the shipment.', 'warehouse_transfer', 12, 0, '2026-01-10 18:44:47'),
(18, 4, 'logistics_manager', 'transfer_approved_for_shipment', 'Transfer Request Approved - Book Shipment', 'Transfer request TRF-20260110-C7F0EC has been approved. Please book the shipment for this transfer.', 'warehouse_transfer', 12, 1, '2026-01-10 18:44:47'),
(19, 7, 'supplier', 'po_created', 'New Purchase Order Created', 'A new purchase order PO-20260111-4525C4 has been created for your company. Expected delivery date: Jan 17, 2026', 'purchase_order', 24, 1, '2026-01-11 10:30:12'),
(20, 1, 'admin', 'po_created', 'New Purchase Order Created', 'Purchase Order PO-20260111-4525C4 has been created by Procurement Staff. Supplier: Incheon Food Export. Total Amount: ₱33,102.00. Expected delivery: Jan 17, 2026', 'purchase_order', 24, 1, '2026-01-11 10:30:12'),
(21, 7, 'supplier', 'po_admin_approved', 'Purchase Order Approved by Admin', 'Purchase Order PO-20260111-4525C4 has been approved by admin. Please review and approve the order.', 'purchase_order', 24, 1, '2026-01-11 10:30:47'),
(22, 1, 'admin', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-20260111-4525C4 has been fully approved by both admin and supplier. The supplier can now schedule the shipment.', 'purchase_order', 24, 1, '2026-01-11 10:31:05'),
(23, 2, 'procurement_staff', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-20260111-4525C4 has been fully approved by both admin and supplier. The supplier can now schedule the shipment.', 'purchase_order', 24, 1, '2026-01-11 10:31:05'),
(24, 7, 'supplier', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-20260111-4525C4 has been fully approved. You can now schedule the shipment.', 'purchase_order', 24, 1, '2026-01-11 10:31:05'),
(25, 4, 'logistics_manager', 'shipment_scheduled', 'Shipment Scheduled', 'Shipment SHIP-20260111-FAD5AE has been scheduled by supplier for Purchase Order PO-20260111-4525C4. Scheduled delivery date: Jan 11, 2026', 'shipment', 37, 1, '2026-01-11 10:31:43'),
(26, 1, 'admin', 'shipment_in_transit', 'Shipment In Transit', 'Shipment SHIP-20260111-FAD5AE for Purchase Order PO-20260111-4525C4 is now IN TRANSIT. Date: Jan 11, 2026', 'shipment', 37, 1, '2026-01-11 10:32:23'),
(27, 7, 'supplier', 'shipment_in_transit', 'Shipment In Transit', 'Shipment SHIP-20260111-FAD5AE for Purchase Order PO-20260111-4525C4 is now IN TRANSIT. Date: Jan 11, 2026', 'shipment', 37, 1, '2026-01-11 10:32:23'),
(28, 2, 'procurement_staff', 'shipment_in_transit', 'Shipment In Transit', 'Shipment SHIP-20260111-FAD5AE for Purchase Order PO-20260111-4525C4 is now IN TRANSIT. Date: Jan 11, 2026', 'shipment', 37, 1, '2026-01-11 10:32:23'),
(29, 3, 'warehouse_officer', 'transfer_shipment_booked', 'Shipment Booked for Transfer', 'A shipment has been booked for transfer request TRF-20260110-C7F0EC. Shipment #: SHIP-20260111-C2173C', 'warehouse_transfer', 12, 0, '2026-01-11 10:34:04'),
(30, 3, 'warehouse_officer', 'transfer_shipment_in_transit', 'Transfer Shipment In Transit', 'Shipment SHIP-20260111-C2173C for transfer request TRF-20260110-C7F0EC is now IN TRANSIT. Date: Jan 11, 2026', 'warehouse_transfer', 12, 0, '2026-01-11 11:03:44'),
(31, 3, 'warehouse_officer', 'transfer_shipment_delivered', 'Transfer Shipment Delivered', 'Shipment SHIP-20260111-C2173C for transfer request TRF-20260110-C7F0EC has been delivered. Delivery date: Jan 11, 2026', 'warehouse_transfer', 12, 0, '2026-01-11 11:09:24'),
(32, 3, 'warehouse_officer', 'transfer_completed', 'Transfer Completed', 'Transfer request TRF-20260110-C7F0EC has been completed. All items have been transferred and inventory has been updated.', 'warehouse_transfer', 12, 0, '2026-01-11 11:10:19'),
(33, 2, 'procurement_staff', 'po_processed', 'Purchase Order Processed by Logistics', 'Purchase Order PO-20260111-4525C4 has been processed and completed by logistics. All shipments have been delivered and inventory has been updated.', 'purchase_order', 24, 1, '2026-01-11 11:16:51'),
(34, 7, 'supplier', 'po_processed', 'Purchase Order Completed', 'Purchase Order PO-20260111-4525C4 has been processed by logistics. All shipments have been delivered successfully.', 'purchase_order', 24, 1, '2026-01-11 11:16:51'),
(35, 2, 'procurement_staff', 'po_processed', 'Purchase Order Processed by Logistics', 'Purchase Order PO-20260110-68B023 has been processed and completed by logistics. All shipments have been delivered and inventory has been updated.', 'purchase_order', 22, 1, '2026-01-11 11:28:56'),
(36, 7, 'supplier', 'po_processed', 'Purchase Order Completed', 'Purchase Order PO-20260110-68B023 has been processed by logistics. All shipments have been delivered successfully.', 'purchase_order', 22, 1, '2026-01-11 11:28:56'),
(37, 1, 'admin', 'transfer_request_created', 'New Warehouse Transfer Request', 'A new warehouse transfer request TRF-20260111-718FC6 has been created and requires your approval.', 'warehouse_transfer', 13, 1, '2026-01-11 11:33:11'),
(38, 1, 'warehouse_officer', 'transfer_approved', 'Transfer Request Approved', 'Your transfer request TRF-20260111-718FC6 has been approved by admin. Logistics will now book the shipment.', 'warehouse_transfer', 13, 1, '2026-01-11 11:33:15'),
(39, 4, 'logistics_manager', 'transfer_approved_for_shipment', 'Transfer Request Approved - Book Shipment', 'Transfer request TRF-20260111-718FC6 has been approved. Please book the shipment for this transfer.', 'warehouse_transfer', 13, 0, '2026-01-11 11:33:15'),
(40, 1, 'warehouse_officer', 'transfer_shipment_booked', 'Shipment Booked for Transfer', 'A shipment has been booked for transfer request TRF-20260111-718FC6. Shipment #: SHIP-20260111-8710B9', 'warehouse_transfer', 13, 1, '2026-01-11 11:33:44'),
(41, 1, 'warehouse_officer', 'transfer_shipment_delivered', 'Transfer Shipment Delivered', 'Shipment SHIP-20260111-8710B9 for transfer request TRF-20260111-718FC6 has been delivered. Delivery date: Jan 11, 2026', 'warehouse_transfer', 13, 1, '2026-01-11 11:33:54'),
(42, 1, 'warehouse_officer', 'transfer_completed', 'Transfer Completed', 'Transfer request TRF-20260111-718FC6 has been completed. All items have been transferred and inventory has been updated.', 'warehouse_transfer', 13, 1, '2026-01-11 11:34:11'),
(43, 1, 'admin', 'transfer_request_created', 'New Warehouse Transfer Request', 'A new warehouse transfer request TRF-20260111-415EF2 has been created and requires your approval.', 'warehouse_transfer', 14, 1, '2026-01-11 11:35:32'),
(44, 1, 'warehouse_officer', 'transfer_approved', 'Transfer Request Approved', 'Your transfer request TRF-20260111-415EF2 has been approved by admin. Logistics will now book the shipment.', 'warehouse_transfer', 14, 1, '2026-01-11 11:35:35'),
(45, 4, 'logistics_manager', 'transfer_approved_for_shipment', 'Transfer Request Approved - Book Shipment', 'Transfer request TRF-20260111-415EF2 has been approved. Please book the shipment for this transfer.', 'warehouse_transfer', 14, 0, '2026-01-11 11:35:35'),
(46, 1, 'warehouse_officer', 'transfer_shipment_booked', 'Shipment Booked for Transfer', 'A shipment has been booked for transfer request TRF-20260111-415EF2. Shipment #: SHIP-20260111-A23EAD', 'warehouse_transfer', 14, 1, '2026-01-11 11:35:54'),
(47, 1, 'warehouse_officer', 'transfer_shipment_delivered', 'Transfer Shipment Delivered', 'Shipment SHIP-20260111-A23EAD for transfer request TRF-20260111-415EF2 has been delivered. Delivery date: Jan 11, 2026', 'warehouse_transfer', 14, 1, '2026-01-11 11:36:02'),
(48, 1, 'warehouse_officer', 'transfer_completed', 'Transfer Completed', 'Transfer request TRF-20260111-415EF2 has been completed. All items have been transferred and inventory has been updated.', 'warehouse_transfer', 14, 1, '2026-01-11 11:36:14'),
(49, 7, 'supplier', 'po_created', 'New Automatic Purchase Order Created', 'A new automatic purchase order PO-AUTO-20260111-B6802D has been created for your company. Expected delivery date: Jan 18, 2026. This order requires admin approval before processing.', 'purchase_order', 25, 1, '2026-01-11 12:23:23'),
(50, 1, 'admin', 'po_created', 'Automatic Purchase Order Requires Approval', 'Automatic Purchase Order PO-AUTO-20260111-B6802D has been created by Procurement Staff from low stock alert. Supplier: Incheon Food Export. Total Amount: ₱23,592.24. Expected delivery: Jan 18, 2026. Please review and approve.', 'purchase_order', 25, 1, '2026-01-11 12:23:23'),
(51, 7, 'supplier', 'po_admin_approved', 'Purchase Order Approved by Admin', 'Purchase Order PO-AUTO-20260111-B6802D has been approved by admin. Please review and approve the order.', 'purchase_order', 25, 1, '2026-01-11 12:23:43'),
(52, 7, 'supplier', 'po_admin_approved', 'Purchase Order Approved by Admin', 'Purchase Order PO-AUTO-20260111-B6802D has been approved by admin. Please review and approve the order.', 'purchase_order', 25, 1, '2026-01-11 12:24:10'),
(53, 7, 'supplier', 'po_created', 'New Automatic Purchase Order Created', 'A new automatic purchase order PO-AUTO-20260111-0ECF02 has been created for your company. Expected delivery date: Jan 18, 2026. This order requires admin approval before processing.', 'purchase_order', 26, 1, '2026-01-11 12:25:04'),
(54, 1, 'admin', 'po_created', 'Automatic Purchase Order Requires Approval', 'Automatic Purchase Order PO-AUTO-20260111-0ECF02 has been created by Procurement Staff from low stock alert. Supplier: Incheon Food Export. Total Amount: ₱23,592.24. Expected delivery: Jan 18, 2026. Please review and approve.', 'purchase_order', 26, 1, '2026-01-11 12:25:04'),
(55, 7, 'supplier', 'po_admin_approved', 'Purchase Order Approved by Admin', 'Purchase Order PO-AUTO-20260111-0ECF02 has been approved by admin. Please review and approve the order.', 'purchase_order', 26, 1, '2026-01-11 12:25:12'),
(56, 7, 'supplier', 'po_admin_approved', 'Purchase Order Approved by Admin', 'Purchase Order PO-AUTO-20260111-0ECF02 has been approved by admin. Please review and approve the order.', 'purchase_order', 26, 1, '2026-01-11 12:29:22'),
(57, 1, 'admin', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-AUTO-20260111-0ECF02 has been fully approved by both admin and supplier. The supplier can now schedule the shipment.', 'purchase_order', 26, 1, '2026-01-11 12:29:31'),
(58, 2, 'procurement_staff', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-AUTO-20260111-0ECF02 has been fully approved by both admin and supplier. The supplier can now schedule the shipment.', 'purchase_order', 26, 1, '2026-01-11 12:29:31'),
(59, 7, 'supplier', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-AUTO-20260111-0ECF02 has been fully approved. You can now schedule the shipment.', 'purchase_order', 26, 1, '2026-01-11 12:29:31'),
(60, 4, 'logistics_manager', 'shipment_scheduled', 'Shipment Scheduled', 'Shipment SHIP-20260111-82BBE6 has been scheduled by supplier for Purchase Order PO-AUTO-20260111-0ECF02. Scheduled delivery date: Jan 18, 2026', 'shipment', 41, 0, '2026-01-11 12:30:00'),
(61, 1, 'admin', 'shipment_in_transit', 'Shipment In Transit', 'Shipment SHIP-20260111-82BBE6 for Purchase Order PO-AUTO-20260111-0ECF02 is now IN TRANSIT. Date: Jan 11, 2026', 'shipment', 41, 1, '2026-01-11 12:30:27'),
(62, 7, 'supplier', 'shipment_in_transit', 'Shipment In Transit', 'Shipment SHIP-20260111-82BBE6 for Purchase Order PO-AUTO-20260111-0ECF02 is now IN TRANSIT. Date: Jan 11, 2026', 'shipment', 41, 1, '2026-01-11 12:30:27'),
(63, 2, 'procurement_staff', 'shipment_in_transit', 'Shipment In Transit', 'Shipment SHIP-20260111-82BBE6 for Purchase Order PO-AUTO-20260111-0ECF02 is now IN TRANSIT. Date: Jan 11, 2026', 'shipment', 41, 1, '2026-01-11 12:30:27'),
(64, 2, 'procurement_staff', 'po_processed', 'Purchase Order Processed by Logistics', 'Purchase Order PO-AUTO-20260111-0ECF02 has been processed and completed by logistics. All shipments have been delivered and inventory has been updated.', 'purchase_order', 26, 1, '2026-01-11 12:30:35'),
(65, 7, 'supplier', 'po_processed', 'Purchase Order Completed', 'Purchase Order PO-AUTO-20260111-0ECF02 has been processed by logistics. All shipments have been delivered successfully.', 'purchase_order', 26, 1, '2026-01-11 12:30:35'),
(66, 7, 'supplier', 'po_created', 'New Automatic Purchase Order Created', 'A new automatic purchase order PO-AUTO-20260111-50EE1B has been created for your company. Expected delivery date: Jan 18, 2026. This order requires admin approval before processing.', 'purchase_order', 27, 1, '2026-01-11 12:31:17'),
(67, 1, 'admin', 'po_created', 'Automatic Purchase Order Requires Approval', 'Automatic Purchase Order PO-AUTO-20260111-50EE1B has been created by Procurement Staff from low stock alert. Supplier: Incheon Food Export. Total Amount: ₱3,168.00. Expected delivery: Jan 18, 2026. Please review and approve.', 'purchase_order', 27, 1, '2026-01-11 12:31:17'),
(68, 7, 'supplier', 'po_admin_approved', 'Purchase Order Approved by Admin', 'Purchase Order PO-AUTO-20260111-50EE1B has been approved by admin. Please review and approve the order.', 'purchase_order', 27, 1, '2026-01-11 12:31:33'),
(69, 1, 'admin', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-AUTO-20260111-50EE1B has been fully approved by both admin and supplier. The supplier can now schedule the shipment.', 'purchase_order', 27, 1, '2026-01-11 12:31:42'),
(70, 2, 'procurement_staff', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-AUTO-20260111-50EE1B has been fully approved by both admin and supplier. The supplier can now schedule the shipment.', 'purchase_order', 27, 1, '2026-01-11 12:31:42'),
(71, 7, 'supplier', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-AUTO-20260111-50EE1B has been fully approved. You can now schedule the shipment.', 'purchase_order', 27, 1, '2026-01-11 12:31:42'),
(72, 7, 'supplier', 'po_created', 'New Automatic Purchase Order Created', 'A new automatic purchase order PO-AUTO-20260111-3D6746 has been created for your company. Expected delivery date: Jan 18, 2026. This order requires admin approval before processing.', 'purchase_order', 28, 1, '2026-01-11 12:38:59'),
(73, 1, 'admin', 'po_created', 'Automatic Purchase Order Requires Approval', 'Automatic Purchase Order PO-AUTO-20260111-3D6746 has been created by Procurement Staff from low stock alert. Supplier: Incheon Food Export. Total Amount: ₱3,168.00. Expected delivery: Jan 18, 2026. Please review and approve.', 'purchase_order', 28, 1, '2026-01-11 12:38:59'),
(74, 7, 'supplier', 'po_admin_approved', 'Purchase Order Approved by Admin', 'Purchase Order PO-AUTO-20260111-3D6746 has been approved by admin. Please review and approve the order.', 'purchase_order', 28, 1, '2026-01-11 12:39:14'),
(75, 1, 'admin', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-AUTO-20260111-3D6746 has been fully approved by both admin and supplier. The supplier can now schedule the shipment.', 'purchase_order', 28, 1, '2026-01-11 12:42:10'),
(76, 2, 'procurement_staff', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-AUTO-20260111-3D6746 has been fully approved by both admin and supplier. The supplier can now schedule the shipment.', 'purchase_order', 28, 1, '2026-01-11 12:42:10'),
(77, 7, 'supplier', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-AUTO-20260111-3D6746 has been fully approved. You can now schedule the shipment.', 'purchase_order', 28, 1, '2026-01-11 12:42:10'),
(78, 4, 'logistics_manager', 'shipment_scheduled', 'Shipment Scheduled', 'Shipment SHIP-20260111-B715A5 has been scheduled by supplier for Purchase Order PO-AUTO-20260111-3D6746. Scheduled delivery date: Jan 18, 2026', 'shipment', 42, 0, '2026-01-11 12:47:23'),
(79, 2, 'procurement_staff', 'po_processed', 'Purchase Order Processed by Logistics', 'Purchase Order PO-AUTO-20260111-3D6746 has been processed and completed by logistics. All shipments have been delivered and inventory has been updated.', 'purchase_order', 28, 1, '2026-01-11 12:47:56'),
(80, 7, 'supplier', 'po_processed', 'Purchase Order Completed', 'Purchase Order PO-AUTO-20260111-3D6746 has been processed by logistics. All shipments have been delivered successfully.', 'purchase_order', 28, 1, '2026-01-11 12:47:56'),
(81, 1, 'admin', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-TEST-DELAY-20260111-002 has been fully approved by both admin and supplier. The supplier can now schedule the shipment.', 'purchase_order', 30, 0, '2026-01-11 14:47:07'),
(82, 1, 'admin', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-TEST-DELAY-20260111-002 has been fully approved by both admin and supplier. The supplier can now schedule the shipment.', 'purchase_order', 30, 0, '2026-01-11 14:47:07'),
(83, 7, 'supplier', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-TEST-DELAY-20260111-002 has been fully approved. You can now schedule the shipment.', 'purchase_order', 30, 1, '2026-01-11 14:47:07'),
(84, 2, 'procurement_staff', 'delay_requested', 'Delay Request Received', 'Supplier has requested a delay for Purchase Order PO-TEST-DELAY-20260111-002. New requested delivery date: Jan 11, 2026. Please review and respond.', 'purchase_order', 30, 1, '2026-01-11 14:51:20'),
(85, 1, 'admin', 'delay_requested', 'Delay Request Received', 'Supplier has requested a delay for Purchase Order PO-TEST-DELAY-20260111-002. New requested delivery date: Jan 11, 2026. Please review and respond.', 'purchase_order', 30, 0, '2026-01-11 14:51:20'),
(86, 7, 'supplier', 'delay_request_approved', 'Delay Request Approved', 'Your delay request for Purchase Order PO-TEST-DELAY-20260111-002 has been approved. New delivery date: Jan 11, 2026', 'purchase_order', 30, 0, '2026-01-11 14:55:02'),
(87, 1, 'admin', 'po_supplier_approved', 'Supplier Approved Purchase Order', 'Purchase Order PO-TEST-DELAY-20260111-001 has been approved by the supplier. Waiting for admin approval.', 'purchase_order', 29, 0, '2026-01-11 15:04:45'),
(88, 7, 'supplier', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-TEST-DELAY-20260111-001 has been fully approved by both admin and supplier. You can now schedule the shipment.', 'purchase_order', 29, 0, '2026-01-11 15:04:55'),
(89, 1, 'admin', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-TEST-DELAY-20260111-001 that you created has been fully approved by both admin and supplier. The supplier can now schedule the shipment.', 'purchase_order', 29, 0, '2026-01-11 15:04:55'),
(90, 7, 'supplier', 'po_created', 'New Purchase Order Created', 'A new purchase order PO-20260111-16A1D3 has been created for your company. Expected delivery date: Jan 11, 2026', 'purchase_order', 31, 0, '2026-01-11 15:32:01'),
(91, 1, 'admin', 'po_created', 'New Purchase Order Created', 'Purchase Order PO-20260111-16A1D3 has been created by System Administrator. Supplier: Incheon Food Export. Total Amount: ₱43,645.50. Expected delivery: Jan 11, 2026', 'purchase_order', 31, 0, '2026-01-11 15:32:01'),
(92, 7, 'supplier', 'po_admin_approved', 'Purchase Order Approved by Admin', 'Purchase Order PO-20260111-16A1D3 has been approved by admin. Please review and approve the order.', 'purchase_order', 31, 0, '2026-01-11 15:32:05'),
(93, 1, 'admin', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-20260111-16A1D3 has been fully approved by both admin and supplier. The supplier can now schedule the shipment.', 'purchase_order', 31, 0, '2026-01-11 15:32:17'),
(94, 1, 'admin', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-20260111-16A1D3 has been fully approved by both admin and supplier. The supplier can now schedule the shipment.', 'purchase_order', 31, 0, '2026-01-11 15:32:17'),
(95, 7, 'supplier', 'po_fully_approved', 'Purchase Order Fully Approved', 'Purchase Order PO-20260111-16A1D3 has been fully approved. You can now schedule the shipment.', 'purchase_order', 31, 0, '2026-01-11 15:32:17'),
(96, 4, 'logistics_manager', 'shipment_scheduled', 'Shipment Scheduled', 'Shipment SHIP-20260111-2C407B has been scheduled by supplier for Purchase Order PO-20260111-16A1D3. Scheduled delivery date: Jan 11, 2026', 'shipment', 43, 0, '2026-01-11 15:32:50'),
(97, 1, 'admin', 'all_shipments_delivered', 'All Shipments Delivered - Ready to Mark as Received', 'All shipments for Purchase Order PO-20260111-16A1D3 have been delivered. Please mark the PO as \'Received\' to update inventory.', 'purchase_order', 31, 0, '2026-01-11 15:33:48'),
(98, 2, 'procurement_staff', 'all_shipments_delivered', 'All Shipments Delivered - Ready to Mark as Received', 'All shipments for Purchase Order PO-20260111-16A1D3 have been delivered. Please mark the PO as \'Received\' to update inventory.', 'purchase_order', 31, 0, '2026-01-11 15:33:48');

-- --------------------------------------------------------

--
-- Table structure for table `po_notifications`
--

CREATE TABLE `po_notifications` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `notification_type` enum('po_approved','po_cancelled','shipment_scheduled') NOT NULL,
  `message` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `po_notifications`
--

INSERT INTO `po_notifications` (`id`, `po_id`, `supplier_id`, `notification_type`, `message`, `is_read`, `created_at`) VALUES
(7, 22, 22, 'po_approved', 'Purchase Order PO-20260110-68B023 has been approved by admin. Waiting for your approval.', 0, '2026-01-10 16:02:39'),
(8, 22, 22, 'po_approved', 'Purchase Order PO-20260110-68B023 has been approved by admin. Waiting for your approval.', 0, '2026-01-10 16:02:51'),
(9, 22, 22, 'shipment_scheduled', 'Shipment SHIP-20260110-D0910F has been scheduled by supplier for PO PO-20260110-68B023.', 0, '2026-01-10 16:19:25'),
(10, 24, 22, 'po_approved', 'Purchase Order PO-20260111-4525C4 has been approved by admin. Waiting for your approval.', 1, '2026-01-11 10:30:47'),
(11, 24, 22, 'shipment_scheduled', 'Shipment SHIP-20260111-FAD5AE has been scheduled by supplier for PO PO-20260111-4525C4.', 1, '2026-01-11 10:31:43'),
(14, 26, 22, 'po_approved', 'Purchase Order PO-AUTO-20260111-0ECF02 has been approved by admin. Waiting for your approval.', 1, '2026-01-11 12:25:12'),
(15, 26, 22, 'po_approved', 'Purchase Order PO-AUTO-20260111-0ECF02 has been approved by admin. Waiting for your approval.', 1, '2026-01-11 12:29:22'),
(16, 26, 22, 'shipment_scheduled', 'Shipment SHIP-20260111-82BBE6 has been scheduled by supplier for PO PO-AUTO-20260111-0ECF02.', 1, '2026-01-11 12:30:00'),
(17, 27, 22, 'po_approved', 'Purchase Order PO-AUTO-20260111-50EE1B has been approved by admin. Waiting for your approval.', 1, '2026-01-11 12:31:33'),
(18, 28, 22, 'po_approved', 'Purchase Order PO-AUTO-20260111-3D6746 has been approved by admin. Waiting for your approval.', 1, '2026-01-11 12:39:14'),
(19, 28, 22, 'shipment_scheduled', 'Shipment SHIP-20260111-B715A5 has been scheduled by supplier for PO PO-AUTO-20260111-3D6746.', 1, '2026-01-11 12:47:23'),
(20, 29, 22, 'po_approved', 'Purchase Order PO-TEST-DELAY-20260111-001 has been fully approved. You can now schedule the shipment.', 0, '2026-01-11 15:04:55'),
(21, 31, 22, 'po_approved', 'Purchase Order PO-20260111-16A1D3 has been approved by admin. Waiting for your approval.', 1, '2026-01-11 15:32:05'),
(22, 31, 22, 'shipment_scheduled', 'Shipment SHIP-20260111-2C407B has been scheduled by supplier for PO PO-20260111-16A1D3.', 0, '2026-01-11 15:32:50');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `min_stock_level` int(11) DEFAULT 10,
  `unit` varchar(20) DEFAULT 'pcs',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `sku`, `name`, `description`, `category`, `unit_price`, `min_stock_level`, `unit`, `created_at`, `updated_at`) VALUES
(256, 'IFE-117', 'Buldak Quattro Cheese', NULL, NULL, 683.76, 32, 'pcs', '2026-01-03 20:25:14', '2026-01-03 20:25:14'),
(257, 'KF-109', 'Buldak Carbonara', NULL, NULL, 683.76, 30, 'pcs', '2026-01-03 20:25:14', '2026-01-03 20:25:14'),
(258, 'IFE-118', 'Buldak Cream Carbonara', NULL, NULL, 683.76, 32, 'pcs', '2026-01-03 20:25:14', '2026-01-03 20:25:14'),
(260, 'IFE-119', 'Buldak Original', NULL, NULL, 872.91, 44, 'pcs', '2026-01-03 20:25:15', '2026-01-03 20:25:15'),
(261, 'KF-111', 'Buldak 2x', NULL, NULL, 809.86, 43, 'pcs', '2026-01-03 20:25:15', '2026-01-03 20:25:15'),
(262, 'IFE-120', 'Buldak 3x', NULL, NULL, 809.86, 32, 'pcs', '2026-01-03 20:25:15', '2026-01-03 20:25:15'),
(263, 'KF-112', 'Jin Ramen Mild', NULL, NULL, 447.34, 30, 'pcs', '2026-01-03 20:25:15', '2026-01-03 20:25:15'),
(264, 'IFE-121', 'Jin Ramen Spicy', NULL, NULL, 447.34, 34, 'pcs', '2026-01-03 20:25:15', '2026-01-03 20:25:15'),
(265, 'KF-113', 'Shin Ramen', NULL, NULL, 510.38, 43, 'pcs', '2026-01-03 20:25:15', '2026-01-03 20:25:15'),
(266, 'IFE-122', 'Shin Small Cup', NULL, NULL, 541.91, 40, 'pcs', '2026-01-03 20:25:15', '2026-01-03 20:25:15'),
(267, 'KF-114', 'Shin Big Cup', NULL, NULL, 456.79, 32, 'pcs', '2026-01-03 20:25:15', '2026-01-03 20:25:15'),
(268, 'IFE-123', 'Jin Ramen Small Cup', NULL, NULL, 99.00, 31, 'pcs', '2026-01-03 20:25:16', '2026-01-03 20:25:16'),
(269, 'KF-115', 'Jin Ramen Spicy Cup', NULL, NULL, 99.00, 41, 'pcs', '2026-01-03 20:25:16', '2026-01-03 20:25:16'),
(270, 'IFE-124', 'Pororo', NULL, NULL, 331.02, 45, 'pcs', '2026-01-03 20:25:16', '2026-01-03 20:25:16'),
(271, 'KF-116', 'Binggrae Strawberry', NULL, NULL, 331.02, 36, 'pcs', '2026-01-03 20:25:16', '2026-01-03 20:25:16'),
(272, 'IFE-125', 'Binggrae Banana', NULL, NULL, 331.02, 32, 'pcs', '2026-01-03 20:25:16', '2026-01-03 20:25:16'),
(273, 'KF-117', 'Binggrae Taro', NULL, NULL, 331.02, 46, 'pcs', '2026-01-03 20:25:16', '2026-01-03 20:25:16'),
(274, 'IFE-126', 'Binggrae Coffee', NULL, NULL, 331.02, 40, 'pcs', '2026-01-03 20:25:16', '2026-01-03 20:25:16'),
(275, 'KF-118', 'Binggrae Vanilla', NULL, NULL, 331.02, 33, 'pcs', '2026-01-03 20:25:17', '2026-01-03 20:25:17'),
(276, 'IFE-127', 'Binggrae Melon', NULL, NULL, 331.02, 48, 'pcs', '2026-01-03 20:25:17', '2026-01-03 20:25:17'),
(277, 'KF-119', 'Binggrae Chestnut', NULL, NULL, 331.02, 39, 'pcs', '2026-01-03 20:25:17', '2026-01-03 20:25:17'),
(278, 'IFE-128', 'Coke', NULL, NULL, 337.33, 45, 'pcs', '2026-01-03 20:25:17', '2026-01-03 20:25:17'),
(279, 'KF-120', 'Sprite', NULL, NULL, 337.33, 34, 'pcs', '2026-01-03 20:25:17', '2026-01-03 20:25:17'),
(280, 'IFE-129', 'Royal', NULL, NULL, 337.33, 38, 'pcs', '2026-01-03 20:25:17', '2026-01-03 20:25:17'),
(328, 'IFE-001', 'Buldak Quattro Cheese', NULL, NULL, 683.76, 30, 'pcs', '2026-01-07 15:00:15', '2026-01-07 15:00:15'),
(335, 'K-001', 'Nori', NULL, NULL, 159.00, 25, 'pcs', '2026-01-08 01:16:35', '2026-01-08 01:16:35'),
(336, 'K-002', 'Nori', NULL, NULL, 159.00, 25, 'pcs', '2026-01-08 01:16:40', '2026-01-08 01:16:40'),
(337, 'K-003', 'Dumplings', NULL, NULL, 159.00, 25, 'pcs', '2026-01-08 01:17:36', '2026-01-08 01:17:36');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` int(11) NOT NULL,
  `po_number` varchar(50) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `order_date` date NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `status` enum('pending','approved','scheduled','in_transit','received','cancelled') DEFAULT 'pending',
  `admin_approved` tinyint(1) DEFAULT 0,
  `admin_approved_by` int(11) DEFAULT NULL,
  `admin_approved_at` timestamp NULL DEFAULT NULL,
  `supplier_approved` tinyint(1) DEFAULT 0,
  `supplier_approved_by` int(11) DEFAULT NULL,
  `supplier_approved_at` timestamp NULL DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `delay_status` enum('none','requested','approved','rejected') DEFAULT 'none' COMMENT 'Status of delay request: none, requested by supplier, approved, or rejected by procurement',
  `delay_requested_date` date DEFAULT NULL COMMENT 'New delivery date requested by supplier',
  `delay_notes` text DEFAULT NULL COMMENT 'Notes from supplier explaining the delay',
  `delay_response_notes` text DEFAULT NULL COMMENT 'Notes from procurement staff when responding to delay request',
  `delay_requested_at` timestamp NULL DEFAULT NULL COMMENT 'When supplier requested the delay',
  `delay_responded_at` timestamp NULL DEFAULT NULL COMMENT 'When procurement responded to delay request',
  `delay_responded_by` int(11) DEFAULT NULL COMMENT 'User ID of procurement staff who responded'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`id`, `po_number`, `supplier_id`, `created_by`, `order_date`, `delivery_date`, `status`, `admin_approved`, `admin_approved_by`, `admin_approved_at`, `supplier_approved`, `supplier_approved_by`, `supplier_approved_at`, `total_amount`, `notes`, `created_at`, `updated_at`, `delay_status`, `delay_requested_date`, `delay_notes`, `delay_response_notes`, `delay_requested_at`, `delay_responded_at`, `delay_responded_by`) VALUES
(22, 'PO-20260110-68B023', 22, 2, '2026-01-11', '2026-01-11', 'received', 1, 1, '2026-01-10 16:02:39', 0, NULL, NULL, 147190.00, '', '2026-01-10 16:01:42', '2026-01-11 11:28:56', 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(24, 'PO-20260111-4525C4', 22, 2, '2026-01-11', '2026-01-17', 'received', 1, 1, '2026-01-11 10:30:47', 1, 7, '2026-01-11 10:31:05', 33102.00, '', '2026-01-11 10:30:12', '2026-01-11 11:16:51', 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(26, 'PO-AUTO-20260111-0ECF02', 22, 2, '2026-01-11', '2026-01-18', 'received', 1, 1, '2026-01-11 12:25:12', 1, 7, '2026-01-11 12:29:30', 23592.24, 'Automatic order generated from low stock alert', '2026-01-11 12:25:04', '2026-01-11 12:30:35', 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(27, 'PO-AUTO-20260111-50EE1B', 22, 2, '2026-01-11', '2026-01-18', 'cancelled', 1, 1, '2026-01-11 12:31:33', 1, 7, '2026-01-11 12:31:41', 3168.00, 'Automatic order generated from low stock alert', '2026-01-11 12:31:17', '2026-01-11 12:38:17', 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(28, 'PO-AUTO-20260111-3D6746', 22, 2, '2026-01-11', '2026-01-18', 'received', 1, 1, '2026-01-11 12:39:14', 1, 7, '2026-01-11 12:42:10', 3168.00, 'Automatic order generated from low stock alert', '2026-01-11 12:38:59', '2026-01-11 12:47:56', 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(29, 'PO-TEST-DELAY-20260111-001', 22, 1, '2026-01-01', '2026-01-06', 'approved', 1, 1, '2026-01-11 15:04:55', 1, 7, '2026-01-11 15:04:45', 15000.00, 'Test purchase order for delay detection - Sample 1', '2026-01-11 14:46:42', '2026-01-11 15:04:55', 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(30, 'PO-TEST-DELAY-20260111-002', 22, 1, '2025-12-27', '2026-01-11', 'approved', 1, NULL, NULL, 1, 7, '2026-01-11 14:47:07', 25000.00, 'Test purchase order for delay detection - Sample 2', '2026-01-11 14:46:42', '2026-01-11 14:55:02', 'approved', '2026-01-11', 'Product Unavailability', '', '2026-01-11 14:51:20', '2026-01-11 14:55:02', 2),
(31, 'PO-20260111-16A1D3', 22, 1, '2026-01-11', '2026-01-11', 'received', 1, 1, '2026-01-11 15:32:05', 1, 7, '2026-01-11 15:32:17', 43645.50, '', '2026-01-11 15:32:01', '2026-01-11 15:34:04', 'none', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_items`
--

CREATE TABLE `purchase_order_items` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_order_items`
--

INSERT INTO `purchase_order_items` (`id`, `po_id`, `product_id`, `warehouse_id`, `quantity`, `unit_price`, `subtotal`) VALUES
(30, 22, 272, NULL, 100, 331.02, 33102.00),
(31, 22, 274, NULL, 100, 331.02, 33102.00),
(32, 22, 262, NULL, 100, 809.86, 80986.00),
(34, 24, 272, NULL, 100, 331.02, 33102.00),
(37, 26, 271, NULL, 62, 331.02, 20523.24),
(38, 26, 268, NULL, 31, 99.00, 3069.00),
(39, 27, 268, NULL, 32, 99.00, 3168.00),
(40, 28, 268, 3, 32, 99.00, 3168.00),
(41, 29, 328, NULL, 100, 150.00, 15000.00),
(42, 30, 328, NULL, 200, 125.00, 25000.00),
(43, 31, 260, NULL, 50, 872.91, 43645.50);

-- --------------------------------------------------------

--
-- Table structure for table `sales_history`
--

CREATE TABLE `sales_history` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `sale_date` date NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales_history`
--

INSERT INTO `sales_history` (`id`, `product_id`, `sale_date`, `quantity`, `unit_price`, `created_at`) VALUES
(1, 256, '2024-01-15', 45, 683.76, '2026-01-11 13:33:24'),
(2, 256, '2024-02-10', 52, 683.76, '2026-01-11 13:33:24'),
(3, 257, '2024-01-20', 30, 683.76, '2026-01-11 13:33:24'),
(4, 257, '2024-02-18', 35, 683.76, '2026-01-11 13:33:24'),
(5, 258, '2024-01-25', 15, 683.76, '2026-01-11 13:33:24'),
(6, 258, '2024-02-22', 18, 683.76, '2026-01-11 13:33:24'),
(7, 260, '2024-02-05', 120, 872.91, '2026-01-11 13:33:24'),
(8, 260, '2024-02-28', 135, 872.91, '2026-01-11 13:33:24'),
(9, 261, '2024-02-12', 8, 809.86, '2026-01-11 13:33:24'),
(10, 261, '2024-03-01', 10, 809.86, '2026-01-11 13:33:24'),
(11, 256, '2026-01-11', 15, 750.00, '2026-01-11 13:55:02'),
(12, 257, '2026-01-11', 20, 750.00, '2026-01-11 13:55:02'),
(13, 260, '2026-01-11', 10, 850.00, '2026-01-11 13:55:02'),
(14, 265, '2026-01-11', 25, 550.00, '2026-01-11 13:55:02'),
(15, 263, '2026-01-11', 30, 500.00, '2026-01-11 13:55:02'),
(16, 271, '2026-01-11', 12, 350.00, '2026-01-11 13:55:02'),
(17, 272, '2026-01-11', 8, 350.00, '2026-01-11 13:55:02'),
(18, 278, '2026-01-11', 24, 380.00, '2026-01-11 13:55:02'),
(19, 279, '2026-01-11', 18, 380.00, '2026-01-11 13:55:02'),
(20, 270, '2026-01-11', 5, 350.00, '2026-01-11 13:55:02'),
(21, 256, '2026-01-11', 15, 750.00, '2026-01-11 14:21:34'),
(22, 257, '2026-01-11', 20, 750.00, '2026-01-11 14:21:34'),
(23, 260, '2026-01-11', 10, 850.00, '2026-01-11 14:21:34'),
(24, 265, '2026-01-11', 25, 550.00, '2026-01-11 14:21:34'),
(25, 263, '2026-01-11', 30, 500.00, '2026-01-11 14:21:34'),
(26, 256, '2026-01-11', 22, 755.00, '2026-01-11 14:21:34'),
(27, 257, '2026-01-11', 28, 758.00, '2026-01-11 14:21:34'),
(28, 265, '2026-01-11', 35, 555.00, '2026-01-11 14:21:34'),
(29, 271, '2026-01-11', 18, 355.00, '2026-01-11 14:21:34'),
(30, 278, '2026-01-11', 32, 385.00, '2026-01-11 14:21:34'),
(31, 256, '2025-01-01', 15, 750.00, '2026-01-11 14:23:10'),
(32, 257, '2025-01-01', 20, 750.00, '2026-01-11 14:23:10'),
(33, 260, '2025-01-01', 10, 850.00, '2026-01-11 14:23:10'),
(34, 265, '2025-01-01', 25, 550.00, '2026-01-11 14:23:10'),
(35, 263, '2025-01-01', 30, 500.00, '2026-01-11 14:23:10'),
(36, 256, '2025-01-01', 22, 755.00, '2026-01-11 14:23:10'),
(37, 257, '2025-01-01', 28, 758.00, '2026-01-11 14:23:10'),
(38, 265, '2025-01-01', 35, 555.00, '2026-01-11 14:23:10'),
(39, 271, '2025-01-01', 18, 355.00, '2026-01-11 14:23:10'),
(40, 278, '2025-01-01', 32, 385.00, '2026-01-11 14:23:10'),
(41, 272, '2025-01-01', 12, 352.00, '2026-01-11 14:23:10'),
(42, 279, '2025-01-01', 25, 382.00, '2026-01-11 14:23:10'),
(43, 273, '2025-01-01', 10, 355.00, '2026-01-11 14:23:10'),
(44, 270, '2025-01-01', 8, 350.00, '2026-01-11 14:23:10'),
(45, 261, '2025-01-01', 18, 810.00, '2026-01-11 14:23:10');

-- --------------------------------------------------------

--
-- Table structure for table `shipments`
--

CREATE TABLE `shipments` (
  `id` int(11) NOT NULL,
  `shipment_number` varchar(50) NOT NULL,
  `po_id` int(11) DEFAULT NULL,
  `transfer_id` int(11) DEFAULT NULL,
  `type` enum('inbound','transfer') NOT NULL,
  `origin_warehouse_id` int(11) DEFAULT NULL,
  `destination_warehouse_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `status` enum('scheduled','in_transit','delivered','cancelled') DEFAULT 'scheduled',
  `scheduled_date` date DEFAULT NULL,
  `delivery_date` date DEFAULT NULL,
  `tracking_number` varchar(100) DEFAULT NULL,
  `transport_cost` decimal(10,2) DEFAULT 0.00,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `distance_km` decimal(10,2) DEFAULT NULL,
  `estimated_time_minutes` int(11) DEFAULT NULL,
  `current_latitude` decimal(10,8) DEFAULT NULL,
  `current_longitude` decimal(11,8) DEFAULT NULL,
  `last_location_update` timestamp NULL DEFAULT NULL,
  `estimated_arrival` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shipments`
--

INSERT INTO `shipments` (`id`, `shipment_number`, `po_id`, `transfer_id`, `type`, `origin_warehouse_id`, `destination_warehouse_id`, `supplier_id`, `vehicle_id`, `driver_id`, `status`, `scheduled_date`, `delivery_date`, `tracking_number`, `transport_cost`, `created_by`, `created_at`, `updated_at`, `distance_km`, `estimated_time_minutes`, `current_latitude`, `current_longitude`, `last_location_update`, `estimated_arrival`) VALUES
(36, 'SHIP-20260110-D0910F', 22, NULL, 'inbound', NULL, 1, 22, 2, 2, 'delivered', '2026-01-11', '2026-01-11', 'TRK-20260110-D09395', 150.00, 7, '2026-01-10 16:19:25', '2026-01-11 11:28:56', NULL, NULL, NULL, NULL, '2026-01-11 10:06:00', NULL),
(37, 'SHIP-20260111-FAD5AE', 24, NULL, 'inbound', NULL, 1, 22, 1, 5, 'delivered', '2026-01-11', '2026-01-11', 'TRK-20260111-FAD5B5', 100.00, 7, '2026-01-11 10:31:43', '2026-01-11 11:16:51', NULL, NULL, NULL, NULL, '2026-01-11 03:32:00', NULL),
(38, 'SHIP-20260111-C2173C', NULL, 12, 'transfer', 1, 2, NULL, 1, 1, 'delivered', '2026-01-11', '2026-01-11', 'TRK-20260111-07106D', 100.00, 4, '2026-01-11 10:34:04', '2026-01-11 11:09:24', NULL, NULL, NULL, NULL, '2026-01-11 04:03:00', NULL),
(39, 'SHIP-20260111-8710B9', NULL, 13, 'transfer', 1, 3, NULL, 2, 5, 'delivered', '2026-01-11', '2026-01-11', '0', 100.00, 1, '2026-01-11 11:33:44', '2026-01-11 11:33:54', NULL, NULL, NULL, NULL, NULL, NULL),
(40, 'SHIP-20260111-A23EAD', NULL, 14, 'transfer', 1, 3, NULL, NULL, 5, 'delivered', '2026-01-11', '2026-01-11', '0', 100.00, 1, '2026-01-11 11:35:54', '2026-01-11 11:36:02', NULL, NULL, NULL, NULL, NULL, NULL),
(41, 'SHIP-20260111-82BBE6', 26, NULL, 'inbound', NULL, 1, 22, 2, 1, 'delivered', '2026-01-18', '2026-01-11', 'TRK-20260111-82BBF1', 100.00, 7, '2026-01-11 12:30:00', '2026-01-11 12:30:35', NULL, NULL, NULL, NULL, '2026-01-11 05:30:00', NULL),
(42, 'SHIP-20260111-B715A5', 28, NULL, 'inbound', NULL, 3, 22, 1, 1, 'delivered', '2026-01-18', '2026-01-11', 'TRK-20260111-B715AA', 100.00, 7, '2026-01-11 12:47:23', '2026-01-11 12:47:56', NULL, NULL, NULL, NULL, NULL, NULL),
(43, 'SHIP-20260111-2C407B', 31, NULL, 'inbound', NULL, 1, 22, 2, 2, 'delivered', '2026-01-11', '2026-01-11', 'TRK-20260111-2C4080', 100.00, 7, '2026-01-11 15:32:50', '2026-01-11 15:33:48', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `shipment_items`
--

CREATE TABLE `shipment_items` (
  `id` int(11) NOT NULL,
  `shipment_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shipment_items`
--

INSERT INTO `shipment_items` (`id`, `shipment_id`, `product_id`, `quantity`) VALUES
(50, 36, 272, 100),
(51, 36, 274, 100),
(52, 36, 262, 100),
(53, 37, 272, 100),
(54, 38, 272, 6),
(55, 39, 278, 54),
(56, 40, 271, 138),
(57, 40, 268, 30),
(58, 41, 271, 62),
(59, 41, 268, 31),
(60, 42, 268, 32),
(61, 43, 260, 50);

-- --------------------------------------------------------

--
-- Table structure for table `shipment_locations`
--

CREATE TABLE `shipment_locations` (
  `id` int(11) NOT NULL,
  `shipment_id` int(11) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `location_name` varchar(255) DEFAULT NULL,
  `speed_kmh` decimal(5,2) DEFAULT NULL,
  `heading_degrees` int(11) DEFAULT NULL,
  `accuracy_meters` decimal(6,2) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shipment_locations`
--

INSERT INTO `shipment_locations` (`id`, `shipment_id`, `latitude`, `longitude`, `location_name`, `speed_kmh`, `heading_degrees`, `accuracy_meters`, `recorded_at`) VALUES
(5, 36, 0.00000000, 0.00000000, 'Robinson, Dasmarinas', NULL, NULL, NULL, '2026-01-09 17:22:00'),
(6, 36, 0.00000000, 0.00000000, 'Manggahan', NULL, NULL, NULL, '2026-01-09 17:24:00'),
(7, 36, 0.00000000, 0.00000000, 'Sm Trece Martirez', NULL, NULL, NULL, '2026-01-11 10:06:00');

-- --------------------------------------------------------

--
-- Table structure for table `stock_movements`
--

CREATE TABLE `stock_movements` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `movement_type` enum('in','out','transfer_in','transfer_out','adjustment') NOT NULL,
  `quantity` int(11) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_movements`
--

INSERT INTO `stock_movements` (`id`, `product_id`, `warehouse_id`, `movement_type`, `quantity`, `reference_type`, `reference_id`, `notes`, `created_by`, `created_at`) VALUES
(530, 337, 1, 'in', 50, NULL, NULL, 'Bulk import from CSV', 1, '2026-01-08 01:17:36'),
(531, 337, 1, 'out', 50, NULL, NULL, 'Bulk delete by admin', 1, '2026-01-08 01:17:53'),
(532, 260, 2, 'in', 50, 'shipment', 30, 'Shipment delivery - Inbound (from Purchase Order)', 1, '2026-01-08 01:27:58'),
(533, 266, 1, 'in', 40, 'shipment', 31, 'Shipment delivery - Inbound (from Purchase Order)', 4, '2026-01-08 03:18:02'),
(534, 270, 1, 'in', 20, 'shipment', 31, 'Shipment delivery - Inbound (from Purchase Order)', 4, '2026-01-08 03:18:02'),
(535, 272, 1, 'in', 100, 'shipment', 37, 'Shipment delivery - Inbound (from Purchase Order)', 4, '2026-01-11 11:16:51'),
(536, 272, 1, 'in', 100, 'shipment', 36, 'Shipment delivery - Inbound (from Purchase Order)', 4, '2026-01-11 11:28:56'),
(537, 274, 1, 'in', 100, 'shipment', 36, 'Shipment delivery - Inbound (from Purchase Order)', 4, '2026-01-11 11:28:56'),
(538, 262, 1, 'in', 100, 'shipment', 36, 'Shipment delivery - Inbound (from Purchase Order)', 4, '2026-01-11 11:28:56'),
(539, 274, 1, 'in', 200, NULL, NULL, '0', 1, '2026-01-11 11:30:13'),
(540, 335, 2, 'in', 100, NULL, NULL, '0', 1, '2026-01-11 11:30:55'),
(541, 274, 2, 'in', 50, NULL, NULL, '0', 1, '2026-01-11 11:31:54'),
(542, 261, 3, 'in', 50, NULL, NULL, '0', 1, '2026-01-11 11:32:08'),
(543, 262, 3, 'in', 50, NULL, NULL, '0', 1, '2026-01-11 11:32:29'),
(544, 271, 1, 'in', 62, 'shipment', 41, 'Shipment delivery - Inbound (from Purchase Order)', 4, '2026-01-11 12:30:35'),
(545, 268, 1, 'in', 31, 'shipment', 41, 'Shipment delivery - Inbound (from Purchase Order)', 4, '2026-01-11 12:30:35'),
(546, 268, 3, 'in', 32, 'shipment', 42, 'Shipment delivery - Inbound (from Purchase Order)', 4, '2026-01-11 12:47:56'),
(547, 260, 1, 'in', 50, 'purchase_order', 31, 'Purchase Order received - Inventory updated (Shipment: 43)', 2, '2026-01-11 15:34:04');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `company_name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `products_supplied` text DEFAULT NULL,
  `performance_rating` decimal(3,2) DEFAULT 0.00,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `user_id`, `company_name`, `contact_person`, `email`, `phone`, `address`, `products_supplied`, `performance_rating`, `status`, `created_at`, `updated_at`) VALUES
(22, 7, 'Incheon Food Export', 'Mikaela', 'ifesupplier@gmail.com', '09262304858', 'General Trias, Cavite', 'Binggrae Banana, Binggrae Chestnut, Binggrae Coffee, Binggrae Melon, Binggrae Strawberry, Binggrae Taro, Binggrae Vanilla, Buldak 2x, Buldak 3x, Buldak Carbonara, Buldak Cream Carbonara, Buldak Original, Buldak Quattro Cheese, Coke, Jin Ramen Mild, Jin Ramen Small Cup, Jin Ramen Spicy, Jin Ramen Spicy Cup, Pororo, Royal, Shin Big Cup, Shin Ramen, Shin Small Cup, Sprite', 5.00, 'active', '2026-01-03 20:25:14', '2026-01-11 15:09:07');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_products`
--

CREATE TABLE `supplier_products` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `supplier_sku` varchar(50) DEFAULT NULL COMMENT 'Supplier-specific SKU for this product',
  `supplier_price` decimal(10,2) DEFAULT NULL COMMENT 'Price from this specific supplier',
  `lead_time_days` int(11) DEFAULT 7 COMMENT 'Lead time in days for this product from this supplier',
  `is_primary` tinyint(1) DEFAULT 0 COMMENT 'Whether this is the primary supplier for this product',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supplier_products`
--

INSERT INTO `supplier_products` (`id`, `supplier_id`, `product_id`, `supplier_sku`, `supplier_price`, `lead_time_days`, `is_primary`, `status`, `created_at`, `updated_at`) VALUES
(318, 22, 256, NULL, NULL, 7, 1, 'active', '2026-01-06 09:15:14', '2026-01-11 12:20:43'),
(319, 22, 258, NULL, NULL, 7, 1, 'active', '2026-01-06 09:15:14', '2026-01-11 12:20:43'),
(320, 22, 260, NULL, NULL, 7, 1, 'active', '2026-01-06 09:15:14', '2026-01-11 12:20:43'),
(321, 22, 262, NULL, NULL, 7, 1, 'active', '2026-01-06 09:15:14', '2026-01-11 12:20:43'),
(322, 22, 264, NULL, NULL, 7, 1, 'active', '2026-01-06 09:15:14', '2026-01-11 12:20:43'),
(323, 22, 266, NULL, NULL, 7, 1, 'active', '2026-01-06 09:15:14', '2026-01-11 12:20:43'),
(324, 22, 268, NULL, NULL, 7, 1, 'active', '2026-01-06 09:15:14', '2026-01-11 12:20:43'),
(325, 22, 270, NULL, NULL, 7, 1, 'active', '2026-01-06 09:15:14', '2026-01-11 12:20:43'),
(326, 22, 272, NULL, NULL, 7, 1, 'active', '2026-01-06 09:15:14', '2026-01-11 12:20:43'),
(327, 22, 274, NULL, NULL, 7, 1, 'active', '2026-01-06 09:15:14', '2026-01-11 12:20:43'),
(328, 22, 276, NULL, NULL, 7, 1, 'active', '2026-01-06 09:15:14', '2026-01-11 12:20:43'),
(329, 22, 278, NULL, NULL, 7, 1, 'active', '2026-01-06 09:15:14', '2026-01-11 12:20:43'),
(330, 22, 280, NULL, NULL, 7, 1, 'active', '2026-01-06 09:15:14', '2026-01-11 12:20:43'),
(342, 22, 328, NULL, NULL, 7, 1, 'active', '2026-01-07 15:00:15', '2026-01-11 12:20:43'),
(352, 22, 257, NULL, NULL, 7, 1, 'active', '2026-01-11 12:06:48', '2026-01-11 12:20:43'),
(353, 22, 261, NULL, NULL, 7, 1, 'active', '2026-01-11 12:06:48', '2026-01-11 12:20:43'),
(354, 22, 263, NULL, NULL, 7, 1, 'active', '2026-01-11 12:06:48', '2026-01-11 12:20:43'),
(355, 22, 265, NULL, NULL, 7, 1, 'active', '2026-01-11 12:06:48', '2026-01-11 12:20:43'),
(356, 22, 267, NULL, NULL, 7, 1, 'active', '2026-01-11 12:06:48', '2026-01-11 12:20:43'),
(357, 22, 269, NULL, NULL, 7, 1, 'active', '2026-01-11 12:06:48', '2026-01-11 12:20:43'),
(358, 22, 271, NULL, NULL, 7, 1, 'active', '2026-01-11 12:06:48', '2026-01-11 12:20:43'),
(359, 22, 273, NULL, NULL, 7, 1, 'active', '2026-01-11 12:06:48', '2026-01-11 12:20:43'),
(360, 22, 275, NULL, NULL, 7, 1, 'active', '2026-01-11 12:06:48', '2026-01-11 12:20:43'),
(361, 22, 277, NULL, NULL, 7, 1, 'active', '2026-01-11 12:06:48', '2026-01-11 12:20:43'),
(362, 22, 279, NULL, NULL, 7, 1, 'active', '2026-01-11 12:06:48', '2026-01-11 12:20:43'),
(367, 22, 337, NULL, NULL, 7, 1, 'active', '2026-01-11 12:20:43', '2026-01-11 12:20:43'),
(368, 22, 336, NULL, NULL, 7, 1, 'active', '2026-01-11 12:20:43', '2026-01-11 12:20:43'),
(369, 22, 335, NULL, NULL, 7, 1, 'active', '2026-01-11 12:20:43', '2026-01-11 12:20:43');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_ratings`
--

CREATE TABLE `supplier_ratings` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `rated_by` int(11) NOT NULL COMMENT 'User ID of procurement_staff who rated',
  `rating` decimal(2,1) NOT NULL DEFAULT 0.0 COMMENT 'Rating 1-5 stars',
  `quality_rating` decimal(2,1) NOT NULL COMMENT 'Rating 1-5 for product quality',
  `timeliness_rating` decimal(2,1) NOT NULL COMMENT 'Rating 1-5 for delivery timeliness',
  `communication_rating` decimal(2,1) NOT NULL COMMENT 'Rating 1-5 for communication',
  `overall_rating` decimal(2,1) NOT NULL COMMENT 'Overall rating 1-5',
  `comments` text DEFAULT NULL COMMENT 'Additional comments about the delivery',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supplier_ratings`
--

INSERT INTO `supplier_ratings` (`id`, `po_id`, `supplier_id`, `rated_by`, `rating`, `quality_rating`, `timeliness_rating`, `communication_rating`, `overall_rating`, `comments`, `created_at`, `updated_at`) VALUES
(1, 22, 22, 2, 0.0, 5.0, 5.0, 5.0, 5.0, '', '2026-01-11 15:09:07', '2026-01-11 15:09:07'),
(2, 31, 22, 2, 5.0, 0.0, 0.0, 0.0, 0.0, '', '2026-01-11 15:55:11', '2026-01-11 15:55:11'),
(3, 28, 22, 2, 2.0, 0.0, 0.0, 0.0, 0.0, '', '2026-01-11 15:55:38', '2026-01-11 15:55:38');

-- --------------------------------------------------------

--
-- Table structure for table `transfer_items`
--

CREATE TABLE `transfer_items` (
  `id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transfer_items`
--

INSERT INTO `transfer_items` (`id`, `transfer_id`, `product_id`, `quantity`) VALUES
(13, 11, 272, 10),
(14, 12, 272, 6),
(15, 13, 278, 54),
(16, 14, 271, 138),
(17, 14, 268, 30);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','procurement_staff','warehouse_officer','logistics_manager','supplier') NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `full_name`, `status`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'admin@supplychain.com', '$2y$10$6uKQtU7kwUPmPA/2yxTHDu3vUUMF4m3BBm.3fAIERQqTxOaoizAGe', 'admin', 'System Administrator', 'active', '2026-01-02 11:18:01', '2026-01-02 11:18:01'),
(2, 'procurement_staff', 'procurementstaff@supplychain.com', '$2y$10$Aqn5/J1dP2THxEIz7dk4C.BCQv27vePavVDbAHX5ux0LMsMJ7ZaLS', 'procurement_staff', 'Procurement Staff', 'active', '2026-01-03 15:59:22', '2026-01-03 15:59:22'),
(3, 'warehouse_officer', 'warehouseofficer@supplychain.com', '$2y$10$z78.iQMLdZSNyywQ8JCA.uZOn9oorrSeyptVGg2QkQTgcne8mV0..', 'warehouse_officer', 'Warehouse Officer', 'active', '2026-01-03 16:10:50', '2026-01-03 16:10:50'),
(4, 'logistics_manager', 'logisticsmanager@supplychain.com', '$2y$10$1RJ7odDCvBAsyN3.aoN/IemE3PGEFdXG..nVRbM8GoauYFfgkfjuq', 'logistics_manager', 'Logistics Manager', 'active', '2026-01-03 16:11:22', '2026-01-04 03:56:50'),
(7, 'supplier1', 'supplier1@supplychain.com', '$2y$10$wFnFAhT4by7j.5./MuTCt.CKQvJZIhqvP/SixwVQLQNWOVB9CSqAO', 'supplier', 'Supplier User', 'active', '2026-01-08 23:57:25', '2026-01-08 23:57:25');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL,
  `vehicle_number` varchar(50) NOT NULL,
  `vehicle_type` enum('truck','van','container','trailer','other') NOT NULL DEFAULT 'truck',
  `make` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `year` year(4) DEFAULT NULL,
  `license_plate` varchar(50) DEFAULT NULL,
  `capacity_kg` decimal(10,2) DEFAULT NULL,
  `capacity_volume` decimal(10,2) DEFAULT NULL,
  `fuel_type` enum('diesel','gasoline','electric','hybrid','other') DEFAULT 'diesel',
  `status` enum('available','in_use','maintenance','inactive') DEFAULT 'available',
  `current_location` varchar(200) DEFAULT NULL,
  `last_maintenance_date` date DEFAULT NULL,
  `next_maintenance_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `vehicle_number`, `vehicle_type`, `make`, `model`, `year`, `license_plate`, `capacity_kg`, `capacity_volume`, `fuel_type`, `status`, `current_location`, `last_maintenance_date`, `next_maintenance_date`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'VH-001', 'truck', 'Isuzu', 'NPR 75', '2022', 'ABC-1234', 7500.00, 25.50, 'diesel', 'available', 'Main Warehouse - Loading Bay 1', '2025-11-15', '2026-02-15', 'Regular maintenance schedule. Good condition.', '2026-01-05 11:27:05', '2026-01-05 11:27:05'),
(2, 'VH-002', 'van', 'Mercedes-Benz', 'Sprinter 3500', '2023', 'XYZ-5678', 3500.00, 12.00, 'diesel', 'available', 'Main Warehouse - Loading Bay 2', '2025-12-01', '2026-03-01', 'New vehicle. Excellent for urban deliveries.', '2026-01-05 11:27:05', '2026-01-05 11:27:05'),
(3, 'VH-003', 'truck', 'Hino', '300 Series', '2021', 'DEF-9012', 10000.00, 35.00, 'diesel', 'in_use', 'In Transit - Route to Warehouse B', '2025-10-20', '2026-01-20', 'Heavy-duty truck for long-distance transport. Currently on delivery route.', '2026-01-05 11:27:05', '2026-01-05 11:27:05');

-- --------------------------------------------------------

--
-- Table structure for table `warehouses`
--

CREATE TABLE `warehouses` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `location` varchar(200) DEFAULT NULL,
  `capacity` decimal(10,2) DEFAULT NULL,
  `utilized_capacity` decimal(10,2) DEFAULT 0.00,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `warehouses`
--

INSERT INTO `warehouses` (`id`, `name`, `location`, `capacity`, `utilized_capacity`, `status`, `created_at`, `updated_at`, `latitude`, `longitude`) VALUES
(1, 'Main Warehouse', '123 Industrial Ave, City', 10000.00, 0.00, 'active', '2026-01-02 11:18:01', '2026-01-04 10:20:35', 14.29909530, 120.95017815),
(2, 'North Distribution Center', '456 Commerce St, North City', 8000.00, 0.00, 'active', '2026-01-02 11:18:01', '2026-01-04 10:20:35', 14.28559412, 120.91580629),
(3, 'South Distribution Center', '789 Trade Blvd, South City', 7500.00, 0.00, 'active', '2026-01-02 11:18:01', '2026-01-04 10:20:35', 14.35348133, 120.91966744);

-- --------------------------------------------------------

--
-- Table structure for table `warehouse_transfers`
--

CREATE TABLE `warehouse_transfers` (
  `id` int(11) NOT NULL,
  `transfer_number` varchar(50) NOT NULL,
  `source_warehouse_id` int(11) NOT NULL,
  `destination_warehouse_id` int(11) NOT NULL,
  `status` enum('pending','approved','in_transit','delivered','received','completed','cancelled') DEFAULT 'pending',
  `requested_by` int(11) NOT NULL,
  `requested_date` date NOT NULL,
  `completed_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `warehouse_transfers`
--

INSERT INTO `warehouse_transfers` (`id`, `transfer_number`, `source_warehouse_id`, `destination_warehouse_id`, `status`, `requested_by`, `requested_date`, `completed_date`, `notes`, `created_at`, `updated_at`) VALUES
(12, 'TRF-20260110-C7F0EC', 1, 2, 'completed', 3, '2026-01-10', '2026-01-11', '', '2026-01-10 18:44:28', '2026-01-11 11:10:19'),
(13, 'TRF-20260111-718FC6', 1, 3, 'completed', 1, '2026-01-11', '2026-01-11', '', '2026-01-11 11:33:11', '2026-01-11 11:34:11'),
(14, 'TRF-20260111-415EF2', 1, 3, 'completed', 1, '2026-01-11', '2026-01-11', '', '2026-01-11 11:35:32', '2026-01-11 11:36:14');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `demand_forecasts`
--
ALTER TABLE `demand_forecasts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `drivers`
--
ALTER TABLE `drivers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `driver_code` (`driver_code`),
  ADD UNIQUE KEY `license_number` (`license_number`),
  ADD KEY `idx_driver_status` (`status`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_product_warehouse` (`product_id`,`warehouse_id`),
  ADD KEY `warehouse_id` (`warehouse_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_role_read` (`role`,`is_read`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `po_notifications`
--
ALTER TABLE `po_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_supplier_read` (`supplier_id`,`is_read`),
  ADD KEY `idx_po_id` (`po_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `po_number` (`po_number`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `admin_approved_by` (`admin_approved_by`),
  ADD KEY `idx_approval_status` (`admin_approved`,`supplier_approved`),
  ADD KEY `idx_delay_status` (`delay_status`),
  ADD KEY `delay_responded_by` (`delay_responded_by`);

--
-- Indexes for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `po_id` (`po_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_po_items_warehouse` (`warehouse_id`);

--
-- Indexes for table `sales_history`
--
ALTER TABLE `sales_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sale_date` (`sale_date`),
  ADD KEY `idx_product_date` (`product_id`,`sale_date`);

--
-- Indexes for table `shipments`
--
ALTER TABLE `shipments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `shipment_number` (`shipment_number`),
  ADD KEY `po_id` (`po_id`),
  ADD KEY `origin_warehouse_id` (`origin_warehouse_id`),
  ADD KEY `destination_warehouse_id` (`destination_warehouse_id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_shipments_status_location` (`status`,`last_location_update`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `driver_id` (`driver_id`),
  ADD KEY `idx_shipment_vehicle` (`vehicle_id`),
  ADD KEY `idx_shipment_driver` (`driver_id`),
  ADD KEY `idx_shipment_transfer` (`transfer_id`);

--
-- Indexes for table `shipment_items`
--
ALTER TABLE `shipment_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shipment_id` (`shipment_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `shipment_locations`
--
ALTER TABLE `shipment_locations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shipment_recorded` (`shipment_id`,`recorded_at`);

--
-- Indexes for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `warehouse_id` (`warehouse_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `supplier_products`
--
ALTER TABLE `supplier_products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_supplier_product` (`supplier_id`,`product_id`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_product_id` (`product_id`);

--
-- Indexes for table `supplier_ratings`
--
ALTER TABLE `supplier_ratings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_po_rating` (`po_id`,`rated_by`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_po_id` (`po_id`),
  ADD KEY `idx_rated_by` (`rated_by`);

--
-- Indexes for table `transfer_items`
--
ALTER TABLE `transfer_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transfer_id` (`transfer_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vehicle_number` (`vehicle_number`),
  ADD UNIQUE KEY `license_plate` (`license_plate`),
  ADD KEY `idx_vehicle_status` (`status`);

--
-- Indexes for table `warehouses`
--
ALTER TABLE `warehouses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `warehouse_transfers`
--
ALTER TABLE `warehouse_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transfer_number` (`transfer_number`),
  ADD KEY `source_warehouse_id` (`source_warehouse_id`),
  ADD KEY `destination_warehouse_id` (`destination_warehouse_id`),
  ADD KEY `requested_by` (`requested_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `demand_forecasts`
--
ALTER TABLE `demand_forecasts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=232;

--
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=349;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=99;

--
-- AUTO_INCREMENT for table `po_notifications`
--
ALTER TABLE `po_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=338;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `sales_history`
--
ALTER TABLE `sales_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `shipments`
--
ALTER TABLE `shipments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `shipment_items`
--
ALTER TABLE `shipment_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `shipment_locations`
--
ALTER TABLE `shipment_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `stock_movements`
--
ALTER TABLE `stock_movements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=548;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `supplier_products`
--
ALTER TABLE `supplier_products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=370;

--
-- AUTO_INCREMENT for table `supplier_ratings`
--
ALTER TABLE `supplier_ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `transfer_items`
--
ALTER TABLE `transfer_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `warehouses`
--
ALTER TABLE `warehouses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `warehouse_transfers`
--
ALTER TABLE `warehouse_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `demand_forecasts`
--
ALTER TABLE `demand_forecasts`
  ADD CONSTRAINT `demand_forecasts_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `demand_forecasts_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_ibfk_2` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `po_notifications`
--
ALTER TABLE `po_notifications`
  ADD CONSTRAINT `po_notifications_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `po_notifications_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `purchase_orders_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `purchase_orders_ibfk_3` FOREIGN KEY (`admin_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_orders_ibfk_4` FOREIGN KEY (`delay_responded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD CONSTRAINT `fk_po_items_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_order_items_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `sales_history`
--
ALTER TABLE `sales_history`
  ADD CONSTRAINT `sales_history_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shipments`
--
ALTER TABLE `shipments`
  ADD CONSTRAINT `shipments_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`),
  ADD CONSTRAINT `shipments_ibfk_2` FOREIGN KEY (`origin_warehouse_id`) REFERENCES `warehouses` (`id`),
  ADD CONSTRAINT `shipments_ibfk_3` FOREIGN KEY (`destination_warehouse_id`) REFERENCES `warehouses` (`id`),
  ADD CONSTRAINT `shipments_ibfk_4` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `shipments_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `shipments_ibfk_6` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `shipments_ibfk_7` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `shipments_ibfk_8` FOREIGN KEY (`transfer_id`) REFERENCES `warehouse_transfers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `shipments_ibfk_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `shipments_ibfk_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `shipment_items`
--
ALTER TABLE `shipment_items`
  ADD CONSTRAINT `shipment_items_ibfk_1` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shipment_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `shipment_locations`
--
ALTER TABLE `shipment_locations`
  ADD CONSTRAINT `shipment_locations_ibfk_1` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD CONSTRAINT `suppliers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `supplier_products`
--
ALTER TABLE `supplier_products`
  ADD CONSTRAINT `supplier_products_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `supplier_products_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `supplier_ratings`
--
ALTER TABLE `supplier_ratings`
  ADD CONSTRAINT `supplier_ratings_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `supplier_ratings_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `supplier_ratings_ibfk_3` FOREIGN KEY (`rated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
