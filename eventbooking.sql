-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 21, 2025 at 03:28 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `eventbooking`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendee`
--

CREATE TABLE `attendee` (
  `user_id` int(11) NOT NULL,
  `attendee_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendee`
--

INSERT INTO `attendee` (`user_id`, `attendee_id`) VALUES
(23, 2013),
(27, 2014),
(28, 2015),
(30, 2017),
(31, 2018),
(32, 2019),
(33, 2020),
(34, 2021),
(35, 2022),
(36, 2023),
(38, 2024),
(40, 2025),
(41, 2026);

-- --------------------------------------------------------

--
-- Stand-in structure for view `attendee_total_booking`
-- (See below for the actual view)
--
--
-- Table structure for table `booking`
--

CREATE TABLE `booking` (
  `booking_id` int(11) NOT NULL,
  `attendee_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `booking_time` datetime DEFAULT current_timestamp(),
  `cancellation_time` datetime DEFAULT NULL,
  `status` enum('confirmed','cancelled') DEFAULT 'confirmed',
  `attendance_status` enum('pending','checked_in','no-show') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `booking`
--

INSERT INTO `booking` (`booking_id`, `attendee_id`, `event_id`, `booking_time`, `cancellation_time`, `status`, `attendance_status`) VALUES
(3, 2013, 3, '2025-08-06 21:04:51', '2025-08-07 22:43:17', 'cancelled', 'pending'),
(4, 2013, 7, '2025-08-07 22:43:38', NULL, 'confirmed', 'pending'),
(5, 2013, 8, '2025-08-08 20:23:16', '2025-08-10 19:55:57', 'cancelled', 'pending'),
(8, 2021, 8, '2025-08-08 20:34:43', NULL, 'confirmed', 'pending'),
(9, 2020, 8, '2025-08-08 20:35:17', NULL, 'confirmed', 'pending'),
(10, 2019, 8, '2025-08-08 20:35:49', NULL, 'confirmed', 'checked_in'),
(12, 2017, 8, '2025-08-08 20:37:27', NULL, 'confirmed', 'checked_in'),
(14, 2022, 8, '2025-08-08 20:55:15', NULL, 'confirmed', 'checked_in'),
(16, 2024, 3, '2025-08-09 22:35:43', '2025-08-09 22:38:08', 'cancelled', 'pending'),
(17, 2013, 4, '2025-08-10 19:40:07', '2025-08-10 19:51:05', 'cancelled', 'pending'),
(18, 2013, 11, '2025-08-10 19:50:02', NULL, 'confirmed', 'pending'),
(19, 2025, 9, '2025-08-10 20:15:40', NULL, 'confirmed', 'pending'),
(20, 2023, 9, '2025-08-10 20:19:57', NULL, 'confirmed', 'pending'),
(21, 2023, 7, '2025-08-10 20:50:36', NULL, 'confirmed', 'pending'),
(22, 2025, 12, '2025-08-11 11:36:58', NULL, 'confirmed', 'checked_in'),
(23, 2014, 9, '2025-08-11 11:43:03', NULL, 'confirmed', 'pending'),
(24, 2014, 12, '2025-08-11 11:47:29', NULL, 'confirmed', 'checked_in'),
(25, 2014, 13, '2025-08-11 13:35:44', NULL, 'confirmed', 'pending');

-- --------------------------------------------------------

--
-- Table structure for table `create_event`
--

