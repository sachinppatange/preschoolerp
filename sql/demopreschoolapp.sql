-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: May 02, 2026 at 03:59 AM
-- Server version: 11.8.6-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u750208840_pioneerdb02`
--

-- --------------------------------------------------------

--
-- Table structure for table `alerts`
--

CREATE TABLE `alerts` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `message` text NOT NULL,
  `level` enum('info','warning','critical') NOT NULL DEFAULT 'info',
  `link` varchar(512) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `class_id` int(10) UNSIGNED DEFAULT NULL,
  `date` date NOT NULL,
  `status` enum('present','absent','leave') NOT NULL DEFAULT 'present',
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `age_group` varchar(50) DEFAULT NULL,
  `fees` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`id`, `school_id`, `name`, `age_group`, `fees`, `created_at`, `updated_at`) VALUES
(14, 1, 'Playgroup', '2-3 Years', 18000.00, '2026-02-22 15:08:07', '2026-02-22 15:08:07'),
(15, 1, 'Nursery', '3-4 Years', 19000.00, '2026-02-22 15:08:41', '2026-02-22 15:08:41'),
(16, 1, 'L.K.G.', '4-5 Years', 20000.00, '2026-02-22 15:09:16', '2026-02-22 15:09:16'),
(17, 1, 'U.K.G.', '5-6 Years', 21000.00, '2026-02-22 15:09:49', '2026-02-22 15:09:49');

-- --------------------------------------------------------

--
-- Table structure for table `class_photos`
--

CREATE TABLE `class_photos` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(1024) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `complaints`
--

CREATE TABLE `complaints` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(191) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `source` varchar(100) DEFAULT 'website',
  `created_at` datetime DEFAULT current_timestamp(),
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enquiries`
--

CREATE TABLE `enquiries` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `phone` varchar(32) NOT NULL,
  `source` varchar(100) NOT NULL DEFAULT '',
  `message` text DEFAULT NULL,
  `assigned_to` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('new','contacted','converted','closed') DEFAULT 'new',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `enquiries`
--

INSERT INTO `enquiries` (`id`, `school_id`, `name`, `phone`, `source`, `message`, `assigned_to`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'Yogita Salunke', '919284568948', 'reception_added', 'Age group: 2-3\n4 March call', 1001, 'new', '2026-03-05 04:51:37', '2026-04-02 06:30:08'),
(2, 1, 'Ovi Tarse', '9960116396', 'Survey', 'Age group: 2-3\nWill visit', 1001, 'new', '2026-03-05 05:47:23', '2026-03-05 05:47:23'),
(3, 1, 'Rudransh Ravikiran udate', '9607031178', 'Survey', 'Age group: 2-3\n', 1001, 'new', '2026-03-06 07:42:53', '2026-03-06 07:43:16'),
(4, 1, 'Shriyash Ganesh Kamble', '9511600156', 'Survey', 'Age group: 3-4\nRecall', 1001, 'new', '2026-03-06 07:48:45', '2026-03-06 07:48:45'),
(5, 1, 'Darshan shailesh kawale', '8484992111', 'Survey', 'Age group: 3-4\nRecall', 1001, 'new', '2026-03-06 08:27:05', '2026-03-06 08:27:05'),
(6, 1, 'Dhaneshwari shailesh kawale', '918484992111', 'Survey', 'Age group: 5-6\nYes', 1001, 'new', '2026-03-06 08:42:23', '2026-03-06 08:42:23'),
(7, 1, 'Shivendra ganesh jadhav', '919689852908', 'Survey', 'Age group: 2-3\nYes', 1001, 'new', '2026-03-06 08:44:37', '2026-03-06 08:44:37'),
(8, 1, 'Aayush Akshay Mahamuni', '919766442715', 'Survey', 'Age group: 2-3\nYes', 1001, 'new', '2026-03-06 08:45:58', '2026-03-06 08:45:58'),
(9, 1, 'Thakur', '919727958353', 'Online', '', 1001, 'new', '2026-03-06 08:47:18', '2026-03-06 08:47:18'),
(10, 1, 'Arvika Pande', '919325878826', 'Survey', 'Age group: 2-3\nYes', 1001, 'new', '2026-03-06 08:49:14', '2026-03-06 08:49:14'),
(11, 1, 'Jaya Hyundai', '919356932108', 'reception_added', '', NULL, 'new', '2026-03-06 08:50:10', '2026-03-06 08:50:10'),
(12, 1, 'Shital Jadhav', '919226719109', 'Instagram', 'Age group: 3-4\nYes', 1001, 'new', '2026-03-06 08:51:24', '2026-03-06 08:51:24'),
(13, 1, 'Rupali Alapure', '919890958146', 'Instagram', '', NULL, 'new', '2026-03-06 08:52:39', '2026-03-06 08:52:39'),
(14, 1, 'Bhanate Ashlesha', '919356286568', 'Instagram', 'Not received', 1001, 'new', '2026-03-06 09:02:05', '2026-03-06 09:02:05'),
(15, 1, 'Sakshi Dalvi', '917350739577', 'Instagram', 'Age group: 1-2\nNot received', 1001, 'new', '2026-03-06 09:03:35', '2026-03-06 09:03:35'),
(16, 1, 'Archana kokate', '919022165787', 'Instagram', 'Age group: 5-6\nFor first class', 1001, 'new', '2026-03-06 09:04:39', '2026-03-06 09:04:39'),
(18, 1, 'Riyansh Kamble', '7709864070', 'Survey', 'Age group: 3-4\n4 April 2026 Saturday\r\nI will come', 1000, 'new', '2026-04-02 06:27:57', '2026-04-02 06:27:57'),
(19, 1, 'Avni kishor kamble', '7219201871', 'Survey', 'Age group: 2-3\nI will come', 1000, 'new', '2026-04-02 06:33:07', '2026-04-02 06:33:07'),
(20, 1, 'Ovi Londhe', '7796225992', 'Survey', 'Age group: 2-3\nI will come\r\nApril month', 1000, 'new', '2026-04-02 06:34:52', '2026-04-02 06:34:52'),
(21, 1, 'Sonali Chavan', '7709566383', 'Survey', 'Age group: 2-3\nNext year (2027)', 1000, 'new', '2026-04-02 06:36:26', '2026-04-02 06:36:26'),
(22, 1, 'Sonali Chavan', '7709566383', 'Survey', 'Age group: 2-3\nNext year (2027)', 1000, 'new', '2026-04-02 06:37:47', '2026-04-02 06:37:47'),
(23, 1, 'Atharva Gajanan Kalyane', '9890131566', 'Survey', 'Age group: 3-4\nVisit to school \r\n(1|04 |2026 )', 1000, 'new', '2026-04-02 06:41:20', '2026-04-02 06:41:20'),
(24, 1, 'Manish Sing', '8335884100', 'Online', 'Age group: 3-4\nConfirm', 1000, 'new', '2026-04-02 06:43:56', '2026-04-02 06:43:56'),
(25, 1, 'Mayra Sagar Shete', '8087871351', 'Walk in', 'Age group: 3-4\nAddmission confirm', 1000, 'new', '2026-04-02 06:46:09', '2026-04-02 06:46:09'),
(26, 1, 'Jigisha Dhule', '9860028013', 'Walk in', 'Age group: 4-5\nAddmission confirm', 1000, 'new', '2026-04-02 06:49:35', '2026-04-02 06:49:35'),
(27, 1, 'Vishwajit Nilkanth Adsule', '9096993158', 'Student reference', 'Age group: 4-5\nAddmission confirm', 1000, 'new', '2026-04-02 06:52:45', '2026-04-02 06:52:45'),
(28, 1, 'Mr. Kawale P.S', '8830052758', 'website_enquiry', 'Age group: 2-3\n', NULL, 'new', '2026-04-07 04:57:19', '2026-04-07 04:57:19'),
(29, 1, 'Atharv Patange', '9096463943', 'website_enquiry', 'Age group: 1-2\nhi', NULL, 'new', '2026-04-18 14:03:06', '2026-04-18 14:03:06');

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `category` varchar(100) DEFAULT NULL,
  `expense_date` date NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feedbacks`
--

CREATE TABLE `feedbacks` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(191) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `type` varchar(50) DEFAULT 'feedback',
  `source` varchar(100) DEFAULT 'website',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fees_records`
--

CREATE TABLE `fees_records` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` varchar(32) DEFAULT 'pending',
  `receipt_no` varchar(191) DEFAULT NULL,
  `collected_by` int(11) DEFAULT NULL,
  `collected_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `fees_records`
--

