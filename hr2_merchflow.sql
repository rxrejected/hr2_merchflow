-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Jan 26, 2026 at 04:12 AM
-- Server version: 10.11.14-MariaDB-ubu2204
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `hr2_merchflow`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity`
--

CREATE TABLE `activity` (
  `id` int(11) NOT NULL,
  `message` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assessment`
--

CREATE TABLE `assessment` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `customer_service` enum('Excellent','Good','Average','Poor') NOT NULL,
  `cash_handling` enum('Excellent','Good','Average','Poor') NOT NULL,
  `inventory` enum('Excellent','Good','Average','Poor') NOT NULL,
  `teamwork` enum('Excellent','Good','Average','Poor') NOT NULL,
  `attendance` enum('Excellent','Good','Average','Poor') NOT NULL,
  `comments` text DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assessment`
--

INSERT INTO `assessment` (`id`, `employee_id`, `evaluator_id`, `customer_service`, `cash_handling`, `inventory`, `teamwork`, `attendance`, `comments`, `date_created`) VALUES
(12, 26, 1, 'Poor', 'Average', 'Average', 'Poor', 'Poor', '', '2025-10-07 13:32:46'),
(13, 21, 1, 'Excellent', 'Excellent', 'Average', 'Excellent', 'Excellent', '\"Consistently performs well, shows initiative, and demonstrates strong teamwork. Keep up the great work!\"', '2025-10-11 05:44:40'),
(14, 31, 2, 'Good', 'Good', 'Good', 'Good', 'Good', 'need improvement', '2025-10-11 22:49:35');

-- --------------------------------------------------------

--
-- Table structure for table `assessments`
--

CREATE TABLE `assessments` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assessments`
--

INSERT INTO `assessments` (`id`, `title`, `description`, `created_at`) VALUES
(2, 'OSave Store Operations Knowledge', 'Assessment to evaluate employee knowledge about daily store operations, customer service, and store policies.', '2025-09-28 04:47:02'),
(4, 'OSAVE Convenience Store Quiz', 'Assessment for OSAVE employees to test product knowledge, sales processes, and inventory management.', '2025-09-28 04:39:33');

-- --------------------------------------------------------

--
-- Table structure for table `assessment_answers`
--