CREATE TABLE `create_event` (
  `organizer_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `create_event`
--

INSERT INTO `create_event` (`organizer_id`, `event_id`) VALUES
(1011, 3),
(1011, 4),
(1011, 7),
(1011, 9),
(1011, 13),
(1012, 7),
(1012, 10),
(1013, 8),
(1013, 9),
(1013, 12),
(1013, 13),
(1014, 11);

-- --------------------------------------------------------

--
-- Table structure for table `event_collaboration_requests`
--

CREATE TABLE `event_collaboration_requests` (
  `request_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `invited_organizer_id` int(11) NOT NULL,
  `invited_by_organizer_id` int(11) NOT NULL,
  `status` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `responded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `eventdetails`
--

CREATE TABLE `eventdetails` (
  `event_id` int(11) NOT NULL,
  `title` varchar(100) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `event_status` enum('scheduled','cancelled') NOT NULL DEFAULT 'scheduled',
  `cancellation_reason` text DEFAULT NULL,
  `cancellation_time` datetime DEFAULT NULL,
  `cancelled_by_organizer_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `event_cancellation_batches`
--

CREATE TABLE `event_cancellation_batches` (
  `batch_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `requested_by_organizer_id` int(11) NOT NULL,
  `cancellation_reason` text NOT NULL,
  `status` enum('pending','declined','completed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_cancellation_approvals`
--

CREATE TABLE `event_cancellation_approvals` (
  `approval_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `organizer_id` int(11) NOT NULL,
  `status` enum('pending','approved','declined') NOT NULL DEFAULT 'pending',
  `responded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Dumping data for table `eventdetails`
--

INSERT INTO `eventdetails` (`event_id`, `title`, `type`, `event_date`, `location`, `event_status`, `cancellation_reason`, `cancellation_time`, `cancelled_by_organizer_id`) VALUES
(3, 'Tech Talks', 'Seminer', '2025-09-06', 'Dhaka', 'scheduled', NULL, NULL, NULL),
(4, 'TechNova Summit', 'Conference', '2025-07-01', 'Dhaka', 'scheduled', NULL, NULL, NULL),
(7, 'The Opulent Affair', 'Executive Summit', '2025-10-09', 'Los Angeles', 'scheduled', NULL, NULL, NULL),
(8, 'InnovateX', 'Startup Pitch', '2025-08-08', 'Dhaka', 'scheduled', NULL, NULL, NULL),
(9, 'Celestara: A Night of Light and Echo', 'Immersive Multisensory Art & Sound Experience', '2025-09-04', 'Dhaka', 'scheduled', NULL, NULL, NULL),
(10, 'Neon Horizons: A Future Arts Festival', 'Art & Technology Fusion Festival', '2025-06-05', 'Los Angeles', 'scheduled', NULL, NULL, NULL),
(11, 'Echoes of Earth: A Sustainable Living Summit', 'Environmental Conference', '2025-11-07', 'Dhaka', 'scheduled', NULL, NULL, NULL),
(12, 'FitFest 2025', 'Fitness and Wellness Festival', '2025-08-11', 'Dhaka', 'scheduled', NULL, NULL, NULL),
(13, 'Techonology Talk', 'conference', '2025-08-30', 'Dhaka', 'scheduled', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `feedback_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `attendee_id` int(11) NOT NULL,
  `rating` int(11) DEFAULT NULL CHECK (`rating` between 1 and 5),
  `comment` text DEFAULT NULL,
  `feedback_time` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `feedback`
--

INSERT INTO `feedback` (`feedback_id`, `event_id`, `attendee_id`, `rating`, `comment`, `feedback_time`) VALUES
(5, 7, 2023, 5, '', '2025-08-10 20:51:36'),
(1, 8, 2017, 5, 'learnt a lot^^', '2025-08-08 20:46:56'),
(2, 8, 2022, 4, '', '2025-08-08 20:55:32'),
(3, 9, 2025, 4, '', '2025-08-10 20:15:55'),
(4, 9, 2023, 5, 'very interesting', '2025-08-10 20:21:45'),
(6, 9, 2014, 5, '', '2025-08-11 13:36:00');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `related_event_id` int(11) DEFAULT NULL,
  `related_request_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `organizer`
--

CREATE TABLE `organizer` (
  `user_id` int(11) NOT NULL,
  `organizer_id` int(11) NOT NULL,
  `is_admin` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `organizer`
--

INSERT INTO `organizer` (`user_id`, `organizer_id`, `is_admin`) VALUES
(24, 1011, 0),
(25, 1012, 1),
(26, 1013, 0),
(37, 1014, 0);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `phone_number1` char(11) DEFAULT NULL,
  `phone_number2` char(11) DEFAULT NULL,
  `phone_number3` char(11) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('attendee','organizer') NOT NULL DEFAULT 'attendee'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `first_name`, `last_name`, `email`, `phone_number1`, `phone_number2`, `phone_number3`, `password_hash`, `role`) VALUES
(23, 'Samia', 'Akter', 'samiaakter@mail.com', '01710000000', NULL, NULL, '$2y$10$CwvpzxqTAq7c0n6S7wk2Suj4oBS2zgd/ibndiBCD90f4jCDSvE4ga', 'attendee'),
(24, 'Rim', 'Islam', 'rimislam@yhoo.com', '01110000022', NULL, NULL, '$2y$10$wnfuCRejJo0moowdRil3cOaL6XWveGFF1gtrp6UGhLhem7vOTqJGO', 'organizer'),
(25, 'Abdur', 'Jalal', 'abduljalal@mail.com', '01210000000', NULL, NULL, '$2y$10$Ko3GK3dzgNhaJJjiMZ3RL./53B572RZ.WVJlfMXGoaIfpM3bEViYC', 'organizer'),
(26, 'Pranto', 'Saha', 'prantosaha@mail.com', '01210000001', NULL, NULL, '$2y$10$XMTxdnQ3c7Jg0SvzBf8TLOmGDwkk0hEicJNzs1k1UABR.6qp9QyE2', 'organizer'),
(27, 'Ashfika', 'Hossain', 'ashfikahossain@mail.com', '01210000002', NULL, NULL, '$2y$10$ryt3pyaF.6ILXHfgEDBR6OyyqmWcocmP70D6mUwS63pkilNU8LUYe', 'attendee'),
(28, 'Abdul', 'Awal', 'abdulawal@mail.com', '01210000003', NULL, NULL, '$2y$10$bsd6tHKG0g/Pe3GsOUjFNe.feG7y.JJ7B91XnvTE6Nb3OP4OT.Q..', 'attendee'),
(30, 'Rifat', 'Sinthia', 'rifatsinthia@mail.com', '01110000005', '01210000005', NULL, '$2y$10$F9t6HcWNUpsqE4L0i6q4U.KLS3sz1c/X90QRq.PsbbE8Yw0qRP6cK', 'attendee'),
(31, 'Abdullah', 'Azmi', 'abdullahazmi@mail.com', '01110000006', '01210000006', NULL, '$2y$10$0VdggwkzrtpUh0WAtalP/u6GveJcwWV1REUPY9AxVMVsHQJ4h8X1W', 'attendee'),
(32, 'Protul', 'Saha', 'protulsaha@mail.com', '01110000007', '01210000007', NULL, '$2y$10$706p7NXQ3rteyo7Dwi8oyex3F5lUt6b6b0k2iRubgxa/Z9KrfGLWm', 'attendee'),
(33, 'Abir', 'Abrar', 'abirabrar@mail.com', '01210000008', NULL, NULL, '$2y$10$dHODdB93q9ui89vxgcCXZ.E8EK5D3q66xO6M.Tdy7.L05t8SJMOge', 'attendee'),
(34, 'Azmeri', 'Haque', 'azmerihaque@mail.com', '01210000009', NULL, NULL, '$2y$10$jB11kY6.2xusIM5OrBrR6OEyIIkl3FBHkdcrc2k8p2wsYNSWAaF0.', 'attendee'),
(35, 'Zarin', 'Anzum', 'zarinanzum@mail.com', '01110000009', NULL, NULL, '$2y$10$djuvqJvlC4GQ.nGqkEOf/O11BP3.Ck3iaPQGQbCn/numOvF7iY7uy', 'attendee'),
(36, 'Mitali', 'Rehnuma', 'mitalirehnuma@mail.com', '01410000009', NULL, NULL, '$2y$10$c3Lr9KW.cc85zIMKLU83d.jd6IBXkF.sYHIO77dD1sP/6nbyuXofG', 'attendee'),
(37, 'Quazi', 'Fatema', 'quazifatema@mail.com', '01410000001', '01220000000', NULL, '$2y$10$zgRRapnPVFy0Z8K2k2b3uOCJSHttyKMZSZy/xMcp.eX0IlPRaKlKK', 'organizer'),
(38, 'Meher', 'Sultana', 'meheresultana@mail.com', '01410010001', '01210000099', NULL, '$2y$10$pjV2pPTVcMjqCk7Lv4j0ZeBGdhw1.fgp2FyauRA2/cCV4DItr0SDe', 'attendee'),
(40, 'Adnan', 'Fairuz', 'adnanfairuz@mail.com', '01910010801', NULL, NULL, '$2y$10$LsxjywnHdsWjM24qxjmaWOMgeBiynJXXCTkl6MF1OrMR5kqRWy1C.', 'attendee'),
(41, 'Rifah', 'Nanjiba', 'rifah@gmail.com', '01715410660', NULL, NULL, '$2y$10$xQLUzQiKU0xuF9x1tooVwuuqPzkkoq2WHGhXBpJ3Xb/rFPaC550bu', 'attendee');

-- --------------------------------------------------------

--
-- Table structure for table `user_profile`
--

CREATE TABLE `user_profile` (
  `user_id` int(11) NOT NULL,
  `bio` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `social_link1` varchar(255) DEFAULT NULL,
  `social_link2` varchar(255) DEFAULT NULL,
  `social_link3` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_profile`
--

INSERT INTO `user_profile` (`user_id`, `bio`, `profile_picture`, `social_link1`, `social_link2`, `social_link3`) VALUES
(23, 'Hi ^^', 'uploads/profile_pics/23_1754410891.jpg', 'https://www.instagram.com/in/samiaakter', '', ''),
(24, '', 'uploads/profile_pics/24_1754757618.jpg', NULL, NULL, NULL),
(25, 'Hello, This Is Abdul! #admin', 'uploads/profile_pics/25_1754579487.jpg', 'https://abduljalal.dev', 'https://www.linkedin.com/in/abduljalal', ''),
(26, '', NULL, NULL, NULL, NULL),
(27, '', NULL, NULL, NULL, NULL),
(28, '', NULL, NULL, NULL, NULL),
(30, '', NULL, NULL, NULL, NULL),
(31, '', NULL, NULL, NULL, NULL),
(32, '', NULL, NULL, NULL, NULL),
(33, '', NULL, NULL, NULL, NULL),
(34, '', NULL, NULL, NULL, NULL),
(35, '', NULL, NULL, NULL, NULL),
(36, '', NULL, NULL, NULL, NULL),
(37, '', NULL, NULL, NULL, NULL),
(38, 'Hello, I am Meher', 'uploads/profile_pics/38_1754757096.jpg', 'https://www.instagram.com/in/Meher', '', ''),
(40, 'hello', 'uploads/profile_pics/40_1754835332.jpg', 'https://www.instagram.com/in/adnanfairuz', '', ''),
(41, 'hi', 'uploads/profile_pics/41_1754897642.jpg', 'https://www.instagram.com/in/rifah', '', '');

-- --------------------------------------------------------

--
-- Structure for view `attendee_total_booking`
--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendee`
--
ALTER TABLE `attendee`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `attendee_id` (`attendee_id`);

--
-- Indexes for table `booking`
--
ALTER TABLE `booking`
  ADD PRIMARY KEY (`booking_id`),
  ADD UNIQUE KEY `attendee_id` (`attendee_id`,`event_id`),
  ADD KEY `event_id` (`event_id`);

--
-- Indexes for table `create_event`
--
ALTER TABLE `create_event`
  ADD PRIMARY KEY (`organizer_id`,`event_id`),
  ADD KEY `event_id` (`event_id`);

--
-- Indexes for table `eventdetails`
--
ALTER TABLE `eventdetails`
  ADD PRIMARY KEY (`event_id`);

--
-- Indexes for table `event_cancellation_batches`
--
ALTER TABLE `event_cancellation_batches`
  ADD PRIMARY KEY (`batch_id`),
  ADD KEY `idx_ecb_event_status` (`event_id`,`status`),
  ADD KEY `idx_ecb_requester_status` (`requested_by_organizer_id`,`status`);

--
-- Indexes for table `event_cancellation_approvals`
--
ALTER TABLE `event_cancellation_approvals`
  ADD PRIMARY KEY (`approval_id`),
  ADD UNIQUE KEY `uniq_batch_organizer` (`batch_id`,`organizer_id`),
  ADD KEY `idx_eca_organizer_status` (`organizer_id`,`status`);

--
-- Indexes for table `event_collaboration_requests`
--
ALTER TABLE `event_collaboration_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD UNIQUE KEY `uniq_event_invited_organizer` (`event_id`,`invited_organizer_id`),
  ADD KEY `idx_invited_organizer_status` (`invited_organizer_id`,`status`),
  ADD KEY `idx_invited_by_organizer` (`invited_by_organizer_id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`event_id`,`feedback_id`),
  ADD UNIQUE KEY `feedback_id` (`feedback_id`),
  ADD UNIQUE KEY `event_id` (`event_id`,`attendee_id`),
  ADD KEY `attendee_id` (`attendee_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_notifications_user` (`user_id`,`is_read`,`created_at`),
  ADD KEY `idx_notifications_request` (`related_request_id`),
  ADD KEY `related_event_id` (`related_event_id`);

--
-- Indexes for table `organizer`
--
ALTER TABLE `organizer`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `organizer_id` (`organizer_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_profile`
--
ALTER TABLE `user_profile`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `booking`
--
ALTER TABLE `booking`
  MODIFY `booking_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `eventdetails`
--
ALTER TABLE `eventdetails`
  MODIFY `event_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `event_cancellation_batches`
--
ALTER TABLE `event_cancellation_batches`
  MODIFY `batch_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event_cancellation_approvals`
--
ALTER TABLE `event_cancellation_approvals`
  MODIFY `approval_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event_collaboration_requests`
--
ALTER TABLE `event_collaboration_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `feedback_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendee`
--
ALTER TABLE `attendee`
  ADD CONSTRAINT `attendee_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `booking`
--
ALTER TABLE `booking`
  ADD CONSTRAINT `booking_ibfk_1` FOREIGN KEY (`attendee_id`) REFERENCES `attendee` (`attendee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `booking_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `eventdetails` (`event_id`) ON DELETE CASCADE;

--
-- Constraints for table `create_event`
--
ALTER TABLE `create_event`
  ADD CONSTRAINT `create_event_ibfk_1` FOREIGN KEY (`organizer_id`) REFERENCES `organizer` (`organizer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `create_event_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `eventdetails` (`event_id`) ON DELETE CASCADE;

--
-- Constraints for table `event_cancellation_batches`
--
ALTER TABLE `event_cancellation_batches`
  ADD CONSTRAINT `ecb_event_fk` FOREIGN KEY (`event_id`) REFERENCES `eventdetails` (`event_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ecb_requester_fk` FOREIGN KEY (`requested_by_organizer_id`) REFERENCES `organizer` (`organizer_id`) ON DELETE CASCADE;

--
-- Constraints for table `event_cancellation_approvals`
--
ALTER TABLE `event_cancellation_approvals`
  ADD CONSTRAINT `eca_batch_fk` FOREIGN KEY (`batch_id`) REFERENCES `event_cancellation_batches` (`batch_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `eca_organizer_fk` FOREIGN KEY (`organizer_id`) REFERENCES `organizer` (`organizer_id`) ON DELETE CASCADE;

--
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `eventdetails` (`event_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `feedback_ibfk_2` FOREIGN KEY (`attendee_id`) REFERENCES `attendee` (`attendee_id`) ON DELETE CASCADE;

--
-- Constraints for table `event_collaboration_requests`
--
ALTER TABLE `event_collaboration_requests`
  ADD CONSTRAINT `ecr_event_fk` FOREIGN KEY (`event_id`) REFERENCES `eventdetails` (`event_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ecr_invited_by_organizer_fk` FOREIGN KEY (`invited_by_organizer_id`) REFERENCES `organizer` (`organizer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ecr_invited_organizer_fk` FOREIGN KEY (`invited_organizer_id`) REFERENCES `organizer` (`organizer_id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_event_fk` FOREIGN KEY (`related_event_id`) REFERENCES `eventdetails` (`event_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `notifications_request_fk` FOREIGN KEY (`related_request_id`) REFERENCES `event_collaboration_requests` (`request_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `notifications_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `organizer`
--
ALTER TABLE `organizer`
  ADD CONSTRAINT `organizer_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_profile`
--
ALTER TABLE `user_profile`
  ADD CONSTRAINT `user_profile_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