INSERT INTO `fees_records` (`id`, `school_id`, `student_id`, `class_id`, `amount`, `due_date`, `paid_amount`, `status`, `receipt_no`, `collected_by`, `collected_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1016, NULL, 1000.00, NULL, 1000.00, 'paid', 'RC20260322181709418||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 18:17:09', '2026-03-22 18:17:09', '2026-03-22 18:17:09'),
(2, 1, 1016, NULL, 10000.00, NULL, 10000.00, 'paid', 'RC20260322181709188||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 18:17:09', '2026-03-22 18:17:09', '2026-03-22 18:17:09'),
(3, 1, 1016, NULL, 4000.00, NULL, 4000.00, 'paid', 'RC20260322181709800||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 18:17:09', '2026-03-22 18:17:09', '2026-03-22 18:17:09'),
(4, 1, 1017, NULL, 3000.00, NULL, 3000.00, 'paid', 'RC20260322181709301||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 18:17:09', '2026-03-22 18:17:09', '2026-03-22 18:17:09'),
(5, 1, 1017, NULL, 5000.00, NULL, 5000.00, 'paid', 'RC20260322181709885||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 18:17:09', '2026-03-22 18:17:09', '2026-03-22 18:17:09'),
(6, 1, 1017, NULL, 10000.00, NULL, 10000.00, 'paid', 'RC20260322181709968||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 18:17:09', '2026-03-22 18:17:09', '2026-03-22 18:17:09'),
(7, 1, 1018, NULL, 10000.00, NULL, 10000.00, 'paid', 'RC20260322181709591||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 18:17:09', '2026-03-22 18:17:09', '2026-03-22 18:17:09'),
(8, 1, 1018, NULL, 2500.00, NULL, 2500.00, 'paid', 'RC20260322181709427||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 18:17:09', '2026-03-22 18:17:09', '2026-03-22 18:17:09'),
(9, 1, 1019, 14, 6000.00, NULL, 6000.00, 'paid', 'RC20260323034955662||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(10, 1, 1019, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955680||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(11, 1, 1020, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955490||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(12, 1, 1020, 14, 8000.00, NULL, 8000.00, 'paid', 'RC20260323034955345||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(13, 1, 1020, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955534||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(14, 1, 1021, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955237||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(15, 1, 1021, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955535||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(16, 1, 1021, 14, 8000.00, NULL, 8000.00, 'paid', 'RC20260323034955995||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(17, 1, 1022, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955692||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(18, 1, 1022, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955240||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(19, 1, 1022, 14, 8000.00, NULL, 8000.00, 'paid', 'RC20260323034955289||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(20, 1, 1023, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955913||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(21, 1, 1024, 14, 1000.00, NULL, 1000.00, 'paid', 'RC20260323034955794||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(22, 1, 1025, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955347||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(23, 1, 1025, 14, 1000.00, NULL, 1000.00, 'paid', 'RC20260323034955259||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(24, 1, 1025, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955368||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(25, 1, 1026, 14, 10000.00, NULL, 10000.00, 'paid', 'RC20260323034955855||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(26, 1, 1026, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955123||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(27, 1, 1026, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955669||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(28, 1, 1027, 14, 10000.00, NULL, 10000.00, 'paid', 'RC20260323034955162||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(29, 1, 1027, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955108||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(30, 1, 1027, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955545||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(31, 1, 1043, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955104||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(32, 1, 1043, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955649||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(33, 1, 1043, 14, 10000.00, NULL, 10000.00, 'paid', 'RC20260323034955675||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(34, 1, 1028, 14, 7000.00, NULL, 7000.00, 'paid', 'RC20260323034955347||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(35, 1, 1028, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955103||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(36, 1, 1028, 14, 7000.00, NULL, 7000.00, 'paid', 'RC20260323034955862||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(37, 1, 1029, 14, 7000.00, NULL, 7000.00, 'paid', 'RC20260323034955981||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(38, 1, 1029, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955270||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(39, 1, 1029, 14, 7000.00, NULL, 7000.00, 'paid', 'RC20260323034955924||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(40, 1, 1030, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955694||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(41, 1, 1030, 14, 1000.00, NULL, 1000.00, 'paid', 'RC20260323034955575||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(42, 1, 1030, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955156||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(43, 1, 1030, 14, 8000.00, NULL, 8000.00, 'paid', 'RC20260323034955537||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(44, 1, 1031, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955521||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(45, 1, 1036, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955586||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(46, 1, 1036, 14, 1500.00, NULL, 1500.00, 'paid', 'RC20260323034955150||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(47, 1, 1036, 14, 6000.00, NULL, 6000.00, 'paid', 'RC20260323034955116||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(48, 1, 1036, 14, 4500.00, NULL, 4500.00, 'paid', 'RC20260323034955536||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(49, 1, 1036, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955506||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(50, 1, 1037, 14, 6500.00, NULL, 6500.00, 'paid', 'RC20260323034955808||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(51, 1, 1038, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955347||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(52, 1, 1038, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955541||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(53, 1, 1038, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955826||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(54, 1, 1039, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955131||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(55, 1, 1039, 14, 10000.00, NULL, 10000.00, 'paid', 'RC20260323034955223||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(56, 1, 1040, 14, 4500.00, NULL, 4500.00, 'paid', 'RC20260323034955136||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(57, 1, 1040, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955616||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(58, 1, 1040, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955409||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(59, 1, 1040, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955900||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(60, 1, 1041, 14, 9000.00, NULL, 9000.00, 'paid', 'RC20260323034955906||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(61, 1, 1042, 14, 7000.00, NULL, 7000.00, 'paid', 'RC20260323034955614||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(62, 1, 1044, 14, 6000.00, NULL, 6000.00, 'paid', 'RC20260323034955648||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(63, 1, 1044, 14, 4000.00, NULL, 4000.00, 'paid', 'RC20260323034955972||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(64, 1, 1044, 14, 1000.00, NULL, 1000.00, 'paid', 'RC20260323034955736||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(65, 1, 1045, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955959||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(66, 1, 1046, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955177||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(67, 1, 1046, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955974||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(68, 1, 1046, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955137||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(69, 1, 1047, 14, 13000.00, NULL, 13000.00, 'paid', 'RC20260323034955960||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(70, 1, 1047, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955787||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(71, 1, 1048, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955608||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(72, 1, 1049, 14, 6050.00, NULL, 6050.00, 'paid', 'RC20260323034955424||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(73, 1, 1050, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955302||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(74, 1, 1050, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955884||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(75, 1, 1050, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955400||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(76, 1, 1050, 14, 4000.00, NULL, 4000.00, 'paid', 'RC20260323034955632||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(77, 1, 1050, 14, 4000.00, NULL, 4000.00, 'paid', 'RC20260323034955537||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(78, 1, 1051, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955954||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(79, 1, 1052, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955216||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(80, 1, 1052, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955371||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(81, 1, 1052, 14, 4000.00, NULL, 4000.00, 'paid', 'RC20260323034955854||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(82, 1, 1052, 14, 10000.00, NULL, 10000.00, 'paid', 'RC20260323034955835||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(83, 1, 1053, 14, 5000.00, NULL, 5000.00, 'paid', 'RC20260323034955880||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(84, 1, 1054, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955278||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(85, 1, 1055, 14, 5500.00, NULL, 5500.00, 'paid', 'RC20260323034955359||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(86, 1, 1055, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955711||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(87, 1, 1056, 14, 8000.00, NULL, 8000.00, 'paid', 'RC20260323034955280||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(88, 1, 1057, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955728||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(89, 1, 1057, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955489||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(90, 1, 1058, 14, 1000.00, NULL, 1000.00, 'paid', 'RC20260323034955615||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(91, 1, 1058, 14, 1000.00, NULL, 1000.00, 'paid', 'RC20260323034955272||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(92, 1, 1059, 14, 500.00, NULL, 500.00, 'paid', 'RC20260323034955541||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(93, 1, 1059, 14, 1500.00, NULL, 1500.00, 'paid', 'RC20260323034955675||METHOD:Cash||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(94, 1, 1060, 14, 3000.00, NULL, 3000.00, 'paid', 'RC20260323034955803||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(95, 1, 1061, 14, 2000.00, NULL, 2000.00, 'paid', 'RC20260323034955417||METHOD:Online||NOTE:Partial payment', 1003, '2025-01-01 03:49:55', '2026-03-23 03:49:55', '2026-03-23 03:49:55'),
(96, 1, 1062, 2, 6000.00, NULL, 6000.00, 'paid', 'RC20260324010914960||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(97, 1, 1062, 2, 11000.00, NULL, 11000.00, 'paid', 'RC20260324010914135||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(98, 1, 1063, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914431||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(99, 1, 1064, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914455||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(100, 1, 1064, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914506||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(101, 1, 1086, 2, 9000.00, NULL, 9000.00, 'paid', 'RC20260324010914106||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(102, 1, 1086, 2, 10000.00, NULL, 10000.00, 'paid', 'RC20260324010914273||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(103, 1, 1087, 2, 10000.00, NULL, 10000.00, 'paid', 'RC20260324010914857||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(104, 1, 1087, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914985||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(105, 1, 1087, 2, 1500.00, NULL, 1500.00, 'paid', 'RC20260324010914664||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(106, 1, 1065, 2, 6000.00, NULL, 6000.00, 'paid', 'RC20260324010914178||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(107, 1, 1065, 2, 6000.00, NULL, 6000.00, 'paid', 'RC20260324010914586||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(108, 1, 1088, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914433||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(109, 1, 1088, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914392||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(110, 1, 1088, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914957||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(111, 1, 1089, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914542||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(112, 1, 1089, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914317||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(113, 1, 1089, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914534||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(114, 1, 1066, 2, 4000.00, NULL, 4000.00, 'paid', 'RC20260324010914208||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(115, 1, 1066, 2, 4000.00, NULL, 4000.00, 'paid', 'RC20260324010914726||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(116, 1, 1066, 2, 4000.00, NULL, 4000.00, 'paid', 'RC20260324010914784||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(117, 1, 1067, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914671||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(118, 1, 1067, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914581||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(119, 1, 1067, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914980||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(120, 1, 1068, 2, 2000.00, NULL, 2000.00, 'paid', 'RC20260324010914457||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(121, 1, 1068, 2, 6000.00, NULL, 6000.00, 'paid', 'RC20260324010914174||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(122, 1, 1068, 2, 8000.00, NULL, 8000.00, 'paid', 'RC20260324010914970||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(123, 1, 1069, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914461||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(124, 1, 1069, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914731||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(125, 1, 1069, 2, 8000.00, NULL, 8000.00, 'paid', 'RC20260324010914567||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(126, 1, 1070, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914105||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(127, 1, 1070, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914498||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(128, 1, 1071, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914708||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(129, 1, 1071, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914203||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(130, 1, 1090, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914521||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(131, 1, 1072, 2, 6000.00, NULL, 6000.00, 'paid', 'RC20260324010914725||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(132, 1, 1072, 2, 6000.00, NULL, 6000.00, 'paid', 'RC20260324010914234||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(133, 1, 1072, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914293||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(134, 1, 1073, 2, 2000.00, NULL, 2000.00, 'paid', 'RC20260324010914420||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(135, 1, 1073, 2, 3000.00, NULL, 3000.00, 'paid', 'RC20260324010914791||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(136, 1, 1073, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914480||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(137, 1, 1074, 2, 4000.00, NULL, 4000.00, 'paid', 'RC20260324010914931||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(138, 1, 1074, 2, 4000.00, NULL, 4000.00, 'paid', 'RC20260324010914471||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(139, 1, 1074, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914902||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(140, 1, 1075, 2, 6000.00, NULL, 6000.00, 'paid', 'RC20260324010914350||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(141, 1, 1075, 2, 6000.00, NULL, 6000.00, 'paid', 'RC20260324010914530||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(142, 1, 1076, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914350||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(143, 1, 1076, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914979||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(144, 1, 1076, 2, 6500.00, NULL, 6500.00, 'paid', 'RC20260324010914748||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(145, 1, 1077, 2, 2000.00, NULL, 2000.00, 'paid', 'RC20260324010914759||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(146, 1, 1077, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914222||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(147, 1, 1077, 2, 3000.00, NULL, 3000.00, 'paid', 'RC20260324010914960||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(148, 1, 1077, 2, 3000.00, NULL, 3000.00, 'paid', 'RC20260324010914577||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(149, 1, 1077, 2, 1000.00, NULL, 1000.00, 'paid', 'RC20260324010914883||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(150, 1, 1091, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914355||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(151, 1, 1091, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914455||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(152, 1, 1091, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914100||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(153, 1, 1096, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914513||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(154, 1, 1096, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914116||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(155, 1, 1092, 2, 10000.00, NULL, 10000.00, 'paid', 'RC20260324010914853||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(156, 1, 1092, 2, 8500.00, NULL, 8500.00, 'paid', 'RC20260324010914342||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(157, 1, 1078, 2, 7000.00, NULL, 7000.00, 'paid', 'RC20260324010914818||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(158, 1, 1079, 2, 3000.00, NULL, 3000.00, 'paid', 'RC20260324010914120||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(159, 1, 1079, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914794||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(160, 1, 1079, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914467||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(161, 1, 1080, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914463||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(162, 1, 1080, 2, 7000.00, NULL, 7000.00, 'paid', 'RC20260324010914658||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(163, 1, 1080, 2, 3000.00, NULL, 3000.00, 'paid', 'RC20260324010914323||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(164, 1, 1081, 2, 10000.00, NULL, 10000.00, 'paid', 'RC20260324010914637||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(165, 1, 1081, 2, 4000.00, NULL, 4000.00, 'paid', 'RC20260324010914519||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(166, 1, 1082, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914358||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(167, 1, 1082, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914240||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(168, 1, 1082, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914660||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(169, 1, 1083, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914810||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(170, 1, 1083, 2, 8000.00, NULL, 8000.00, 'paid', 'RC20260324010914700||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(171, 1, 1093, 2, 5000.00, NULL, 5000.00, 'paid', 'RC20260324010914997||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(172, 1, 1093, 2, 10000.00, NULL, 10000.00, 'paid', 'RC20260324010914228||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(173, 1, 1084, 2, 6000.00, NULL, 6000.00, 'paid', 'RC20260324010914635||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(174, 1, 1094, 2, 3000.00, NULL, 3000.00, 'paid', 'RC20260324010914939||METHOD:Cash||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(175, 1, 1095, 2, 8000.00, NULL, 8000.00, 'paid', 'RC20260324010914154||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14'),
(176, 1, 1085, 2, 4000.00, NULL, 4000.00, 'paid', 'RC20260324010914814||METHOD:Online||NOTE:Partial payment', 1003, '2026-01-01 01:09:14', '2026-03-24 01:09:14', '2026-03-24 01:09:14');

-- --------------------------------------------------------

--
-- Table structure for table `homeworks`
--

CREATE TABLE `homeworks` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `class_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `assigned_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `news_events`
--

CREATE TABLE `news_events` (
  `id` int(10) UNSIGNED NOT NULL,
  `type` enum('news','event') NOT NULL DEFAULT 'news',
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `excerpt` text DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `image_path` varchar(512) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notices`
--

CREATE TABLE `notices` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `audience` varchar(100) DEFAULT 'all',
  `published_by` int(10) UNSIGNED DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `type` enum('task','alert') NOT NULL DEFAULT 'task',
  `title` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `assigned_to` int(10) UNSIGNED DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `status` enum('pending','in_progress','done') DEFAULT 'pending',
  `level` enum('info','warning','critical') DEFAULT 'info',
  `link` varchar(512) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otp_verifications`
--

CREATE TABLE `otp_verifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `phone` varchar(32) NOT NULL,
  `role` varchar(50) DEFAULT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `otp_expires_at` datetime NOT NULL,
  `attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parents_children`
--

CREATE TABLE `parents_children` (
  `id` int(10) UNSIGNED NOT NULL,
  `parent_user_id` int(10) UNSIGNED NOT NULL,
  `child_student_id` int(10) UNSIGNED NOT NULL,
  `relation` varchar(50) DEFAULT 'parent',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `parents_children`
--

INSERT INTO `parents_children` (`id`, `parent_user_id`, `child_student_id`, `relation`, `created_at`) VALUES
(2, 1074, 1016, 'guardian', '2026-03-14 16:19:26'),
(3, 1075, 1017, 'guardian', '2026-03-16 03:05:17'),
(4, 1080, 1018, 'guardian', '2026-03-16 03:08:16'),
(5, 1076, 1019, 'guardian', '2026-03-16 03:09:47'),
(6, 1081, 1020, 'guardian', '2026-03-16 03:12:43'),
(7, 1082, 1021, 'guardian', '2026-03-16 03:27:19'),
(8, 1083, 1022, 'guardian', '2026-03-16 03:29:40'),
(9, 1084, 1023, 'guardian', '2026-03-16 03:42:30'),
(10, 1085, 1024, 'guardian', '2026-03-16 03:44:02'),
(11, 1086, 1025, 'guardian', '2026-03-16 03:46:38'),
(12, 1087, 1026, 'guardian', '2026-03-16 03:50:49'),
(13, 1088, 1027, 'guardian', '2026-03-22 05:04:01'),
(14, 1090, 1028, 'guardian', '2026-03-22 06:22:46'),
(15, 1090, 1029, 'guardian', '2026-03-22 06:22:46'),
(16, 1091, 1030, 'guardian', '2026-03-22 06:22:46'),
(17, 1092, 1031, 'guardian', '2026-03-22 06:22:46'),
(22, 1093, 1036, 'guardian', '2026-03-22 06:45:13'),
(23, 1094, 1037, 'guardian', '2026-03-22 06:45:13'),
(24, 1095, 1038, 'guardian', '2026-03-22 06:45:13'),
(25, 1095, 1039, 'guardian', '2026-03-22 06:45:13'),
(26, 1096, 1040, 'guardian', '2026-03-22 06:45:13'),
(27, 1097, 1041, 'guardian', '2026-03-22 06:45:13'),
(28, 1098, 1042, 'guardian', '2026-03-22 06:45:13'),
(29, 1089, 1043, 'guardian', '2026-03-22 07:02:11'),
(30, 1099, 1044, 'guardian', '2026-03-22 07:15:48'),
(31, 1100, 1045, 'guardian', '2026-03-22 07:15:48'),
(32, 1101, 1046, 'guardian', '2026-03-22 07:15:48'),
(33, 1102, 1047, 'guardian', '2026-03-22 07:15:48'),
(34, 1103, 1048, 'guardian', '2026-03-22 07:15:48'),
(35, 1104, 1049, 'guardian', '2026-03-22 07:15:48'),
(36, 1105, 1050, 'guardian', '2026-03-22 07:15:48'),
(37, 1106, 1051, 'guardian', '2026-03-22 07:15:48'),
(38, 1107, 1052, 'guardian', '2026-03-22 07:15:48'),
(39, 1108, 1053, 'guardian', '2026-03-22 07:15:48'),
(40, 1109, 1054, 'guardian', '2026-03-22 07:15:48'),
(41, 1110, 1055, 'guardian', '2026-03-22 07:29:47'),
(42, 1111, 1056, 'guardian', '2026-03-22 07:29:47'),
(43, 1112, 1057, 'guardian', '2026-03-22 07:29:47'),
(44, 1113, 1058, 'guardian', '2026-03-22 07:29:47'),
(45, 1089, 1059, 'guardian', '2026-03-22 07:29:47'),
(46, 1115, 1060, 'guardian', '2026-03-22 07:29:47'),
(47, 1116, 1061, 'guardian', '2026-03-22 07:29:47'),
(48, 1117, 1062, 'guardian', '2026-03-22 17:56:05'),
(49, 1118, 1063, 'guardian', '2026-03-22 17:56:05'),
(50, 1119, 1064, 'guardian', '2026-03-22 17:56:05'),
(51, 1120, 1065, 'guardian', '2026-03-22 17:56:05'),
(52, 1121, 1066, 'guardian', '2026-03-22 17:56:05'),
(53, 1122, 1067, 'guardian', '2026-03-22 17:56:05'),
(54, 1123, 1068, 'guardian', '2026-03-22 17:56:05'),
(55, 1124, 1069, 'guardian', '2026-03-22 17:56:05'),
(56, 1125, 1070, 'guardian', '2026-03-22 17:56:05'),
(57, 1126, 1071, 'guardian', '2026-03-22 17:56:05'),
(58, 1127, 1072, 'guardian', '2026-03-22 17:56:05'),
(59, 1128, 1073, 'guardian', '2026-03-22 17:56:05'),
(60, 1129, 1074, 'guardian', '2026-03-22 17:56:05'),
(61, 1130, 1075, 'guardian', '2026-03-22 17:56:05'),
(62, 1131, 1076, 'guardian', '2026-03-22 17:56:05'),
(63, 1132, 1077, 'guardian', '2026-03-22 17:56:05'),
(64, 1133, 1078, 'guardian', '2026-03-22 17:56:05'),
(65, 1134, 1079, 'guardian', '2026-03-22 17:56:05'),
(66, 1135, 1080, 'guardian', '2026-03-22 17:56:05'),
(67, 1136, 1081, 'guardian', '2026-03-22 17:56:05'),
(68, 1137, 1082, 'guardian', '2026-03-22 17:56:05'),
(69, 1138, 1083, 'guardian', '2026-03-22 17:56:05'),
(70, 1139, 1084, 'guardian', '2026-03-22 17:56:05'),
(71, 1140, 1085, 'guardian', '2026-03-22 17:56:05'),
(72, 1111, 1086, 'guardian', '2026-03-22 17:56:05'),
(73, 1107, 1087, 'guardian', '2026-03-22 17:56:05'),
(74, 1095, 1088, 'guardian', '2026-03-22 17:56:05'),
(75, 1095, 1089, 'guardian', '2026-03-22 17:56:05'),
(76, 1100, 1090, 'guardian', '2026-03-22 17:56:05'),
(77, 1075, 1091, 'guardian', '2026-03-22 17:56:05'),
(78, 1107, 1092, 'guardian', '2026-03-22 17:56:05'),
(79, 1083, 1093, 'guardian', '2026-03-22 17:56:05'),
(80, 1106, 1094, 'guardian', '2026-03-22 17:56:05'),
(81, 1104, 1095, 'guardian', '2026-03-22 17:56:05'),
(82, 1141, 1096, 'guardian', '2026-03-22 18:00:19');

-- --------------------------------------------------------

--
-- Table structure for table `schools`
--

CREATE TABLE `schools` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `short_about` text DEFAULT NULL,
  `address` text DEFAULT NULL,
  `map_embed` text DEFAULT NULL,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `tagline` varchar(255) DEFAULT NULL,
  `hero_image` varchar(512) DEFAULT NULL,
  `long_about` text DEFAULT NULL,
  `classes_offered` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`classes_offered`)),
  `facilities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`facilities`)),
  `gallery` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gallery`)),
  `contact_phone` varchar(50) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `opening_hours` varchar(255) DEFAULT NULL,
  `social_links` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`social_links`)),
  `seo_title` varchar(255) DEFAULT NULL,
  `seo_description` text DEFAULT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `hero_title` varchar(255) DEFAULT NULL,
  `hero_subtitle` text DEFAULT NULL,
  `hero_cta_text` varchar(128) DEFAULT NULL,
  `hero_cta_url` varchar(512) DEFAULT NULL,
  `why_choose_us` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`why_choose_us`)),
  `admission_process` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`admission_process`)),
  `testimonials` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`testimonials`)),
  `faqs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`faqs`)),
  `contact_whatsapp` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `schools`
--

INSERT INTO `schools` (`id`, `name`, `logo_path`, `short_about`, `address`, `map_embed`, `settings`, `created_at`, `updated_at`, `tagline`, `hero_image`, `long_about`, `classes_offered`, `facilities`, `gallery`, `contact_phone`, `contact_email`, `opening_hours`, `social_links`, `seo_title`, `seo_description`, `slug`, `hero_title`, `hero_subtitle`, `hero_cta_text`, `hero_cta_url`, `why_choose_us`, `admission_process`, `testimonials`, `faqs`, `contact_whatsapp`) VALUES
(1, 'Pioneer Play School', '/assets/uploads/1770732023_b41acefe_Logo.png', 'Safe, joyful and smart learning for your child.', '📍 <b>Address:</b> “Garje Automotive”, First Floor, Gate No. 2, \r\nBehind PVR, Plot X-3, MIDC, Barshi Road, Latur – 413512', '<iframe src=\"https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3785.6463620127456!2d76.5384606!3d18.4089392!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3bcf816b6045c9eb%3A0x605fabad554d2239!2sPioneer%20Play%20School%2C%20Latur%20(A%20Unit%20of%20Garje%20Foundation)!5e0!3m2!1sen!2sin!4v1770722598423!5m2!1sen!2sin\" width=\"600\" height=\"450\" style=\"border:0;\" allowfullscreen=\"\" loading=\"lazy\" referrerpolicy=\"no-referrer-when-downgrade\"></iframe>', '{\"timezone\":\"Asia\\/Kolkata\",\"currency\":\"INR\",\"about_image\":\"\\/assets\\/uploads\\/1770732675_460422e8e0_image1-79.jpg\",\"popup\":{\"enabled\":false,\"image\":\"\\/pioneerplayschool01\\/assets\\/uploads\\/1771647245_2f58a0970bef_Page_2.jpg\",\"title\":\"Test\",\"text\":\"Hi\",\"show_once\":true}}', '2026-02-04 06:35:20', '2026-02-21 04:14:05', 'A Unit of Garje Foundation', '/assets/uploads/1770732119_82639af9_Hero.jpeg', '🌱 <b>About School</b>\r\n\r\nPioneer Play School is thoughtfully designed to provide young children with a <b>secure, joyful, and stimulating learning environment.</b> We understand that early childhood is a crucial stage in a child’s development, and our approach focuses on nurturing curiosity, creativity, and confidence.\r\n\r\nOur preschool follows a <b>CBSE pattern–based curriculum,</b> combined with play-way and activity-based learning methods that encourage children to learn naturally. Every classroom and activity is aligned with Government Approved Standards, ensuring quality education and safety.\r\n\r\nWith a team of trained, caring, and passionate educators, we guide children to develop language skills, motor skills, emotional balance, social interaction, and early academic readiness.', '[]', '[\"✔ Spacious & Safe Play Area\",\"✔ Bright & Colourful Classrooms\",\"✔ Activity Rooms for Art & Craft\",\"✔ Music, Dance & Rhymes Sessions\",\"✔ Storytelling & Creative Play\",\"✔ Outdoor & Indoor Games\",\"✔ CCTV Surveillance & Safety Systems\"]', '[\"\\/pioneerplayschool\\/assets\\/uploads\\/1770485929_c0f6183e_WhatsApp_Image_2026-02-07_at_21.07.37.jpeg\",\"\\/pioneerplayschool\\/assets\\/uploads\\/1770485939_0637427f_WhatsApp_Image_2026-02-07_at_21.07.41.jpeg\",\"\\/pioneerplayschool\\/assets\\/uploads\\/1770485975_c2b242fa_WhatsApp_Image_2026-02-07_at_21.07.39.jpeg\",\"\\/pioneerplayschool\\/assets\\/uploads\\/1770485982_e6c1b5af_WhatsApp_Image_2026-02-07_at_21.07.42.jpeg\",\"\\/pioneerplayschool\\/assets\\/uploads\\/1770485991_3035fd3f_WhatsApp_Image_2026-02-07_at_21.07.40.jpeg\",\"\\/pioneerplayschool\\/assets\\/uploads\\/1770486013_7a697855_WhatsApp_Image_2026-02-07_at_21.07.40__1_.jpeg\",\"\\/pioneerplayschool\\/assets\\/uploads\\/1770486022_0194868c_WhatsApp_Image_2026-02-07_at_21.07.43__1_.jpeg\",\"\\/pioneerplayschool\\/assets\\/uploads\\/1770486028_3eabf066_WhatsApp_Image_2026-02-07_at_21.07.39__1_.jpeg\",\"\\/pioneerplayschool\\/assets\\/uploads\\/1770486032_b775d983_WhatsApp_Image_2026-02-07_at_21.07.38__1_.jpeg\",\"\\/pioneerplayschool\\/assets\\/uploads\\/1770486042_09631bad_WhatsApp_Image_2026-02-07_at_21.07.37__1_.jpeg\",\"\\/pioneerplayschool\\/assets\\/uploads\\/1770486074_1a5d22e0_WhatsApp_Image_2026-02-07_at_21.07.42__1_.jpeg\"]', '+917770007156', 'pioneerplayschoollatur@gmail.com', 'Mon-Fri 9:00 AM - 5:00 PM', '{\"facebook\":\"https:\\/\\/facebook.com\\/pioneerplayschool\",\"instagram\":\"https:\\/\\/instagram.com\\/pioneer_play_school\",\"youtube\":\"https:\\/\\/youtube.com\\/@pioneer_play_school\"}', 'Pioneer Play School — Preschool in Latur', 'Pioneer Play School in Latur offers joyful early learning, art & craft, music, play area and nutritious meals.', 'pioneer-play-school', '🌈 A Second Home Full of Joy, Care & Learning', 'At Pioneer Play School, we believe that every child deserves a safe, loving, and inspiring start to life. Our preschool blends fun, care, and structured learning to build strong foundations for a bright future.', 'Admissions Open 2026–27 - Book a School Visit | Enroll Now', '/#enquiry', '[{\"title\":\"Child-Centered Learning Approach\",\"description\":\"We follow a CBSE pattern–based, age-appropriate curriculum that focuses on learning through play, activities, and exploration. Our teaching methods encourage curiosity, creativity, and confidence in every child.\"},{\"title\":\"Trained & Caring Teachers\",\"description\":\"Our educators are professionally trained, experienced, and passionate about early childhood education. They understand each child’s unique needs and provide individual attention in a warm and supportive environment.\"},{\"title\":\"Safe, Secure & Hygienic Campus\",\"description\":\"Child safety is our top priority. Our campus is fully secured with 24×7 CCTV surveillance, child-friendly infrastructure, hygienic classrooms, and strict supervision to ensure a safe learning space.\"},{\"title\":\"Activity-Based & Play-Way Learning\",\"description\":\"Children learn best when learning is fun. We integrate art, craft, music, dance, storytelling, and games into daily activities to support physical, emotional, social, and cognitive development.\"},{\"title\":\"Positive & Joyful Environment\",\"description\":\"We create a welcoming atmosphere where children feel happy, confident, and comfortable—just like a second home. This helps them develop strong social skills and emotional well-being.\"},{\"title\":\"Individual Attention to Every Child\",\"description\":\"Small class sizes allow us to focus on every child’s progress. We observe, guide, and support each child based on their learning pace and interests.\"},{\"title\":\"Parent Partnership & Transparency\",\"description\":\"We believe parents are our partners in a child’s learning journey. Regular communication, updates, and open interaction help build trust and transparency.\"},{\"title\":\"Trusted & Quality Preschool\",\"description\":\"Designed and operated as per Government Approved Standards, Pioneer Play School is trusted by parents for quality education, strong safety measures, and a nurturing environment.\"}]', '[{\"step\": 1, \"title\": \"Visit & Tour\", \"description\": \"Schedule a campus visit and meet our team\"}, {\"step\": 2, \"title\": \"Submit Documents\", \"description\": \"Fill form and submit birth certificate / ID proof\"}, {\"step\": 3, \"title\": \"Pay Fees\", \"description\": \"Pay registration & term fees to confirm seat\"}, {\"step\": 4, \"title\": \"Orientation\", \"description\": \"Attend orientation before term starts\"}]', '[{\"name\":\"Nursery Student\",\"role\":\"Parent\",\"quote\":\"“Pioneer Play School provides a safe and loving environment. My child enjoys coming to school every day.”\",\"photo\":\"\\/assets\\/uploads\\/1770733979_e1ef5b4449_Logo01.png\"},{\"name\":\"L.K.G. Student\",\"role\":\"Parent\",\"quote\":\"“The teachers are very caring and supportive. We have seen great improvement in our child’s confidence.”\",\"photo\":\"\\/assets\\/uploads\\/1770734002_97bca7eabe_Logo.png\"},{\"name\":\"U.K.G. Student\",\"role\":\"Parent\",\"quote\":\"“Excellent school with strong safety and quality education. Highly recommended.”\",\"photo\":\"\\/assets\\/uploads\\/1770734043_64521c2ece_Logo.png\"}]', '[{\"q\":\"Q. What age group do you accept?\",\"a\":\"We accept children from Play Group to U.K.G. as per age eligibility.\"},{\"q\":\"Q. Is the curriculum CBSE based?\",\"a\":\"Yes, our preschool follows a CBSE pattern–based curriculum.\"},{\"q\":\"Q. Is the school safe for children?\",\"a\":\"Absolutely. We have CCTV surveillance, trained staff, and child-safe infrastructure.\"},{\"q\":\"Q. How can parents apply for admission?\",\"a\":\"Parents can fill out the admission enquiry form or contact us directly.\"}]', '+917770007156');

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(128) NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `data` text DEFAULT NULL,
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `class_id` int(10) UNSIGNED DEFAULT NULL,
  `parent_id` int(10) UNSIGNED DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `admission_date` date DEFAULT NULL,
  `academic_year` varchar(7) NOT NULL,
  `status` enum('active','inactive','alumni','pending') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `middle_name` varchar(191) DEFAULT NULL,
  `form_no` varchar(64) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `place_of_birth` varchar(255) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `caste` varchar(100) DEFAULT NULL,
  `languages` text DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `pin` varchar(20) DEFAULT NULL,
  `father_first` varchar(100) DEFAULT NULL,
  `father_middle` varchar(100) DEFAULT NULL,
  `father_last` varchar(100) DEFAULT NULL,
  `father_email` varchar(191) DEFAULT NULL,
  `father_edu` varchar(191) DEFAULT NULL,
  `father_prof` varchar(191) DEFAULT NULL,
  `father_designation` varchar(191) DEFAULT NULL,
  `father_phone` varchar(30) DEFAULT NULL,
  `mother_first` varchar(100) DEFAULT NULL,
  `mother_middle` varchar(100) DEFAULT NULL,
  `mother_last` varchar(100) DEFAULT NULL,
  `mother_email` varchar(191) DEFAULT NULL,
  `mother_edu` varchar(191) DEFAULT NULL,
  `mother_prof` varchar(191) DEFAULT NULL,
  `mother_designation` varchar(191) DEFAULT NULL,
  `mother_phone` varchar(30) DEFAULT NULL,
  `guardian_name` varchar(191) DEFAULT NULL,
  `guardian_email` varchar(191) DEFAULT NULL,
  `guardian_relation` varchar(100) DEFAULT NULL,
  `guardian_phone` varchar(30) DEFAULT NULL,
  `previous_school` varchar(255) DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `health_conditions` text DEFAULT NULL,
  `current_medications` text DEFAULT NULL,
  `immunization_records` text DEFAULT NULL,
  `sibling1` varchar(255) DEFAULT NULL,
  `sibling2` varchar(255) DEFAULT NULL,
  `additional_info` text DEFAULT NULL,
  `parent_signature` varchar(255) DEFAULT NULL,
  `total_fees` decimal(10,2) DEFAULT NULL,
  `installment1` decimal(10,2) DEFAULT NULL,
  `installment2` decimal(10,2) DEFAULT NULL,
  `installment3` decimal(10,2) DEFAULT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `stamp` varchar(255) DEFAULT NULL,
  `extended_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extended_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `school_id`, `first_name`, `last_name`, `dob`, `class_id`, `parent_id`, `photo_path`, `admission_date`, `academic_year`, `status`, `created_at`, `updated_at`, `middle_name`, `form_no`, `location`, `place_of_birth`, `nationality`, `caste`, `languages`, `address`, `city`, `state`, `country`, `pin`, `father_first`, `father_middle`, `father_last`, `father_email`, `father_edu`, `father_prof`, `father_designation`, `father_phone`, `mother_first`, `mother_middle`, `mother_last`, `mother_email`, `mother_edu`, `mother_prof`, `mother_designation`, `mother_phone`, `guardian_name`, `guardian_email`, `guardian_relation`, `guardian_phone`, `previous_school`, `allergies`, `health_conditions`, `current_medications`, `immunization_records`, `sibling1`, `sibling2`, `additional_info`, `parent_signature`, `total_fees`, `installment1`, `installment2`, `installment3`, `remark`, `stamp`, `extended_json`) VALUES
