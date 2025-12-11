-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: mariadb
-- Generation Time: Dec 11, 2025 at 04:09 PM
-- Server version: 11.4.5-MariaDB-log
-- PHP Version: 8.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `valselt_id`
--

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `profile_pic` varchar(255) DEFAULT NULL,
  `otp` varchar(6) DEFAULT NULL,
  `otp_expiry` timestamp NULL DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `auth_token` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `profile_pic`, `otp`, `otp_expiry`, `is_verified`, `created_at`, `auth_token`) VALUES
(1, 'ivanaldorino', 'alyaivanselamanya@gmail.com', '$2y$10$El4fWjQUZXM/kbzHDpcRJ.ahcwPaRSpbXpP22v9LWVCmSSUEsq13m', 'https://cdn.ivanaldorino.web.id/valselt/photoprofile/2025-12-07_22-19-23_1.webp', NULL, '2025-12-06 21:35:42', 1, '2025-12-06 21:25:43', NULL),
(2, 'Aldorino051004', 'ivanaldorino@gmail.com', '$2y$10$tM4EsoWLCaikjAAZwi15Gemkyq5yzJVfdrwtMuUj//K6FAceAX0TG', 'https://cdn.ivanaldorino.web.id/spencal/photoprofile/2025-12-07_07-19-22_2.webp', NULL, '2025-12-07 00:28:15', 1, '2025-12-07 00:18:15', NULL),
(3, 'grassenart', 'grassenart@gmail.com', '$2y$10$ygeBnqEveKN2zaQ2AEy74OahwDuW7P4x9k2/cyNURyHKASFlnQrzm', 'https://cdn.ivanaldorino.web.id/valselt/photoprofile/2025-12-07_21-38-19_3.webp', NULL, '2025-12-07 14:38:10', 1, '2025-12-07 14:28:10', NULL),
(4, 'Sal', 'faisal@gmail.com', '$2y$10$kwwKtVg2TQzj8j4CVUQ3o.6QQ.AzyNV7V/hoKLqJxW9TUedHaPYe2', NULL, '393932', '2025-12-11 09:25:50', 0, '2025-12-11 09:15:50', NULL),
(5, 'Cloy', 'mas.naufalraid@gmail.com', '$2y$10$qFtWWkOAIvsMYmMk0GUaM.3W2d33ELjvb6mHXg/tuUEFEQD9.RWoC', NULL, NULL, '2025-12-11 11:14:43', 1, '2025-12-11 11:04:43', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