CREATE TABLE `assessment_answers` (
  `id` int(11) NOT NULL,
  `assessment_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `selected_option` enum('A','B','C','D') NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assessment_answers`
--

INSERT INTO `assessment_answers` (`id`, `assessment_id`, `question_id`, `user_id`, `selected_option`, `is_correct`, `submitted_at`) VALUES
(14, 4, 34, 2, 'A', 1, '2025-09-30 08:56:56'),
(15, 4, 35, 2, 'B', 1, '2025-09-30 08:56:56'),
(16, 4, 36, 2, 'D', 0, '2025-09-30 08:56:56'),
(17, 4, 37, 2, 'C', 1, '2025-09-30 08:56:56'),
(18, 4, 38, 2, 'B', 1, '2025-09-30 08:56:56'),
(19, 4, 39, 2, 'B', 1, '2025-09-30 08:56:56'),
(20, 4, 40, 2, 'B', 1, '2025-09-30 08:56:56'),
(21, 4, 41, 2, 'A', 1, '2025-09-30 08:56:56'),
(22, 4, 42, 2, 'B', 1, '2025-09-30 08:56:56'),
(23, 4, 43, 2, 'B', 1, '2025-09-30 08:56:56'),
(24, 2, 11, 35, 'B', 1, '2025-10-02 03:50:02'),
(25, 2, 12, 35, 'C', 1, '2025-10-02 03:50:02'),
(26, 2, 13, 35, 'B', 1, '2025-10-02 03:50:02'),
(27, 2, 14, 35, 'C', 1, '2025-10-02 03:50:02'),
(28, 2, 15, 35, 'A', 1, '2025-10-02 03:50:02'),
(39, 4, 39, 35, 'B', 1, '2025-10-02 03:52:20'),
(40, 4, 40, 35, 'B', 1, '2025-10-02 03:52:20'),
(41, 4, 41, 35, 'A', 1, '2025-10-02 03:52:20'),
(42, 4, 42, 35, 'B', 1, '2025-10-02 03:52:20'),
(43, 4, 43, 35, 'B', 1, '2025-10-02 03:52:20'),
(44, 2, 11, 21, 'B', 1, '2025-10-07 10:40:22'),
(45, 2, 12, 21, 'C', 1, '2025-10-07 10:40:22'),
(46, 2, 13, 21, 'B', 1, '2025-10-07 10:40:22'),
(47, 2, 14, 21, 'C', 1, '2025-10-07 10:40:22'),
(48, 2, 15, 21, 'A', 1, '2025-10-07 10:40:22'),
(64, 2, 11, 37, 'B', 1, '2025-10-11 09:51:18'),
(65, 2, 12, 37, 'C', 1, '2025-10-11 09:51:18'),
(66, 2, 13, 37, 'B', 1, '2025-10-11 09:51:18'),
(67, 2, 14, 37, 'C', 1, '2025-10-11 09:51:18'),
(68, 2, 15, 37, 'A', 1, '2025-10-11 09:51:18'),
(69, 2, 16, 37, 'B', 1, '2025-10-11 09:51:18'),
(70, 2, 17, 37, 'B', 1, '2025-10-11 09:51:18'),
(71, 2, 18, 37, 'C', 1, '2025-10-11 09:51:18'),
(72, 2, 19, 37, 'B', 1, '2025-10-11 09:51:18'),
(73, 2, 20, 37, 'B', 1, '2025-10-11 09:51:18'),
(74, 4, 34, 37, 'A', 1, '2025-10-11 09:53:23'),
(75, 4, 35, 37, 'B', 1, '2025-10-11 09:53:23'),
(76, 4, 36, 37, 'A', 1, '2025-10-11 09:53:23'),
(77, 4, 37, 37, 'C', 1, '2025-10-11 09:53:23'),
(78, 4, 38, 37, 'B', 1, '2025-10-11 09:53:23'),
(79, 4, 39, 37, 'B', 1, '2025-10-11 09:53:23'),
(80, 4, 40, 37, 'B', 1, '2025-10-11 09:53:23'),
(81, 4, 41, 37, 'A', 1, '2025-10-11 09:53:23'),
(82, 4, 42, 37, 'B', 1, '2025-10-11 09:53:23'),
(83, 4, 43, 37, 'B', 1, '2025-10-11 09:53:23');

-- --------------------------------------------------------

--
-- Table structure for table `assessment_questions`
--

CREATE TABLE `assessment_questions` (
  `id` int(11) NOT NULL,
  `assessment_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `option_a` varchar(255) NOT NULL,
  `option_b` varchar(255) NOT NULL,
  `option_c` varchar(255) NOT NULL,
  `option_d` varchar(255) NOT NULL,
  `correct_option` enum('A','B','C','D') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assessment_questions`
--

INSERT INTO `assessment_questions` (`id`, `assessment_id`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`) VALUES
(11, 2, 'What is the proper procedure for opening the cash register?', 'Immediately start transactions without counting cash', 'Count cash and log in the starting balance', 'Leave cash unattended', 'D. Take cash home for safekeeping', 'B'),
(12, 2, 'Which of the following is important when restocking shelves?', 'Stack products randomly', 'Place expired items in front', 'Rotate stock with newer items in back', 'D. Ignore product labels', 'C'),
(13, 2, 'How should you greet a customer entering the store?', 'Ignore them', 'Say “Can I help you?” politely', 'Ask them to wait outside', 'D. Only greet regular customers', 'B'),
(14, 2, 'If a customer complains about a damaged product, you should:', 'Argue with them', 'Ignore the complaint', 'Follow store policy to refund or replace', 'D. Ask them to leave', 'C'),
(15, 2, 'What is the maximum number of items you can put on the checkout counter at once?', 'Depends on store policy', '50 items always', 'No limit', 'D. Only 5 items', 'A'),
(16, 2, 'How often should temperature-sensitive products be checked?', 'Once a month', 'Every day during the shift', 'Only during delivery', 'D. Never', 'B'),
(17, 2, 'What is the correct way to handle cash discrepancies?', 'Ignore them', 'Report to supervisor immediately', 'Adjust register yourself secretly', 'D. Blame coworker', 'B'),
(18, 2, 'When should you sanitize frequently touched surfaces?', 'Only at opening', 'Only at closing', 'Regularly throughout the day', 'D. Never', 'C'),
(19, 2, 'Which of these is a proper way to upsell products?', 'Force customers to buy more', 'Suggest complementary items politely', 'C. Ignore cross-selling opportunities', 'D. Charge extra secretly', 'B'),
(20, 2, 'What should you do if you notice a product is expired on the shelf?', 'Leave it for customers', 'Remove it and report if necessary', 'C. Hide it behind other products', 'D. Sell it quickly', 'B'),
(34, 4, 'What is the primary function of a POS (Point of Sale) system in a convenience store?', 'Track inventory and sales', 'Manage employee payroll', 'Handle marketing campaigns', 'Deliver products to customers', 'A'),
(35, 4, 'Which product category is most likely to generate impulse purchases at OSAVE?', 'Toiletries', 'Snacks and beverages', 'Cleaning supplies', 'Stationery', 'B'),
(36, 4, 'What is FIFO in inventory management?', 'First In, First Out', 'Fast Inventory, Fast Out', 'Final Item, First Order', 'Fast In, Fast Out', 'A'),
(37, 4, 'Which payment method is becoming increasingly popular in convenience stores in the Philippines?', 'Cash only', 'Credit card only', 'QR code mobile payment', 'Checks', 'C'),
(38, 4, 'Why is proper shelf placement important in a convenience store?', 'To reduce cleaning time', 'To maximize product visibility and sales', 'To comply with tax rules', 'To store excess products', 'B'),
(39, 4, 'Which of these is a common challenge for OSAVE in managing inventory?', 'Employee attendance', 'Overstocking and stockouts', 'Customer complaints', 'Marketing campaigns', 'B'),
(40, 4, 'What is the main benefit of offering promotions and discounts in a convenience store?', 'Increase store rent', 'Attract more customers and boost sales', 'Reduce working hours', 'Avoid paying taxes', 'B'),
(41, 4, 'Which product would you most likely restock first during peak hours?', 'Daily essentials like bread and milk', 'Seasonal decorations', 'Magazines', 'Stationery', 'A'),
(42, 4, 'Why is checking expiry dates crucial in a convenience store?', 'To improve store aesthetics', 'To prevent selling expired products and protect customer health', 'To increase prices', 'To track employee performance', 'B'),
(43, 4, 'Which system can help OSAVE monitor employee performance in-store?', 'Inventory tracking system', 'Employee scheduling and sales tracking system', 'POS system only', 'Security cameras only', 'B');

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `course_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `video_path` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `skill_type` varchar(10) NOT NULL DEFAULT 'Soft',
  `training_type` varchar(15) NOT NULL DEFAULT 'Theoretical'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`course_id`, `title`, `description`, `video_path`, `created_at`, `skill_type`, `training_type`) VALUES
(6, 'Workplace Safety', 'Learn basic safety rules and practices to keep the workplace safe and accident-free.', 'uploads/workplace safety.mp4', '2025-09-27 05:52:25', 'Soft', 'Theoretical'),
(7, 'How to deal with customers?', 'Learn how to deal with different customers', 'uploads/how to deal.mp4', '2025-09-28 03:39:17', 'Soft', 'Theoretical');

-- --------------------------------------------------------

--
-- Table structure for table `course_progress`
--

CREATE TABLE `course_progress` (
  `progress_id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `course_id` int(10) UNSIGNED NOT NULL,
  `watched_percent` int(11) DEFAULT 0,
  `last_watched_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `course_progress`
--

INSERT INTO `course_progress` (`progress_id`, `employee_id`, `course_id`, `watched_percent`, `last_watched_at`) VALUES
(1, 2, 6, 100, '2025-09-27 08:01:24'),
(899, 2, 7, 14, '2025-10-02 07:08:10'),
(912, 35, 6, 39, '2025-10-02 03:48:13'),
(952, 36, 7, 3, '2025-10-02 07:22:51'),
(958, 36, 6, 12, '2025-10-02 07:33:09'),
(1073, 21, 6, 78, '2026-01-10 15:20:39'),
(1097, 21, 7, 4, '2025-10-07 17:54:42'),
(1149, 37, 7, 16, '2025-10-11 15:09:43'),
(1165, 37, 6, 18, '2025-10-11 16:12:23');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `position` varchar(50) NOT NULL,
  `skills` varchar(255) NOT NULL,
  `performance` varchar(50) NOT NULL,
  `suggested_skills` varchar(255) DEFAULT NULL,
  `next_role` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `name`, `position`, `skills`, `performance`, `suggested_skills`, `next_role`) VALUES
(1, 'Juan Dela Cruz', 'Junior Developer', 'Python, SQL, Excel', 'Excellent', 'Advanced Python, Cloud Computing', 'Senior Developer'),
(2, 'Maria Santos', 'HR Assistant', 'Recruitment, Communication, Excel', 'Good', 'Employee Relations, Recruitment Tools', 'HR Officer'),
(3, 'Pedro Reyes', 'Marketing Staff', 'Social Media, Photoshop, SEO', 'Average', 'SEO, Digital Advertising, Analytics', 'Marketing Manager'),
(4, 'Ana Lopez', 'Sales Associate', 'Customer Service, Sales, Excel', 'Excellent', 'Advanced Sales, CRM', 'Sales Supervisor'),
(5, 'Luis Garcia', 'Inventory Clerk', 'Inventory Management, Excel, Basic Accounting', 'Good', 'Inventory Software, Basic Accounting', 'Inventory Supervisor');

-- --------------------------------------------------------

--
-- Table structure for table `evaluations`
--

CREATE TABLE `evaluations` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `customer_service` enum('Excellent','Good','Average','Poor') NOT NULL,
  `cash_handling` enum('Excellent','Good','Average','Poor') NOT NULL,
  `inventory` enum('Excellent','Good','Average','Poor') NOT NULL,
  `teamwork` enum('Excellent','Good','Average','Poor') NOT NULL,
  `attendance` enum('Excellent','Good','Average','Poor') NOT NULL,
  `overall_rating` enum('Excellent','Good','Average','Poor') NOT NULL,
  `comments` text DEFAULT NULL,
  `evaluation_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `evaluations`
--

INSERT INTO `evaluations` (`id`, `employee_id`, `customer_service`, `cash_handling`, `inventory`, `teamwork`, `attendance`, `overall_rating`, `comments`, `evaluation_date`, `created_at`) VALUES
(1, 2, 'Excellent', 'Good', 'Excellent', 'Good', 'Excellent', 'Excellent', 'Outstanding performance in customer relations and inventory management. Shows great initiative.', '2025-01-05', '2026-01-06 13:54:18');

-- --------------------------------------------------------

--
-- Table structure for table `promotions`
--

CREATE TABLE `promotions` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `old_position` varchar(100) NOT NULL,
  `new_position` varchar(100) NOT NULL,
  `promoted_by` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `date_promoted` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trainings`
--

CREATE TABLE `trainings` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `type` enum('Internal','External') NOT NULL DEFAULT 'Internal',
  `skill_type` enum('Soft','Hard') NOT NULL DEFAULT 'Soft',
  `training_method` enum('Theoretical','Actual') NOT NULL DEFAULT 'Theoretical',
  `date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `training_attendance`
--

CREATE TABLE `training_attendance` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `attended` enum('Yes','No') NOT NULL DEFAULT 'No',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `date_submitted` datetime DEFAULT current_timestamp(),
  `training_result` enum('Passed','Failed') DEFAULT NULL,
  `date_evaluated` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `training_attendance`
--

INSERT INTO `training_attendance` (`id`, `user_id`, `schedule_id`, `attended`, `created_at`, `date_submitted`, `training_result`, `date_evaluated`) VALUES
(52, 36, 3, 'Yes', '2025-10-11 08:09:23', '2025-10-11 08:09:23', NULL, NULL),
(55, 36, 4, 'No', '2025-10-11 08:09:48', '2025-10-11 08:09:48', 'Failed', NULL),
(56, 36, 5, 'No', '2025-10-11 08:09:52', '2025-10-11 08:09:52', NULL, NULL),
(58, 37, 3, 'No', '2025-10-11 09:54:17', '2025-10-11 09:54:17', NULL, NULL),
(59, 37, 4, 'Yes', '2025-10-11 09:54:23', '2025-10-11 09:54:23', 'Failed', NULL),
(60, 37, 5, 'Yes', '2025-10-11 09:54:25', '2025-10-11 09:54:25', 'Passed', NULL),
(62, 2, 3, 'Yes', '2026-01-06 15:13:13', '2026-01-06 23:13:13', 'Passed', NULL),
(63, 2, 4, 'No', '2026-01-06 15:13:18', '2026-01-06 23:13:18', 'Failed', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `training_schedules`
--

CREATE TABLE `training_schedules` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `trainer` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `time` time NOT NULL,
  `end_time` time NOT NULL,
  `venue` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `status` enum('Pending','Passed','Failed') DEFAULT 'Pending',
  `training_type` enum('Theoretical','Actual Practices') DEFAULT 'Theoretical',
  `training_source` enum('Internal','External') DEFAULT 'Internal',
  `type` enum('Internal','External') DEFAULT 'Internal',
  `skill_type` enum('Soft','Hard') DEFAULT 'Soft',
  `training_method` enum('Theoretical','Actual') DEFAULT 'Theoretical'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `training_schedules`
--

INSERT INTO `training_schedules` (`id`, `title`, `trainer`, `date`, `time`, `end_time`, `venue`, `description`, `created_by`, `created_at`, `status`, `training_type`, `training_source`, `type`, `skill_type`, `training_method`) VALUES
(3, 'Employee Training', 'Osave Manager', '2025-10-12', '07:00:00', '12:00:00', 'BMA Avenue Quezon City Osave Branch', 'For new employee only', 1, '2025-10-03 11:19:49', 'Pending', 'Actual Practices', 'Internal', 'Internal', 'Soft', 'Theoretical'),
(4, 'Employee Training', 'Osave Manager', '2025-10-19', '07:00:00', '12:00:00', 'BMA Avenue Quezon City Osave Branch', 'For new employee only.', 1, '2025-10-03 11:22:05', 'Pending', 'Actual Practices', 'Internal', 'Internal', 'Soft', 'Theoretical'),
(5, 'Employee Training', 'Osave Manager', '2025-10-26', '07:00:00', '00:00:00', 'BMA Avenue Quezon City Osave Branch', 'For new employee only.', 1, '2025-10-03 11:24:01', 'Pending', 'Actual Practices', 'External', 'Internal', 'Soft', 'Theoretical'),
(7, 'ss', 'ss', '2026-01-26', '14:05:00', '14:08:00', 'dito lang', 'sss', 1, '2026-01-25 06:05:52', 'Pending', 'Theoretical', 'Internal', 'Internal', 'Soft', 'Theoretical');

-- --------------------------------------------------------

--
-- Table structure for table `training_submit`
--

CREATE TABLE `training_submit` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `time` time NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `training_submit`
--

INSERT INTO `training_submit` (`id`, `title`, `date`, `time`, `created_at`) VALUES
(1, 'Employee Training', '2025-10-05', '07:00:00', '2025-10-03 11:34:00'),
(2, 'Employee Training', '2025-10-12', '07:00:00', '2025-10-07 18:19:39'),
(3, 'Employee Training', '2025-10-26', '07:00:00', '2025-10-09 09:15:53');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'employee',
  `avatar` varchar(255) DEFAULT 'uploads/avatars/default.png',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verify_token` varchar(255) DEFAULT NULL,
  `verification_code` varchar(6) DEFAULT NULL,
  `email_verification_code` varchar(255) DEFAULT NULL,
  `job_position` varchar(100) DEFAULT 'Store Helper',
  `otp_code` varchar(6) DEFAULT NULL,
  `otp_expiry` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `phone`, `address`, `password`, `role`, `avatar`, `created_at`, `is_verified`, `verify_token`, `verification_code`, `email_verification_code`, `job_position`, `otp_code`, `otp_expiry`) VALUES
(1, 'Raymond Rosario', 'rosarioxraymond@gmail.com', '09923769669', 'Ph9 Pkg8 Blk85 Lot Excess Bagong Silang Caloocan City', '$2y$12$9QCSNB17VzwSsy64FKkkEOk.vjuMSBOdnzks/9OHJDav/.IGi8IJa', 'Super Admin', '/uploads/avatars/user_1.jpeg', '2025-09-09 12:05:41', 1, NULL, '631285', NULL, 'Area Operations Manager', NULL, NULL),
(2, 'Karl Pua', 'karlthedestroyer8@gmail.com', '09876543210', 'Sta, Quiteria Caloocan City', '$2y$10$I/dfBeEsQbN9s8VIoceJCOUsy6xnkBcF.9mTYmMPpoFZYikwhd9du', 'Super Admin', '/MerchFlow/uploads/avatars/user_2.jpg', '2025-09-09 14:51:17', 1, NULL, NULL, '998696', 'Assistant Store Manager', NULL, NULL),
(21, 'Osave Project', 'osaveproject2025@gmail.com', '09876543210', 'Quezon City, Metro Manila', '$2y$10$Vc.ltHYEIvQMHRUhd9nYBeTkvZfC3kvchqvh0LnFgus7ElV7vvO5S', 'employee', '/uploads/avatars/user_21.png', '2025-09-21 10:29:24', 1, NULL, NULL, NULL, 'Area Operations Manager', NULL, NULL),
(23, 'Juan Dela Cruz', 'juan.delacruz@example.com', '09171234567', 'Quezon City, Metro Manila', '$2y$10$abc123hashpassworddummy1', 'employee', 'uploads/avatars/default.png', '2025-09-26 02:31:46', 1, NULL, NULL, NULL, 'Assistant Store Manager', NULL, NULL),
(24, 'Maria Santos', 'maria.santos@example.com', '09181234567', 'Makati City, Metro Manila', '$2y$10$abc123hashpassworddummy2', 'employee', 'uploads/avatars/default.png', '2025-09-26 02:31:46', 1, NULL, NULL, NULL, 'Assistant Store Manager', NULL, NULL),
(25, 'Pedro Ramirez', 'pedro.ramirez@example.com', '09221234567', 'Caloocan City, Metro Manila', '$2y$10$abc123hashpassworddummy3', 'employee', 'uploads/avatars/default.png', '2025-09-26 02:31:46', 1, NULL, NULL, NULL, 'Warehouse Supervisor', NULL, NULL),
(26, 'Ana Lopez', 'ana.lopez@example.com', '09331234567', 'Pasig City, Metro Manila', '$2y$10$abc123hashpassworddummy4', 'employee', 'uploads/avatars/default.png', '2025-09-26 02:31:46', 1, NULL, NULL, NULL, 'Central Purchasing Assistant', NULL, NULL),
(27, 'Carlos Reyes', 'carlos.reyes@example.com', '09451234567', 'Taguig City, Metro Manila', '$2y$10$abc123hashpassworddummy5', 'employee', 'uploads/avatars/default.png', '2025-09-26 02:31:46', 1, NULL, NULL, NULL, 'Delivery Driver', NULL, NULL),
(28, 'Elena Cruz', 'elena.cruz@example.com', '09561234567', 'Manila City, Metro Manila', '$2y$10$abc123hashpassworddummy6', 'employee', 'uploads/avatars/default.png', '2025-09-26 02:31:46', 1, NULL, NULL, NULL, 'Store Manager', NULL, NULL),
(29, 'Mark Villanueva', 'mark.villanueva@example.com', '09671234567', 'Marikina City, Metro Manila', '$2y$10$abc123hashpassworddummy7', 'employee', 'uploads/avatars/default.png', '2025-09-26 02:31:46', 1, NULL, NULL, NULL, 'Area Operations Manager', NULL, NULL),
(30, 'Jessa Garcia', 'jessa.garcia@example.com', '09781234567', 'Mandaluyong City, Metro Manila', '$2y$10$abc123hashpassworddummy8', 'employee', 'uploads/avatars/default.png', '2025-09-26 02:31:46', 1, NULL, NULL, NULL, 'Assistant Store Manager', NULL, NULL),
(31, 'Bryan Mendoza', 'bryan.mendoza@example.com', '09891234567', 'San Juan City, Metro Manila', '$2y$10$abc123hashpassworddummy9', 'employee', 'uploads/avatars/default.png', '2025-09-26 02:31:46', 1, NULL, NULL, NULL, 'Store Manager', NULL, NULL),
(32, 'Sophia Ramos', 'sophia.ramos@example.com', '09991234567', 'Las Piñas City, Metro Manila', '$2y$10$abc123hashpassworddummy10', 'employee', 'uploads/avatars/default.png', '2025-09-26 02:31:46', 1, NULL, NULL, NULL, 'Warehouse Supervisor', NULL, NULL),
(34, 'Larida, Mc Dave', 'laridamcdave10@gmail.com', NULL, NULL, '$2y$10$Ku3gM5JlFah1w2aWECHduuHN.3em76nHxzQOsuf04RKcIksTKB8pS', 'Super Admin', 'uploads/avatars/default.png', '2025-10-06 14:35:33', 1, NULL, NULL, NULL, 'Delivery Driver', NULL, NULL),
(35, 'Adrian Hao', 'adrianhao01@gmail.com', NULL, NULL, '$2y$10$89xGyqIgik/HZzd178/WqudLcmTwHBgytQRBIOzbIIk4msV7XFhV6', 'Super Admin', 'uploads/avatars/user_35.jpg', '2025-10-02 03:46:41', 1, NULL, NULL, NULL, 'Store Manager', NULL, NULL),
(36, 'Eko Saputra', 'ekosaputrarosario@gmail.com', NULL, NULL, '$2y$12$eN/sH5XpK2QgbHkErTG7qOElRaG9bJ3WWpJLrgYlSdmzx.wfTmBiS', 'Staff', 'uploads/avatars/default.png', '2025-10-02 07:15:20', 1, NULL, NULL, NULL, 'Delivery Driver', NULL, NULL),
(37, 'Mcdavelarida', 'mcdavelarida6@gmail.com', NULL, NULL, '$2y$12$Md5hI.u6onL2ZGrDVdZYoekL1USDq404v7S6NKwXIhUUBK61X6jZe', 'employee', 'uploads/avatars/default.png', '2025-10-06 14:32:00', 1, NULL, NULL, NULL, 'Assistant Store Manager', NULL, NULL),
(38, 'Karl pua', 'karl@gmail.com', NULL, NULL, '$2y$12$T0VOyp7RTNXTrjlUiRZMlOUTu1ouf6q9rE6IPoFl3Sp0VzN6b6CbS', 'employee', 'uploads/avatars/default.png', '2025-10-07 17:12:35', 0, NULL, '159851', NULL, 'Assistant Store Manager', NULL, NULL),
(40, 'Tyrone', 'tyronestevenbalanlayos@gmail.com', NULL, NULL, '$2y$12$a0Fi3YPBkDAVZc044AMQSuqaUeNi1zJ1XGC7JdKsbOHSwS/b8bCT2', 'employee', 'uploads/avatars/default.png', '2025-10-11 11:45:40', 1, NULL, NULL, NULL, 'Store Helper', NULL, NULL),
(41, 'John carl', 'johncarlgultiano@gmail.com', NULL, NULL, '$2y$12$wuWnK5E56DcQx0ZNE5Q7U.mONsqJIIG2DAyTYNCoz3xLFEnFi4Ome', 'employee', 'uploads/avatars/default.png', '2025-10-11 11:49:29', 0, NULL, '860517', NULL, 'Store Helper', NULL, NULL),
(42, 'Simone Fidradoeia', 'ocean-smooth8827@localglobalmail.com', NULL, NULL, '$2y$12$rB2LSoHTyHfz.lxS4TihJ.w6IE19WpXXe.24HHNb5YHjPvBBcPpTO', 'employee', 'uploads/avatars/default.png', '2026-01-13 00:58:09', 0, NULL, '872676', NULL, 'Store Helper', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `user_id` int(11) NOT NULL,
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_sessions`
--

INSERT INTO `user_sessions` (`user_id`, `last_activity`) VALUES
(35, '2025-10-02 03:47:42');

-- --------------------------------------------------------

--
-- Stand-in structure for view `user_status`
-- (See below for the actual view)
--
CREATE TABLE `user_status` (
`user_id` int(11)
,`full_name` varchar(100)
,`email` varchar(150)
,`status` varchar(7)
,`last_activity` timestamp
);

-- --------------------------------------------------------

--
-- Structure for view `user_status`
--
DROP TABLE IF EXISTS `user_status`;

CREATE ALGORITHM=UNDEFINED DEFINER=`hrnextg2`@`localhost` SQL SECURITY DEFINER VIEW `user_status`  AS SELECT `u`.`id` AS `user_id`, `u`.`full_name` AS `full_name`, `u`.`email` AS `email`, CASE WHEN `s`.`last_activity` >= current_timestamp() - interval 5 minute THEN 'Online' ELSE 'Offline' END AS `status`, `s`.`last_activity` AS `last_activity` FROM (`users` `u` left join `user_sessions` `s` on(`u`.`id` = `s`.`user_id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity`
--
ALTER TABLE `activity`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `assessment`
--
ALTER TABLE `assessment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_employee` (`employee_id`),
  ADD KEY `fk_evaluator` (`evaluator_id`);

--
-- Indexes for table `assessments`
--
ALTER TABLE `assessments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `assessment_answers`
--
ALTER TABLE `assessment_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assessment_id` (`assessment_id`),
  ADD KEY `question_id` (`question_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `assessment_questions`
--
ALTER TABLE `assessment_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assessment_id` (`assessment_id`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`course_id`);

--
-- Indexes for table `course_progress`
--
ALTER TABLE `course_progress`
  ADD PRIMARY KEY (`progress_id`),
  ADD UNIQUE KEY `unique_progress` (`employee_id`,`course_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `evaluations`
--
ALTER TABLE `evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `promotions`
--
ALTER TABLE `promotions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `promoted_by` (`promoted_by`);

--
-- Indexes for table `trainings`
--
ALTER TABLE `trainings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `training_attendance`
--
ALTER TABLE `training_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`user_id`,`schedule_id`),
  ADD KEY `schedule_id` (`schedule_id`);

--
-- Indexes for table `training_schedules`
--
ALTER TABLE `training_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `training_submit`
--
ALTER TABLE `training_submit`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity`
--
ALTER TABLE `activity`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assessment`
--
ALTER TABLE `assessment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `assessments`
--
ALTER TABLE `assessments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `assessment_answers`
--
ALTER TABLE `assessment_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=84;

--
-- AUTO_INCREMENT for table `assessment_questions`
--
ALTER TABLE `assessment_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `course_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `course_progress`
--
ALTER TABLE `course_progress`
  MODIFY `progress_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1189;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `evaluations`
--
ALTER TABLE `evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `promotions`
--
ALTER TABLE `promotions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trainings`
--
ALTER TABLE `trainings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `training_attendance`
--
ALTER TABLE `training_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `training_schedules`
--
ALTER TABLE `training_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `training_submit`
--
ALTER TABLE `training_submit`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assessment`
--
ALTER TABLE `assessment`
  ADD CONSTRAINT `fk_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_evaluator` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `assessment_answers`
--
ALTER TABLE `assessment_answers`
  ADD CONSTRAINT `assessment_answers_ibfk_1` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assessment_answers_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `assessment_questions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assessment_answers_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `assessment_questions`
--
ALTER TABLE `assessment_questions`
  ADD CONSTRAINT `assessment_questions_ibfk_1` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `course_progress`
--
ALTER TABLE `course_progress`
  ADD CONSTRAINT `course_progress_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `evaluations`
--
ALTER TABLE `evaluations`
  ADD CONSTRAINT `evaluations_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `promotions`
--
ALTER TABLE `promotions`
  ADD CONSTRAINT `promotions_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `promotions_ibfk_2` FOREIGN KEY (`promoted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `training_attendance`
--
ALTER TABLE `training_attendance`
  ADD CONSTRAINT `training_attendance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `training_attendance_ibfk_2` FOREIGN KEY (`schedule_id`) REFERENCES `training_schedules` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `training_schedules`
--
ALTER TABLE `training_schedules`
  ADD CONSTRAINT `training_schedules_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