(1016, 1, 'Riddhi', 'phatale', '2022-01-01', 15, 1074, 'uploads/students/30df0d2ca77ed4b85b2cf1a9.jpg', '2026-03-14', '2024-25', 'active', '2026-03-14 16:19:26', '2026-03-29 03:58:22', 'Siddhaji', 'FORM-20260314-9833', 'Latur', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"form_no\":\"FORM-20260314-9833\",\"location\":\"Latur\",\"academic_year\":\"2024-25\",\"admission_seeking_in\":15,\"student\":{\"first\":\"Riddhi\",\"middle\":\"Siddhaji\",\"last\":\"Fatale\",\"dob\":\"2022-01-01\",\"gender\":\"female\"},\"place_of_birth\":\"\",\"nationality\":\"\",\"caste\":\"\",\"languages\":\"\",\"address\":{\"address\":\"\",\"city\":\"\",\"state\":\"\",\"country\":\"\",\"pin\":\"\"},\"father\":{\"first\":\"\",\"phone\":\"\"},\"mother\":{\"first\":\"\",\"phone\":\"\"},\"guardian\":{\"name\":\"\",\"email\":\"\",\"phone\":\"\",\"relation\":\"\"},\"previous_school\":\"\",\"medical\":{\"allergies\":\"\",\"health_conditions\":\"\",\"current_medications\":\"\",\"immunization_records\":\"\"},\"siblings\":{\"1\":\"\",\"2\":\"\"},\"additional_info\":\"\",\"fees\":{\"total\":\"18000\",\"installments\":[\"\",\"\",\"\"],\"remark\":\"\",\"stamp\":\"\"},\"created_at\":\"2026-03-14 16:19:26\"}'),
(1017, 1, 'Adhira Deepak', 'Dawane', '2020-01-01', 15, 1075, 'uploads/students/stu_1017_1c2736ac1148.jpg', '2026-03-16', '2024-25', 'active', '2026-03-16 03:05:17', '2026-03-22 06:47:49', 'Deepak', 'FORM-20260316-6883', 'Latur', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"form_no\":\"FORM-20260316-6883\",\"location\":\"Latur\",\"academic_year\":\"2024-25\",\"admission_seeking_in\":15,\"student\":{\"first\":\"Adhira\",\"middle\":\"Deepak\",\"last\":\"Dawane\",\"dob\":\"2020-01-01\",\"gender\":\"male\"},\"place_of_birth\":\"\",\"nationality\":\"\",\"caste\":\"\",\"languages\":\"\",\"address\":{\"address\":\"\",\"city\":\"\",\"state\":\"\",\"country\":\"\",\"pin\":\"\"},\"father\":{\"first\":\"\",\"phone\":\"\"},\"mother\":{\"first\":\"\",\"phone\":\"\"},\"guardian\":{\"name\":\"\",\"email\":\"\",\"phone\":\"\",\"relation\":\"\"},\"previous_school\":\"\",\"medical\":{\"allergies\":\"\",\"health_conditions\":\"\",\"current_medications\":\"\",\"immunization_records\":\"\"},\"siblings\":{\"1\":\"\",\"2\":\"\"},\"additional_info\":\"\",\"fees\":{\"total\":\"18000\",\"installments\":[\"\",\"\",\"\"],\"remark\":\"\",\"stamp\":\"\"},\"created_at\":\"2026-03-16 03:05:17\"}'),
(1018, 1, 'Sharayu Ramesheshwar', 'Patil', '2020-01-01', 17, 1080, NULL, '2026-03-16', '2024-25', 'active', '2026-03-16 03:08:16', '2026-03-16 03:08:16', 'Ramesheshwar', 'FORM-20260316-8412', 'Latur', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"form_no\":\"FORM-20260316-8412\",\"location\":\"Latur\",\"academic_year\":\"2024-25\",\"admission_seeking_in\":17,\"student\":{\"first\":\"Sharayu\",\"middle\":\"Ramesheshwar\",\"last\":\"Patil\",\"dob\":\"2020-01-01\",\"gender\":\"female\"},\"place_of_birth\":\"\",\"nationality\":\"\",\"caste\":\"\",\"languages\":\"\",\"address\":{\"address\":\"\",\"city\":\"\",\"state\":\"\",\"country\":\"\",\"pin\":\"\"},\"father\":{\"first\":\"\",\"phone\":\"\"},\"mother\":{\"first\":\"\",\"phone\":\"\"},\"guardian\":{\"name\":\"\",\"email\":\"\",\"phone\":\"\",\"relation\":\"\"},\"previous_school\":\"\",\"medical\":{\"allergies\":\"\",\"health_conditions\":\"\",\"current_medications\":\"\",\"immunization_records\":\"\"},\"siblings\":{\"1\":\"\",\"2\":\"\"},\"additional_info\":\"\",\"fees\":{\"total\":\"18000\",\"installments\":[\"\",\"\",\"\"],\"remark\":\"\",\"stamp\":\"\"},\"created_at\":\"2026-03-16 03:08:16\"}'),
(1019, 1, 'Rudra Dinkar', 'Kamble', '2021-01-01', 16, 1076, NULL, '2026-03-16', '2024-25', 'active', '2026-03-16 03:09:47', '2026-03-16 03:09:47', 'Dinkar', 'FORM-20260316-9564', 'Latur', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 17000.00, NULL, NULL, NULL, NULL, NULL, '{\"form_no\":\"FORM-20260316-9564\",\"location\":\"Latur\",\"academic_year\":\"2024-25\",\"admission_seeking_in\":16,\"student\":{\"first\":\"Rudra\",\"middle\":\"Dinkar\",\"last\":\"Kamble\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"place_of_birth\":\"\",\"nationality\":\"\",\"caste\":\"\",\"languages\":\"\",\"address\":{\"address\":\"\",\"city\":\"\",\"state\":\"\",\"country\":\"\",\"pin\":\"\"},\"father\":{\"first\":\"\",\"phone\":\"\"},\"mother\":{\"first\":\"\",\"phone\":\"\"},\"guardian\":{\"name\":\"\",\"email\":\"\",\"phone\":\"\",\"relation\":\"\"},\"previous_school\":\"\",\"medical\":{\"allergies\":\"\",\"health_conditions\":\"\",\"current_medications\":\"\",\"immunization_records\":\"\"},\"siblings\":{\"1\":\"\",\"2\":\"\"},\"additional_info\":\"\",\"fees\":{\"total\":\"17000\",\"installments\":[\"\",\"\",\"\"],\"remark\":\"\",\"stamp\":\"\"},\"created_at\":\"2026-03-16 03:09:47\"}'),
(1020, 1, 'Samarth Satish', 'Rathod', '2020-01-01', 15, 1081, NULL, '2026-03-16', '2024-25', 'active', '2026-03-16 03:12:43', '2026-03-16 03:12:43', 'Satish', 'FORM-20260316-8246', 'Latur', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"form_no\":\"FORM-20260316-8246\",\"location\":\"Latur\",\"academic_year\":\"2024-25\",\"admission_seeking_in\":15,\"student\":{\"first\":\"Samarth\",\"middle\":\"Satish\",\"last\":\"Rathod\",\"dob\":\"2020-01-01\",\"gender\":\"male\"},\"place_of_birth\":\"\",\"nationality\":\"\",\"caste\":\"\",\"languages\":\"\",\"address\":{\"address\":\"\",\"city\":\"\",\"state\":\"\",\"country\":\"\",\"pin\":\"\"},\"father\":{\"first\":\"\",\"phone\":\"\"},\"mother\":{\"first\":\"\",\"phone\":\"\"},\"guardian\":{\"name\":\"\",\"email\":\"\",\"phone\":\"\",\"relation\":\"\"},\"previous_school\":\"\",\"medical\":{\"allergies\":\"\",\"health_conditions\":\"\",\"current_medications\":\"\",\"immunization_records\":\"\"},\"siblings\":{\"1\":\"\",\"2\":\"\"},\"additional_info\":\"\",\"fees\":{\"total\":\"18000\",\"installments\":[\"\",\"\",\"\"],\"remark\":\"\",\"stamp\":\"\"},\"created_at\":\"2026-03-16 03:12:43\"}'),
(1021, 1, 'Utkarsh Prafulla', 'gaikwad', '2021-01-01', 17, 1082, NULL, '2026-03-16', '2024-25', 'active', '2026-03-16 03:27:19', '2026-03-16 03:27:19', 'Prafulla', 'FORM-20260316-1754', 'Latur', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"form_no\":\"FORM-20260316-1754\",\"location\":\"Latur\",\"academic_year\":\"2024-25\",\"admission_seeking_in\":17,\"student\":{\"first\":\"Utkarsh\",\"middle\":\"Prafulla\",\"last\":\"gaikwad\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"place_of_birth\":\"\",\"nationality\":\"\",\"caste\":\"\",\"languages\":\"\",\"address\":{\"address\":\"\",\"city\":\"\",\"state\":\"\",\"country\":\"\",\"pin\":\"\"},\"father\":{\"first\":\"\",\"phone\":\"\"},\"mother\":{\"first\":\"\",\"phone\":\"\"},\"guardian\":{\"name\":\"\",\"email\":\"\",\"phone\":\"\",\"relation\":\"\"},\"previous_school\":\"\",\"medical\":{\"allergies\":\"\",\"health_conditions\":\"\",\"current_medications\":\"\",\"immunization_records\":\"\"},\"siblings\":{\"1\":\"\",\"2\":\"\"},\"additional_info\":\"\",\"fees\":{\"total\":\"18000\",\"installments\":[\"\",\"\",\"\"],\"remark\":\"\",\"stamp\":\"\"},\"created_at\":\"2026-03-16 03:27:19\"}'),
(1022, 1, 'Advika Sachin', 'Dronacharya', '2020-01-01', 15, 1083, NULL, '2026-03-16', '2024-25', 'active', '2026-03-16 03:29:40', '2026-03-16 03:29:40', 'Sachin', 'FORM-20260316-3927', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16000.00, NULL, NULL, NULL, NULL, NULL, '{\"form_no\":\"FORM-20260316-3927\",\"location\":\"\",\"academic_year\":\"2024-25\",\"admission_seeking_in\":15,\"student\":{\"first\":\"Advika\",\"middle\":\"Sachin\",\"last\":\"Dronacharya\",\"dob\":\"2020-01-01\",\"gender\":\"female\"},\"place_of_birth\":\"\",\"nationality\":\"\",\"caste\":\"\",\"languages\":\"\",\"address\":{\"address\":\"\",\"city\":\"\",\"state\":\"\",\"country\":\"\",\"pin\":\"\"},\"father\":{\"first\":\"\",\"phone\":\"\"},\"mother\":{\"first\":\"\",\"phone\":\"\"},\"guardian\":{\"name\":\"\",\"email\":\"\",\"phone\":\"\",\"relation\":\"\"},\"previous_school\":\"\",\"medical\":{\"allergies\":\"\",\"health_conditions\":\"\",\"current_medications\":\"\",\"immunization_records\":\"\"},\"siblings\":{\"1\":\"\",\"2\":\"\"},\"additional_info\":\"\",\"fees\":{\"total\":\"16000\",\"installments\":[\"\",\"\",\"\"],\"remark\":\"\",\"stamp\":\"\"},\"created_at\":\"2026-03-16 03:29:40\"}'),
(1023, 1, 'Shivansh Rahul', 'Bhaybhang', '2020-01-01', 14, 1084, NULL, '2026-03-16', '2024-25', 'active', '2026-03-16 03:42:30', '2026-03-16 03:42:30', 'Rahul', 'FORM-20260316-4268', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3000.00, NULL, NULL, NULL, NULL, NULL, '{\"form_no\":\"FORM-20260316-4268\",\"location\":\"\",\"academic_year\":\"2024-25\",\"admission_seeking_in\":14,\"student\":{\"first\":\"Shivansh\",\"middle\":\"Rahul\",\"last\":\"Bhaybhang\",\"dob\":\"2020-01-01\",\"gender\":\"male\"},\"place_of_birth\":\"\",\"nationality\":\"\",\"caste\":\"\",\"languages\":\"\",\"address\":{\"address\":\"\",\"city\":\"\",\"state\":\"\",\"country\":\"\",\"pin\":\"\"},\"father\":{\"first\":\"\",\"phone\":\"\"},\"mother\":{\"first\":\"\",\"phone\":\"\"},\"guardian\":{\"name\":\"\",\"email\":\"\",\"phone\":\"\",\"relation\":\"\"},\"previous_school\":\"\",\"medical\":{\"allergies\":\"\",\"health_conditions\":\"\",\"current_medications\":\"\",\"immunization_records\":\"\"},\"siblings\":{\"1\":\"\",\"2\":\"\"},\"additional_info\":\"\",\"fees\":{\"total\":\"3000\",\"installments\":[\"\",\"\",\"\"],\"remark\":\"\",\"stamp\":\"\"},\"created_at\":\"2026-03-16 03:42:30\"}'),
(1024, 1, 'Prince Amol', 'Sonwane', '2020-01-01', 15, 1085, NULL, '2026-03-16', '2024-25', 'active', '2026-03-16 03:44:02', '2026-03-16 03:44:02', 'Amol', 'FORM-20260316-7554', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 15000.00, NULL, NULL, NULL, NULL, NULL, '{\"form_no\":\"FORM-20260316-7554\",\"location\":\"\",\"academic_year\":\"2024-25\",\"admission_seeking_in\":15,\"student\":{\"first\":\"Prince\",\"middle\":\"Amol\",\"last\":\"Sonwane\",\"dob\":\"2020-01-01\",\"gender\":\"male\"},\"place_of_birth\":\"\",\"nationality\":\"\",\"caste\":\"\",\"languages\":\"\",\"address\":{\"address\":\"\",\"city\":\"\",\"state\":\"\",\"country\":\"\",\"pin\":\"\"},\"father\":{\"first\":\"\",\"phone\":\"\"},\"mother\":{\"first\":\"\",\"phone\":\"\"},\"guardian\":{\"name\":\"\",\"email\":\"\",\"phone\":\"\",\"relation\":\"\"},\"previous_school\":\"\",\"medical\":{\"allergies\":\"\",\"health_conditions\":\"\",\"current_medications\":\"\",\"immunization_records\":\"\"},\"siblings\":{\"1\":\"\",\"2\":\"\"},\"additional_info\":\"\",\"fees\":{\"total\":\"15000\",\"installments\":[\"\",\"\",\"\"],\"remark\":\"\",\"stamp\":\"\"},\"created_at\":\"2026-03-16 03:44:02\"}'),
(1025, 1, 'Shruti Sachin', 'Kore', '2021-01-01', 15, 1086, NULL, '2026-03-16', '2024-25', 'active', '2026-03-16 03:46:38', '2026-03-16 03:46:38', 'Sachin', 'FORM-20260316-7866', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 15000.00, NULL, NULL, NULL, NULL, NULL, '{\"form_no\":\"FORM-20260316-7866\",\"location\":\"\",\"academic_year\":\"2024-25\",\"admission_seeking_in\":15,\"student\":{\"first\":\"Shruti\",\"middle\":\"Sachin\",\"last\":\"Kore\",\"dob\":\"2021-01-01\",\"gender\":\"female\"},\"place_of_birth\":\"\",\"nationality\":\"\",\"caste\":\"\",\"languages\":\"\",\"address\":{\"address\":\"\",\"city\":\"\",\"state\":\"\",\"country\":\"\",\"pin\":\"\"},\"father\":{\"first\":\"\",\"phone\":\"\"},\"mother\":{\"first\":\"\",\"phone\":\"\"},\"guardian\":{\"name\":\"\",\"email\":\"\",\"phone\":\"\",\"relation\":\"\"},\"previous_school\":\"\",\"medical\":{\"allergies\":\"\",\"health_conditions\":\"\",\"current_medications\":\"\",\"immunization_records\":\"\"},\"siblings\":{\"1\":\"\",\"2\":\"\"},\"additional_info\":\"\",\"fees\":{\"total\":\"15000\",\"installments\":[\"\",\"\",\"\"],\"remark\":\"\",\"stamp\":\"\"},\"created_at\":\"2026-03-16 03:46:38\"}'),
(1026, 1, 'Veer Rupesh', 'Borgavkar', '2020-01-01', NULL, 1087, NULL, '2026-03-16', '2024-25', 'active', '2026-03-16 03:50:49', '2026-03-16 03:50:49', 'Rupesh', 'FORM-20260316-1166', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 17000.00, NULL, NULL, NULL, NULL, NULL, '{\"form_no\":\"FORM-20260316-1166\",\"location\":\"\",\"academic_year\":\"2024-25\",\"admission_seeking_in\":null,\"student\":{\"first\":\"Veer\",\"middle\":\"Rupesh\",\"last\":\"Borgavkar\",\"dob\":\"2020-01-01\",\"gender\":\"male\"},\"place_of_birth\":\"\",\"nationality\":\"\",\"caste\":\"\",\"languages\":\"\",\"address\":{\"address\":\"\",\"city\":\"\",\"state\":\"\",\"country\":\"\",\"pin\":\"\"},\"father\":{\"first\":\"\",\"phone\":\"\"},\"mother\":{\"first\":\"\",\"phone\":\"\"},\"guardian\":{\"name\":\"\",\"email\":\"\",\"phone\":\"\",\"relation\":\"\"},\"previous_school\":\"\",\"medical\":{\"allergies\":\"\",\"health_conditions\":\"\",\"current_medications\":\"\",\"immunization_records\":\"\"},\"siblings\":{\"1\":\"\",\"2\":\"\"},\"additional_info\":\"\",\"fees\":{\"total\":\"17000\",\"installments\":[\"\",\"\",\"\"],\"remark\":\"\",\"stamp\":\"\"},\"created_at\":\"2026-03-16 03:50:49\"}'),
(1027, 1, 'Anandi Pramod', 'Birajdar', '2020-01-01', 17, 1088, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 05:04:01', '2026-03-22 05:04:01', 'Pramod', 'FORM-20260322-3862', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 17000.00, NULL, NULL, NULL, NULL, NULL, '{\"form_no\":\"FORM-20260322-3862\",\"location\":\"\",\"academic_year\":\"2024-25\",\"admission_seeking_in\":17,\"student\":{\"first\":\"Anandi\",\"middle\":\"Pramod\",\"last\":\"Birajdar\",\"dob\":\"2020-01-01\",\"gender\":\"male\"},\"place_of_birth\":\"\",\"nationality\":\"\",\"caste\":\"\",\"languages\":\"\",\"address\":{\"address\":\"\",\"city\":\"\",\"state\":\"\",\"country\":\"\",\"pin\":\"\"},\"father\":{\"first\":\"\",\"phone\":\"\"},\"mother\":{\"first\":\"\",\"phone\":\"\"},\"guardian\":{\"name\":\"\",\"email\":\"\",\"phone\":\"\",\"relation\":\"\"},\"previous_school\":\"\",\"medical\":{\"allergies\":\"\",\"health_conditions\":\"\",\"current_medications\":\"\",\"immunization_records\":\"\"},\"siblings\":{\"1\":\"\",\"2\":\"\"},\"additional_info\":\"\",\"fees\":{\"total\":\"17000\",\"installments\":[\"\",\"\",\"\"],\"remark\":\"\",\"stamp\":\"\"},\"created_at\":\"2026-03-22 05:04:01\"}'),
(1028, 1, 'Sambhaji Ajit', 'Ingale', '2020-01-01', 16, 1090, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 06:22:46', '2026-03-22 06:22:46', 'Ajit', 'FORM-20260322-6495', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 17000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Sambhaji\",\"middle\":\"Ajit\",\"last\":\"Ingale\",\"dob\":\"2020-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1090\",\"school_id\":\"1\",\"total_fees\":\"17000\",\"created_at\":\"2026-03-22 06:22:46\"}'),
(1029, 1, 'Jaideep Ajit', 'Ingale', '2022-09-22', 16, 1090, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 06:22:46', '2026-03-22 06:22:46', 'Ajit', 'FORM-20260322-5578', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Jaideep\",\"middle\":\"Ajit\",\"last\":\"Ingale\",\"dob\":\"2022-09-22\"},\"class_id\":\"16\",\"parent_id\":\"1090\",\"school_id\":\"1\",\"total_fees\":\"16000\",\"created_at\":\"2026-03-22 06:22:46\"}'),
(1030, 1, 'Sajvi Basawaraj', 'Dhanure', '2020-01-21', 16, 1091, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 06:22:46', '2026-03-22 06:22:46', 'Basawaraj', 'FORM-20260322-1773', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Sajvi\",\"middle\":\"Basawaraj\",\"last\":\"Dhanure\",\"dob\":\"2020-01-21\"},\"class_id\":\"16\",\"parent_id\":\"1091\",\"school_id\":\"1\",\"total_fees\":\"16000\",\"created_at\":\"2026-03-22 06:22:46\"}'),
(1031, 1, 'Avni Babu', 'Raut', '2020-01-01', 16, 1092, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 06:22:46', '2026-03-22 06:22:46', 'Babu', 'FORM-20260322-6324', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Avni\",\"middle\":\"Babu\",\"last\":\"Raut\",\"dob\":\"2020-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1092\",\"school_id\":\"1\",\"total_fees\":\"16000\",\"created_at\":\"2026-03-22 06:22:46\"}'),
(1036, 1, 'Siddhant Mahesh', 'Patil', '2020-01-01', 16, 1093, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 06:45:13', '2026-03-22 06:45:13', 'Mahesh', 'FORM-20260322-4235', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Siddhant\",\"middle\":\"Mahesh\",\"last\":\"Patil\",\"dob\":\"2020-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1093\",\"school_id\":\"1\",\"total_fees\":\"18000\",\"created_at\":\"2026-03-22 06:45:13\"}'),
(1037, 1, 'Advik Ajay', 'Kumar', '2020-01-01', 16, 1094, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 06:45:13', '2026-03-22 06:45:13', 'Ajay', 'FORM-20260322-5352', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 13000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Advik\",\"middle\":\"Ajay\",\"last\":\"Kumar\",\"dob\":\"2020-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1094\",\"school_id\":\"1\",\"total_fees\":\"13000\",\"created_at\":\"2026-03-22 06:45:13\"}'),
(1038, 1, 'Rajdeep Vitthalrao', 'Ekunde', '2020-01-01', 16, 1095, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 06:45:13', '2026-03-22 06:45:13', 'Vitthalrao', 'FORM-20260322-8525', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 13000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Rajdeep\",\"middle\":\"Vitthalrao\",\"last\":\"Ekunde\",\"dob\":\"2020-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1095\",\"school_id\":\"1\",\"total_fees\":\"13000\",\"created_at\":\"2026-03-22 06:45:13\"}'),
(1039, 1, 'Raavi Vitthalrao', 'Ekunde', '2020-01-01', 16, 1095, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 06:45:13', '2026-03-22 06:45:13', 'Vitthalrao', 'FORM-20260322-8555', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 13000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Raavi\",\"middle\":\"Vitthalrao\",\"last\":\"Ekunde\",\"dob\":\"2020-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1095\",\"school_id\":\"1\",\"total_fees\":\"13000\",\"created_at\":\"2026-03-22 06:45:13\"}'),
(1040, 1, 'Rajlaxmi Pruthviraj', 'Salunke', '2020-01-01', 16, 1096, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 06:45:13', '2026-03-22 06:45:13', 'Pruthviraj', 'FORM-20260322-7639', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 14500.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Rajlaxmi\",\"middle\":\"Pruthviraj\",\"last\":\"Salunke\",\"dob\":\"2020-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1096\",\"school_id\":\"1\",\"total_fees\":\"14500\",\"created_at\":\"2026-03-22 06:45:13\"}'),
(1041, 1, 'Tanushri Shankar', 'Swami', '2020-01-01', 16, 1097, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 06:45:13', '2026-03-22 06:45:13', 'Shankar', 'FORM-20260322-5367', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Tanushri\",\"middle\":\"Shankar\",\"last\":\"Swami\",\"dob\":\"2020-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1097\",\"school_id\":\"1\",\"total_fees\":\"16000\",\"created_at\":\"2026-03-22 06:45:13\"}'),
(1042, 1, 'Shrinidhi Shrikant', 'More', '2020-01-01', 16, 1098, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 06:45:13', '2026-03-22 06:45:13', 'Shrikant', 'FORM-20260322-3054', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 17000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Shrinidhi\",\"middle\":\"Shrikant\",\"last\":\"More\",\"dob\":\"2020-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1098\",\"school_id\":\"1\",\"total_fees\":\"17000\",\"created_at\":\"2026-03-22 06:45:13\"}'),
(1043, 1, 'Aryan Vyankat', 'Kande', '2021-01-01', 16, 1089, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:02:11', '2026-03-22 07:02:11', 'Vyankat', 'FORM-20260322-6053', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Aryan\",\"middle\":\"Vyankat\",\"last\":\"Kande\",\"dob\":\"2021-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1089\",\"school_id\":\"1\",\"total_fees\":\"18000\",\"created_at\":\"2026-03-22 07:02:11\"}'),
(1044, 1, 'Trisha Ghodke', 'Ghodke', '2020-01-22', 16, 1099, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:15:48', '2026-03-22 07:15:48', 'Ghodke', 'FORM-20260322-8351', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Trisha\",\"middle\":\"Ghodke\",\"last\":\"Ghodke\",\"dob\":\"2020-01-22\"},\"class_id\":\"16\",\"parent_id\":\"1099\",\"school_id\":\"1\",\"total_fees\":\"16000\",\"created_at\":\"2026-03-22 07:15:48\"}'),
(1045, 1, 'Shriyog Shrikant', 'Baride', '2020-01-22', 16, 1100, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:15:48', '2026-03-22 07:15:48', 'Shrikant', 'FORM-20260322-7965', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 15500.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Shriyog\",\"middle\":\"Shrikant\",\"last\":\"Baride\",\"dob\":\"2020-01-22\"},\"class_id\":\"16\",\"parent_id\":\"1100\",\"school_id\":\"1\",\"total_fees\":\"15500\",\"created_at\":\"2026-03-22 07:15:48\"}'),
(1046, 1, 'Atharv Shreeram', 'Davkare', '2020-01-22', 16, 1101, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:15:48', '2026-03-22 07:15:48', 'Shreeram', 'FORM-20260322-2616', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Atharv\",\"middle\":\"Shreeram\",\"last\":\"Davkare\",\"dob\":\"2020-01-22\"},\"class_id\":\"16\",\"parent_id\":\"1101\",\"school_id\":\"1\",\"total_fees\":\"16000\",\"created_at\":\"2026-03-22 07:15:48\"}'),
(1047, 1, 'Iyotiraditya Parmeshwar', 'Dhekne', '2020-01-22', 16, 1102, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:15:48', '2026-03-22 07:15:48', 'Parmeshwar', 'FORM-20260322-4248', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Iyotiraditya\",\"middle\":\"Parmeshwar\",\"last\":\"Dhekne\",\"dob\":\"2020-01-22\"},\"class_id\":\"16\",\"parent_id\":\"1102\",\"school_id\":\"1\",\"total_fees\":\"16000\",\"created_at\":\"2026-03-22 07:15:48\"}'),
(1048, 1, 'Shivansh Ganesh', 'Kamble', '2020-01-22', 16, 1103, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:15:48', '2026-03-22 07:15:48', 'Ganesh', 'FORM-20260322-5119', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Shivansh\",\"middle\":\"Ganesh\",\"last\":\"Kamble\",\"dob\":\"2020-01-22\"},\"class_id\":\"16\",\"parent_id\":\"1103\",\"school_id\":\"1\",\"total_fees\":\"18000\",\"created_at\":\"2026-03-22 07:15:48\"}'),
(1049, 1, 'Mahiraj Mukesh', 'Dhormare', '2020-01-22', 16, 1104, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:15:48', '2026-03-22 07:15:48', 'Mukesh', 'FORM-20260322-1684', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Mahiraj\",\"middle\":\"Mukesh\",\"last\":\"Dhormare\",\"dob\":\"2020-01-22\"},\"class_id\":\"16\",\"parent_id\":\"1104\",\"school_id\":\"1\",\"total_fees\":\"18000\",\"created_at\":\"2026-03-22 07:15:48\"}'),
(1050, 1, 'Shivansh Balaji', 'Kamble', '2020-01-22', 16, 1105, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:15:48', '2026-03-22 07:15:48', 'Balaji', 'FORM-20260322-9098', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 15500.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Shivansh\",\"middle\":\"Balaji\",\"last\":\"Kamble\",\"dob\":\"2020-01-22\"},\"class_id\":\"16\",\"parent_id\":\"1105\",\"school_id\":\"1\",\"total_fees\":\"15500\",\"created_at\":\"2026-03-22 07:15:48\"}'),
(1051, 1, 'Anay Ram', 'Chigure', '2020-01-22', 16, 1106, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:15:48', '2026-03-22 07:15:48', 'Ram', 'FORM-20260322-7977', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 15000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Anay\",\"middle\":\"Ram\",\"last\":\"Chigure\",\"dob\":\"2020-01-22\"},\"class_id\":\"16\",\"parent_id\":\"1106\",\"school_id\":\"1\",\"total_fees\":\"15000\",\"created_at\":\"2026-03-22 07:15:48\"}'),
(1052, 1, 'Vikram Govind', 'Chavan', '2020-01-22', 16, 1107, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:15:48', '2026-03-22 07:15:48', 'Govind', 'FORM-20260322-1909', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Vikram\",\"middle\":\"Govind\",\"last\":\"Chavan\",\"dob\":\"2020-01-22\"},\"class_id\":\"16\",\"parent_id\":\"1107\",\"school_id\":\"1\",\"total_fees\":\"18000\",\"created_at\":\"2026-03-22 07:15:48\"}'),
(1053, 1, 'Garsh Dattatray', 'Paul', '2020-01-22', 16, 1108, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:15:48', '2026-03-22 07:15:48', 'Dattatray', 'FORM-20260322-2358', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Garsh\",\"middle\":\"Dattatray\",\"last\":\"Paul\",\"dob\":\"2020-01-22\"},\"class_id\":\"16\",\"parent_id\":\"1108\",\"school_id\":\"1\",\"total_fees\":\"16000\",\"created_at\":\"2026-03-22 07:15:48\"}'),
(1054, 1, 'Yogeshri Dilip', 'Jadhav', '2020-01-22', 16, 1109, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:15:48', '2026-03-22 07:15:48', 'Dilip', 'FORM-20260322-5157', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 15000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Yogeshri\",\"middle\":\"Dilip\",\"last\":\"Jadhav\",\"dob\":\"2020-01-22\"},\"class_id\":\"16\",\"parent_id\":\"1109\",\"school_id\":\"1\",\"total_fees\":\"15000\",\"created_at\":\"2026-03-22 07:15:48\"}'),
(1055, 1, 'Pransi Pratik', 'Nelge', '2021-01-01', 16, 1110, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:29:47', '2026-03-22 07:29:47', 'Pratik', 'FORM-20260322-6051', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 15000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Pransi\",\"middle\":\"Pratik\",\"last\":\"Nelge\",\"dob\":\"2021-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1110\",\"school_id\":\"1\",\"total_fees\":\"15000\",\"created_at\":\"2026-03-22 07:29:47\"}'),
(1056, 1, 'Varad Dhanraj', 'Nagalgave', '2021-01-01', 16, 1111, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:29:47', '2026-03-22 07:29:47', 'Dhanraj', 'FORM-20260322-3657', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 8000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Varad\",\"middle\":\"Dhanraj\",\"last\":\"Nagalgave\",\"dob\":\"2021-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1111\",\"school_id\":\"1\",\"total_fees\":\"8000\",\"created_at\":\"2026-03-22 07:29:47\"}'),
(1057, 1, 'Aaditi Chavan', 'Chavan', '2021-01-01', 16, 1112, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:29:47', '2026-03-22 07:29:47', 'Chavan', 'FORM-20260322-2166', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Aaditi\",\"middle\":\"Chavan\",\"last\":\"Chavan\",\"dob\":\"2021-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1112\",\"school_id\":\"1\",\"total_fees\":\"5000\",\"created_at\":\"2026-03-22 07:29:47\"}'),
(1058, 1, 'Prisha Ram', 'Savan', '2021-01-01', 16, 1113, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:29:47', '2026-03-22 07:29:47', 'Ram', 'FORM-20260322-9557', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Prisha\",\"middle\":\"Ram\",\"last\":\"Savan\",\"dob\":\"2021-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1113\",\"school_id\":\"1\",\"total_fees\":\"5000\",\"created_at\":\"2026-03-22 07:29:47\"}'),
(1059, 1, 'Durga Vyankat', 'Kande', '2021-01-01', 16, 1089, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:29:47', '2026-03-22 07:29:47', 'Vyankat', 'FORM-20260322-7579', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Durga\",\"middle\":\"Vyankat\",\"last\":\"Kande\",\"dob\":\"2021-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1089\",\"school_id\":\"1\",\"total_fees\":\"5000\",\"created_at\":\"2026-03-22 07:29:47\"}'),
(1060, 1, 'Samiksha Ghuge', 'Ghuge', '2021-01-01', 16, 1115, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:29:47', '2026-03-22 07:29:47', 'Ghuge', 'FORM-20260322-9303', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Samiksha\",\"middle\":\"Ghuge\",\"last\":\"Ghuge\",\"dob\":\"2021-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1115\",\"school_id\":\"1\",\"total_fees\":\"3000\",\"created_at\":\"2026-03-22 07:29:47\"}'),
(1061, 1, 'Riyansh Rahul', 'Bhosale', '2021-01-01', 16, 1116, NULL, '2026-03-22', '2024-25', 'active', '2026-03-22 07:29:47', '2026-03-22 07:29:47', 'Rahul', 'FORM-20260322-5580', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 4000.00, NULL, NULL, NULL, NULL, NULL, '{\"academic_year\":\"2024-25\",\"student\":{\"first\":\"Riyansh\",\"middle\":\"Rahul\",\"last\":\"Bhosale\",\"dob\":\"2021-01-01\"},\"class_id\":\"16\",\"parent_id\":\"1116\",\"school_id\":\"1\",\"total_fees\":\"4000\",\"created_at\":\"2026-03-22 07:29:47\"}'),
(1062, 1, 'Tasmay', 'Joshi', '2021-01-01', NULL, 1117, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Aniket', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 17000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Tasmay\",\"middle\":\"Aniket\",\"last\":\"Joshi\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1117,\"parent_name\":\"Aniket Joshi\",\"total_fees\":\"17000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1063, 1, 'Vedika', 'Chire', '2021-01-01', NULL, 1118, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Ravikumar', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 15500.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Vedika\",\"middle\":\"Ravikumar\",\"last\":\"Chire\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1118,\"parent_name\":\"Ravikumar Chire\",\"total_fees\":\"15500\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1064, 1, 'Dhanishka', 'Chole', '2021-01-01', NULL, 1119, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 15500.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Dhanishka\",\"middle\":\"\",\"last\":\"Chole\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1119,\"parent_name\":\"Chole\",\"total_fees\":\"15500\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1065, 1, 'Adhiraj', 'Gapat', '2021-01-01', NULL, 1120, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Pradeep', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Adhiraj\",\"middle\":\"Pradeep\",\"last\":\"Gapat\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1120,\"parent_name\":\"Pradeep Gapat\",\"total_fees\":\"18000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1066, 1, 'Sai', 'Gade', '2021-01-01', NULL, 1121, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Akash', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 15000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Sai\",\"middle\":\"Akash\",\"last\":\"Gade\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1121,\"parent_name\":\"Akash Gade\",\"total_fees\":\"15000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1067, 1, 'Advik', 'Dalve', '2021-01-01', NULL, 1122, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Ajaykumar', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 15000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Advik\",\"middle\":\"Ajaykumar\",\"last\":\"Dalve\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1122,\"parent_name\":\"Ajaykumar Dalve\",\"total_fees\":\"15000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1068, 1, 'Dakshit', 'Thombare', '2021-01-01', NULL, 1123, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Dnyaneswar', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Dakshit\",\"middle\":\"Dnyaneswar\",\"last\":\"Thombare\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1123,\"parent_name\":\"Dnyaneswar Thombare\",\"total_fees\":\"18000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1069, 1, 'Soham', 'Malile', '2021-01-01', NULL, 1124, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Dnyaneswar', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Soham\",\"middle\":\"Dnyaneswar\",\"last\":\"Malile\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1124,\"parent_name\":\"Dnyaneswar Malile\",\"total_fees\":\"18000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1070, 1, 'Utkarsh', 'Gharnikar', '2021-01-01', NULL, 1125, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Pravin', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16500.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Utkarsh\",\"middle\":\"Pravin\",\"last\":\"Gharnikar\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1125,\"parent_name\":\"Pravin Gharnikar\",\"total_fees\":\"16500\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1071, 1, 'Avni', 'Gaikwad', '2021-01-01', NULL, 1126, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Vasant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16500.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Avni\",\"middle\":\"Vasant\",\"last\":\"Gaikwad\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1126,\"parent_name\":\"Vasant Gaikwad\",\"total_fees\":\"16500\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1072, 1, 'Aarnik', 'Birajdar', '2021-01-01', NULL, 1127, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Ajay', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Aarnik\",\"middle\":\"Ajay\",\"last\":\"Birajdar\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1127,\"parent_name\":\"Ajay Birajdar\",\"total_fees\":\"18000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1073, 1, 'Swanandi', 'Vhanale', '2021-01-01', NULL, 1128, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Khandappa', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 19000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Swanandi\",\"middle\":\"Khandappa\",\"last\":\"Vhanale\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1128,\"parent_name\":\"Khandappa Vhanale\",\"total_fees\":\"19000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1074, 1, 'Riyansh', 'Jadhav', '2021-01-01', NULL, 1129, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Rahul', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Riyansh\",\"middle\":\"Rahul\",\"last\":\"Jadhav\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1129,\"parent_name\":\"Rahul Jadhav\",\"total_fees\":\"18000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1075, 1, 'Vipraj', 'Ghodke', '2021-01-01', NULL, 1130, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Vijaykumar', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Vipraj\",\"middle\":\"Vijaykumar\",\"last\":\"Ghodke\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1130,\"parent_name\":\"Vijaykumar Ghodke\",\"total_fees\":\"18000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}');
INSERT INTO `students` (`id`, `school_id`, `first_name`, `last_name`, `dob`, `class_id`, `parent_id`, `photo_path`, `admission_date`, `academic_year`, `status`, `created_at`, `updated_at`, `middle_name`, `form_no`, `location`, `place_of_birth`, `nationality`, `caste`, `languages`, `address`, `city`, `state`, `country`, `pin`, `father_first`, `father_middle`, `father_last`, `father_email`, `father_edu`, `father_prof`, `father_designation`, `father_phone`, `mother_first`, `mother_middle`, `mother_last`, `mother_email`, `mother_edu`, `mother_prof`, `mother_designation`, `mother_phone`, `guardian_name`, `guardian_email`, `guardian_relation`, `guardian_phone`, `previous_school`, `allergies`, `health_conditions`, `current_medications`, `immunization_records`, `sibling1`, `sibling2`, `additional_info`, `parent_signature`, `total_fees`, `installment1`, `installment2`, `installment3`, `remark`, `stamp`, `extended_json`) VALUES
(1076, 1, 'Mahira', 'Koli', '2021-01-01', NULL, 1131, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Mahesh', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16500.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Mahira\",\"middle\":\"Mahesh\",\"last\":\"Koli\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1131,\"parent_name\":\"Mahesh Koli\",\"total_fees\":\"16500\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1077, 1, 'Anshika', 'Sonkamble', '2021-01-01', NULL, 1132, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Yuvraj', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 17500.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Anshika\",\"middle\":\"Yuvraj\",\"last\":\"Sonkamble\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1132,\"parent_name\":\"Yuvraj Sonkamble\",\"total_fees\":\"17500\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1078, 1, 'Yug', 'Deshmukh', '2021-01-01', NULL, 1133, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Laxman', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 20000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Yug\",\"middle\":\"Laxman\",\"last\":\"Deshmukh\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1133,\"parent_name\":\"Laxman Deshmukh\",\"total_fees\":\"20000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1079, 1, 'Rathod', 'Satish', '2021-01-01', NULL, 1134, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Samarth', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 20000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Rathod\",\"middle\":\"Samarth\",\"last\":\"Satish\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1134,\"parent_name\":\"Samarth Satish\",\"total_fees\":\"20000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1080, 1, 'Mahit', 'Mote', '2021-01-01', NULL, 1135, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Raju', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 21000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Mahit\",\"middle\":\"Raju\",\"last\":\"Mote\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1135,\"parent_name\":\"Raju Mote\",\"total_fees\":\"21000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1081, 1, 'Sajvi', 'Dhanure', '2021-01-01', NULL, 1136, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Basawaraj', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 20000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Sajvi\",\"middle\":\"Basawaraj\",\"last\":\"Dhanure\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1136,\"parent_name\":\"Basawaraj Dhanure\",\"total_fees\":\"20000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1082, 1, 'Riyansh', 'Bhosale', '2021-01-01', NULL, 1137, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Rahul', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 20000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Riyansh\",\"middle\":\"Rahul\",\"last\":\"Bhosale\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1137,\"parent_name\":\"Rahul Bhosale\",\"total_fees\":\"20000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1083, 1, 'Mihika', 'Khonde', '2021-01-01', NULL, 1138, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Ganesh', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Mihika\",\"middle\":\"Ganesh\",\"last\":\"Khonde\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1138,\"parent_name\":\"Ganesh Khonde\",\"total_fees\":\"18000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1084, 1, 'Sarutchi', 'Khaire', '2021-01-01', NULL, 1139, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 6000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Sarutchi\",\"middle\":\"\",\"last\":\"Khaire\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1139,\"parent_name\":\"Sarutchi  Khaire\",\"total_fees\":\"6000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1085, 1, 'Adsule', 'Adsule', '2021-01-01', NULL, 1140, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 4000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Adsule\",\"middle\":\"\",\"last\":\"Adsule\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1140,\"parent_name\":\"Adsule\",\"total_fees\":\"4000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1086, 1, 'Varad', 'Nagalgave', '2021-01-01', NULL, 1111, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Dharaj', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 19000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Varad\",\"middle\":\"Dharaj\",\"last\":\"Nagalgave\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1111,\"parent_name\":\"Dhanraj Nagalgave\",\"total_fees\":\"19000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1087, 1, 'Aaditi', 'Chavan', '2021-01-01', NULL, 1107, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Govind', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16500.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Aaditi\",\"middle\":\"Govind\",\"last\":\"Chavan\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1107,\"parent_name\":\"Govind Chavan\",\"total_fees\":\"16500\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1088, 1, 'Rajdeep', 'Ekunde', '2021-01-01', NULL, 1095, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Vitthal', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 15000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Rajdeep\",\"middle\":\"Vitthal\",\"last\":\"Ekunde\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1095,\"parent_name\":\"Vitthalrao Ekunde\",\"total_fees\":\"15000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1089, 1, 'Raavi', 'Ekunde', '2021-01-01', NULL, 1095, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Vitthal', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 15000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Raavi\",\"middle\":\"Vitthal\",\"last\":\"Ekunde\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1095,\"parent_name\":\"Vitthalrao Ekunde\",\"total_fees\":\"15000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1090, 1, 'Shriyog', 'Baride', '2021-01-01', NULL, 1100, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'Shrikant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16500.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"Shriyog\",\"middle\":\"Shrikant\",\"last\":\"Baride\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1100,\"parent_name\":\"Baride Shrikant\",\"total_fees\":\"16500\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1091, 1, 'aadhira', 'bawane', '2021-01-01', NULL, 1075, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'deepak', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 19000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"aadhira\",\"middle\":\"deepak\",\"last\":\"bawane\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1075,\"parent_name\":\"Deepak Bawane\",\"total_fees\":\"19000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1092, 1, 'vikram', 'chavan', '2021-01-01', NULL, 1107, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'govind', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18500.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"vikram\",\"middle\":\"govind\",\"last\":\"chavan\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1107,\"parent_name\":\"Govind Chavan\",\"total_fees\":\"18500\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1093, 1, 'advika', 'dronacharya', '2021-01-01', NULL, 1083, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'sachin', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 20000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"advika\",\"middle\":\"sachin\",\"last\":\"dronacharya\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1083,\"parent_name\":\"Sashin Dronacharya\",\"total_fees\":\"20000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1094, 1, 'anay', 'chigure', '2021-01-01', NULL, 1106, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', 'ram', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 8000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"anay\",\"middle\":\"ram\",\"last\":\"chigure\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1106,\"parent_name\":\"Ram Chigure\",\"total_fees\":\"8000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1095, 1, 'mahiraj', 'dhormare', '2021-01-01', NULL, 1104, NULL, '2002-06-25', '2025-26', 'active', '2026-03-22 17:56:05', '2026-03-22 17:56:05', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 20000.00, NULL, NULL, NULL, NULL, NULL, '{\"student\":{\"first\":\"mahiraj\",\"middle\":\"\",\"last\":\"dhormare\",\"dob\":\"2021-01-01\",\"gender\":\"male\"},\"class_id\":null,\"school_id\":1,\"academic_year\":\"2025-26\",\"parent_id\":1104,\"parent_name\":\"Mukesh Dhormare\",\"total_fees\":\"20000\",\"meta\":\"{\\\"notes\\\"}\",\"created_at\":\"2026-03-22T17:56:05+00:00\"}'),
(1096, 1, 'Jyotiraditya Parmeshwar', 'Dhekane', '2020-01-01', 14, 1141, NULL, '2026-03-22', '2025-26', 'active', '2026-03-22 18:00:19', '2026-03-22 18:00:19', 'Parmeshwar', 'FORM-20260322-9632', 'Latur', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16500.00, NULL, NULL, NULL, NULL, NULL, '{\"form_no\":\"FORM-20260322-9632\",\"location\":\"Latur\",\"academic_year\":\"2025-26\",\"admission_seeking_in\":14,\"student\":{\"first\":\"Jyotiraditya\",\"middle\":\"Parmeshwar\",\"last\":\"Dhekane\",\"dob\":\"2020-01-01\",\"gender\":\"female\"},\"place_of_birth\":\"\",\"nationality\":\"\",\"caste\":\"\",\"languages\":\"\",\"address\":{\"address\":\"\",\"city\":\"\",\"state\":\"\",\"country\":\"\",\"pin\":\"\"},\"father\":{\"first\":\"\",\"phone\":\"\"},\"mother\":{\"first\":\"\",\"phone\":\"\"},\"guardian\":{\"name\":\"\",\"email\":\"\",\"phone\":\"\",\"relation\":\"\"},\"previous_school\":\"\",\"medical\":{\"allergies\":\"\",\"health_conditions\":\"\",\"current_medications\":\"\",\"immunization_records\":\"\"},\"siblings\":{\"1\":\"\",\"2\":\"\"},\"additional_info\":\"\",\"fees\":{\"total\":\"16500\",\"installments\":[\"\",\"\",\"\"],\"remark\":\"\",\"stamp\":\"\"},\"created_at\":\"2026-03-22 18:00:19\"}');

-- --------------------------------------------------------

--
-- Table structure for table `student_remarks`
--

CREATE TABLE `student_remarks` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `class_id` int(10) UNSIGNED NOT NULL,
  `teacher_id` int(10) UNSIGNED NOT NULL,
  `remark` text NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'note',
  `date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `status` enum('pending','in_progress','done') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teacher_classes`
--

CREATE TABLE `teacher_classes` (
  `id` int(11) NOT NULL,
  `teacher_id` int(10) UNSIGNED NOT NULL,
  `class_id` int(10) UNSIGNED NOT NULL,
  `role` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `timetable`
--

CREATE TABLE `timetable` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `class_id` int(11) NOT NULL,
  `day_of_week` varchar(16) NOT NULL,
  `period` varchar(32) DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `room` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `phone` varchar(32) NOT NULL,
  `role` enum('owner','accounts','teacher','reception','parent','staff') NOT NULL DEFAULT 'staff',
  `whatsapp_id` varchar(191) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `school_id`, `name`, `phone`, `role`, `whatsapp_id`, `is_active`, `meta`, `created_at`, `updated_at`) VALUES
(1, 1, 'Owner Admin', '919096463943', 'owner', '919096463943', 1, '{\"email\":\"owner@pioneer.example\"}', '2026-02-04 06:35:20', '2026-02-22 07:35:08'),
(1000, 1, 'Admin Reception', '+919096463943', 'reception', '+919096463943', 1, NULL, '2026-02-22 03:10:15', '2026-02-22 03:11:59'),
(1001, 1, 'School Reception', '+917770007801', 'reception', '+917770007801', 1, NULL, '2026-02-22 03:16:16', '2026-02-22 03:16:16'),
(1003, 1, 'Admin Accounts', '9096463943', 'accounts', '919096463943', 1, NULL, '2026-02-22 07:31:49', '2026-03-14 13:18:41'),
(1052, 1, 'School Login Pallavi', '918446881383', 'owner', '918446881383', 1, '{\"note\":\"Sachin Patange\"}', '2026-02-22 21:13:45', '2026-02-24 10:07:34'),
(1059, 1, 'Mukesh Garje', '919423076094‬', 'owner', '919423076094‬', 1, NULL, '2026-02-23 15:03:35', '2026-02-23 15:03:35'),
(1060, 1, 'Nikita Garje', '919922199333‬', 'owner', '919922199333‬', 1, NULL, '2026-02-23 15:04:55', '2026-02-23 15:04:55'),
(1062, 1, 'School Accounts Login', '917770007801', 'accounts', '917770007801', 1, NULL, '2026-02-24 10:08:55', '2026-03-14 13:18:55'),
(1069, 1, 'Pallavi Accounts Login', '8446881383', 'accounts', '918446881383', 1, NULL, '2026-03-14 13:16:52', '2026-03-14 13:18:31'),
(1073, 1, 'Pallavi Reception Login', '+918446881383', 'reception', '+918446881383', 1, NULL, '2026-03-14 13:34:58', '2026-03-14 13:36:52'),
(1074, 1, 'Siddhaji Fatale', '9876543210', 'parent', '9876543210', 1, NULL, '2026-03-14 13:54:03', '2026-03-14 13:54:03'),
(1075, 1, 'Deepak Bawane', '9876543212', 'parent', '9876543212', 1, NULL, '2026-03-14 13:54:24', '2026-03-14 13:54:24'),
(1076, 1, 'Dinkar Kamble', '9876543213', 'parent', '9876543213', 1, NULL, '2026-03-14 13:54:42', '2026-03-14 13:54:42'),
(1080, 1, 'Ramesheshwar Patil', '9876543211', 'parent', '9876543211', 1, NULL, '2026-03-14 13:55:45', '2026-03-14 13:55:45'),
(1081, 1, 'Satish Rathod', '9876543214', 'parent', '9876543214', 1, NULL, '2026-03-14 13:56:21', '2026-03-14 13:56:21'),
(1082, 1, 'Utkarsh Gaikwad', '9876543215', 'parent', '9876543215', 1, NULL, '2026-03-14 13:56:57', '2026-03-14 13:56:57'),
(1083, 1, 'Sashin Dronacharya', '9876543216', 'parent', '9876543216', 1, NULL, '2026-03-14 13:57:13', '2026-03-14 13:57:13'),
(1084, 1, 'Rahul Bhaybhang', '9876543217', 'parent', '9876543217', 1, NULL, '2026-03-14 13:57:29', '2026-03-14 13:57:29'),
(1085, 1, 'Amol Sonwane', '9876543218', 'parent', '9876543218', 1, NULL, '2026-03-14 13:57:47', '2026-03-14 13:57:47'),
(1086, 1, 'Sachin Kore', '9876543219', 'parent', '9876543219', 1, NULL, '2026-03-14 13:58:10', '2026-03-14 13:58:10'),
(1087, 1, 'Rupesh Borgavkar', '9876543220', 'parent', '9876543220', 1, NULL, '2026-03-14 13:58:50', '2026-03-14 13:58:50'),
(1088, 1, 'Pramod Birajdar', '9876543221', 'parent', '9876543221', 1, NULL, '2026-03-14 13:59:14', '2026-03-14 13:59:14'),
(1089, 1, 'Vyankat Kande', '9876543222', 'parent', '9876543222', 1, NULL, '2026-03-14 13:59:32', '2026-03-14 13:59:32'),
(1090, 1, 'Ajit Ingale', '9876543223', 'parent', '9876543223', 1, NULL, '2026-03-14 13:59:48', '2026-03-14 13:59:48'),
(1091, 1, 'Basawaraj Dhanure', '9876543225', 'parent', '9876543225', 1, NULL, '2026-03-14 14:00:03', '2026-03-14 14:00:03'),
(1092, 1, 'Babu Raut', '9876543226', 'parent', '9876543226', 1, NULL, '2026-03-14 14:00:18', '2026-03-14 14:00:18'),
(1093, 1, 'Mahesh Patil', '9876543227', 'parent', '9876543227', 1, NULL, '2026-03-14 14:00:38', '2026-03-14 14:00:38'),
(1094, 1, 'Ajay Kumar', '9876543228', 'parent', '9876543228', 1, NULL, '2026-03-14 14:01:10', '2026-03-14 14:01:10'),
(1095, 1, 'Vitthalrao Ekunde', '9876543229', 'parent', '9876543229', 1, NULL, '2026-03-14 14:08:04', '2026-03-14 14:08:04'),
(1096, 1, 'Pruthviraj Salunke', '9876543231', 'parent', '9876543231', 1, NULL, '2026-03-14 14:08:18', '2026-03-14 14:08:18'),
(1097, 1, 'Shankar Swami', '9876543232', 'parent', '9876543232', 1, NULL, '2026-03-14 14:10:30', '2026-03-14 14:10:30'),
(1098, 1, 'Shrikant More', '9876543233', 'parent', '9876543233', 1, NULL, '2026-03-14 14:10:46', '2026-03-14 14:10:46'),
(1099, 1, 'Ghodke', '9876543234', 'parent', '9876543234', 1, NULL, '2026-03-14 14:11:06', '2026-03-14 14:11:06'),
(1100, 1, 'Baride Shrikant', '9876543235', 'parent', '9876543235', 1, NULL, '2026-03-14 14:11:25', '2026-03-14 14:11:25'),
(1101, 1, 'Shreeram Davkare', '9876543236', 'parent', '9876543236', 1, NULL, '2026-03-14 14:11:40', '2026-03-14 14:11:40'),
(1102, 1, 'Parmeshwar Dhekne', '9876543237', 'parent', '9876543237', 1, NULL, '2026-03-14 14:11:56', '2026-03-14 14:11:56'),
(1103, 1, 'Ganesh Kamble', '9876543238', 'parent', '9876543238', 1, NULL, '2026-03-14 14:12:12', '2026-03-14 14:12:12'),
(1104, 1, 'Mukesh Dhormare', '9876543239', 'parent', '9876543239', 1, NULL, '2026-03-14 14:12:28', '2026-03-14 14:12:28'),
(1105, 1, 'Balaji Kamble', '9876543240', 'parent', '9876543240', 1, NULL, '2026-03-14 14:12:44', '2026-03-14 14:12:44'),
(1106, 1, 'Ram Chigure', '9876543241', 'parent', '9876543241', 1, NULL, '2026-03-14 14:12:58', '2026-03-14 14:12:58'),
(1107, 1, 'Govind Chavan', '9876543242', 'parent', '9876543242', 1, NULL, '2026-03-14 14:13:15', '2026-03-14 14:13:15'),
(1108, 1, 'Dattatray Paul', '9876543243', 'parent', '9876543243', 1, NULL, '2026-03-14 14:13:27', '2026-03-14 14:13:27'),
(1109, 1, 'Dilip Jadhav', '9876543244', 'parent', '9876543244', 1, NULL, '2026-03-14 14:13:43', '2026-03-14 14:13:43'),
(1110, 1, 'Pratik Nelge', '9876543245', 'parent', '9876543245', 1, NULL, '2026-03-14 14:14:02', '2026-03-14 14:14:02'),
(1111, 1, 'Dhanraj Nagalgave', '9876543246', 'parent', '9876543246', 1, NULL, '2026-03-14 14:14:21', '2026-03-14 14:14:21'),
(1112, 1, 'Chavan', '9876543247', 'parent', '9876543247', 1, NULL, '2026-03-14 14:14:34', '2026-03-14 14:14:34'),
(1113, 1, 'Ram Savan', '9876543248', 'parent', '9876543248', 1, NULL, '2026-03-14 14:14:47', '2026-03-14 14:14:47'),
(1114, 1, 'Vyankat Kande', '9876543249', 'parent', '9876543249', 1, NULL, '2026-03-14 14:15:07', '2026-03-14 14:15:07'),
(1115, 1, 'Ghuge', '9876543250', 'parent', '9876543250', 1, NULL, '2026-03-14 14:15:24', '2026-03-14 14:15:24'),
(1116, 1, 'Rahul Bhosale', '9876543251', 'parent', '9876543251', 1, NULL, '2026-03-14 14:15:36', '2026-03-14 14:15:36'),
(1117, 1, 'Aniket Joshi', '9765432121', 'parent', '9765432121', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1118, 1, 'Ravikumar Chire', '9765432122', 'parent', '9765432122', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1119, 1, 'Chole', '9765432123', 'parent', '9765432123', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1120, 1, 'Pradeep Gapat', '9765432124', 'parent', '9765432124', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1121, 1, 'Akash Gade', '9765432125', 'parent', '9765432125', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1122, 1, 'Ajaykumar Dalve', '9765432126', 'parent', '9765432126', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1123, 1, 'Dnyaneswar Thombare', '9765432127', 'parent', '9765432127', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1124, 1, 'Dnyaneswar Malile', '9765432128', 'parent', '9765432128', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1125, 1, 'Pravin Gharnikar', '9765432129', 'parent', '9765432129', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1126, 1, 'Vasant Gaikwad', '9765432130', 'parent', '9765432130', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1127, 1, 'Ajay Birajdar', '9765432131', 'parent', '9765432131', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1128, 1, 'Khandappa Vhanale', '9765432132', 'parent', '9765432132', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1129, 1, 'Rahul Jadhav', '9765432133', 'parent', '9765432133', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1130, 1, 'Vijaykumar Ghodke', '9765432134', 'parent', '9765432134', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1131, 1, 'Mahesh Koli', '9765432135', 'parent', '9765432135', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1132, 1, 'Yuvraj Sonkamble', '9765432136', 'parent', '9765432136', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1133, 1, 'Laxman Deshmukh', '9765432137', 'parent', '9765432137', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1134, 1, 'Samarth Satish', '9765432138', 'parent', '9765432138', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1135, 1, 'Raju Mote', '9765432139', 'parent', '9765432139', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1136, 1, 'Basawaraj Dhanure', '9765432140', 'parent', '9765432140', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1137, 1, 'Rahul Bhosale', '9765432141', 'parent', '9765432141', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1138, 1, 'Ganesh Khonde', '9765432142', 'parent', '9765432142', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1139, 1, 'Sarutchi  Khaire', '9765432143', 'parent', '9765432143', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1140, 1, 'Adsule', '9765432144', 'parent', '9765432144', 1, NULL, '2026-03-22 17:15:39', '2026-03-22 17:15:39'),
(1141, 1, 'Parmeshwar Dhekane', '9096463876', 'parent', '9096463876', 1, NULL, '2026-03-22 17:59:02', '2026-03-22 17:59:02');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `alerts`
--
ALTER TABLE `alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_alerts_school` (`school_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_attendance_student_date` (`student_id`,`date`),
  ADD KEY `idx_attendance_school` (`school_id`),
  ADD KEY `fk_attendance_recorded_by` (`recorded_by`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_classes_school` (`school_id`);

--
-- Indexes for table `class_photos`
--
ALTER TABLE `class_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_class` (`class_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_uploaded_by` (`uploaded_by`);

--
-- Indexes for table `complaints`
--
ALTER TABLE `complaints`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_complaints_created_at` (`created_at`),
  ADD KEY `idx_complaints_status` (`status`);

--
-- Indexes for table `enquiries`
--
ALTER TABLE `enquiries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_enquiries_school` (`school_id`),
  ADD KEY `fk_enquiries_assigned` (`assigned_to`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `expense_date` (`expense_date`),
  ADD KEY `category` (`category`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `feedbacks`
--
ALTER TABLE `feedbacks`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `fees_records`
--
ALTER TABLE `fees_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `school_id` (`school_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `homeworks`
--
ALTER TABLE `homeworks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_class_assigned` (`class_id`,`assigned_date`),
  ADD KEY `idx_assigned_date` (`assigned_date`),
  ADD KEY `idx_created_by` (`created_by`);

--
-- Indexes for table `news_events`
--
ALTER TABLE `news_events`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notices`
--
ALTER TABLE `notices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notices_school` (`school_id`),
  ADD KEY `fk_notices_published_by` (`published_by`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_school` (`school_id`),
  ADD KEY `idx_notifications_assigned` (`assigned_to`),
  ADD KEY `idx_notifications_type` (`type`),
  ADD KEY `idx_notifications_status` (`status`),
  ADD KEY `idx_notifications_is_read` (`is_read`);

--
-- Indexes for table `otp_verifications`
--
ALTER TABLE `otp_verifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_otp_phone` (`phone`),
  ADD KEY `idx_otp_expires` (`otp_expires_at`);

--
-- Indexes for table `parents_children`
--
ALTER TABLE `parents_children`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_parent_child` (`parent_user_id`,`child_student_id`),
  ADD KEY `fk_parents_children_child` (`child_student_id`);

--
-- Indexes for table `schools`
--
ALTER TABLE `schools`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_name` (`name`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sessions_user` (`user_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_students_school` (`school_id`),
  ADD KEY `idx_students_class` (`class_id`),
  ADD KEY `fk_students_parent` (`parent_id`),
  ADD KEY `idx_parent_id` (`parent_id`),
  ADD KEY `idx_class_id` (`class_id`),
  ADD KEY `idx_school_id` (`school_id`);

--
-- Indexes for table `student_remarks`
--
ALTER TABLE `student_remarks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_class` (`class_id`),
  ADD KEY `idx_teacher` (`teacher_id`),
  ADD KEY `idx_date` (`date`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `teacher_classes`
--
ALTER TABLE `teacher_classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_teacher_class` (`teacher_id`,`class_id`),
  ADD KEY `idx_teacher_id` (`teacher_id`),
  ADD KEY `idx_class_id` (`class_id`);

--
-- Indexes for table `timetable`
--
ALTER TABLE `timetable`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_class_day` (`class_id`,`day_of_week`),
  ADD KEY `idx_subject` (`subject_id`),
  ADD KEY `idx_teacher` (`teacher_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_phone` (`phone`),
  ADD KEY `idx_school_role` (`school_id`,`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alerts`
--
ALTER TABLE `alerts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `class_photos`
--
ALTER TABLE `class_photos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `complaints`
--
ALTER TABLE `complaints`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `enquiries`
--
ALTER TABLE `enquiries`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feedbacks`
--
ALTER TABLE `feedbacks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fees_records`
--
ALTER TABLE `fees_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=177;

--
-- AUTO_INCREMENT for table `homeworks`
--
ALTER TABLE `homeworks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `news_events`
--
ALTER TABLE `news_events`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notices`
--
ALTER TABLE `notices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otp_verifications`
--
ALTER TABLE `otp_verifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `parents_children`
--
ALTER TABLE `parents_children`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

--
-- AUTO_INCREMENT for table `schools`
--
ALTER TABLE `schools`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1097;

--
-- AUTO_INCREMENT for table `student_remarks`
--
ALTER TABLE `student_remarks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teacher_classes`
--
ALTER TABLE `teacher_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `timetable`
--
ALTER TABLE `timetable`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1142;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `alerts`
--
ALTER TABLE `alerts`
  ADD CONSTRAINT `fk_alerts_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_attendance_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_attendance_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `classes`
--
ALTER TABLE `classes`
  ADD CONSTRAINT `fk_classes_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `enquiries`
--
ALTER TABLE `enquiries`
  ADD CONSTRAINT `fk_enquiries_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_enquiries_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notices`
--
ALTER TABLE `notices`
  ADD CONSTRAINT `fk_notices_published_by` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_notices_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_notifications_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `parents_children`
--
ALTER TABLE `parents_children`
  ADD CONSTRAINT `fk_parents_children_child` FOREIGN KEY (`child_student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_parents_children_parent` FOREIGN KEY (`parent_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sessions`
--
ALTER TABLE `sessions`
  ADD CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `fk_students_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_students_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_students_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `teacher_classes`
--
ALTER TABLE `teacher_classes`
  ADD CONSTRAINT `fk_tc_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_tc_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
