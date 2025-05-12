-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 12, 2025 at 06:30 PM
-- Server version: 8.4.3
-- PHP Version: 8.3.16

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dmproject`
--

-- --------------------------------------------------------

--
-- Table structure for table `flashcards`
--

CREATE TABLE `flashcards` (
  `flashcard_id` int NOT NULL,
  `set_id` int DEFAULT NULL,
  `question` text,
  `answer` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `friendships`
--

CREATE TABLE `friendships` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `friend_id` int NOT NULL,
  `status` enum('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sets`
--

CREATE TABLE `sets` (
  `set_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shared_sets`
--

CREATE TABLE `shared_sets` (
  `id` int NOT NULL,
  `set_id` int NOT NULL,
  `owner_id` int NOT NULL,
  `user_id` int NOT NULL,
  `shared_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `profile_picture_url` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `flashcards`
--
ALTER TABLE `flashcards`
  ADD PRIMARY KEY (`flashcard_id`),
  ADD KEY `set_id` (`set_id`);

--
-- Indexes for table `friendships`
--
ALTER TABLE `friendships`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_friendship` (`user_id`,`friend_id`),
  ADD KEY `friend_id` (`friend_id`);

--
-- Indexes for table `sets`
--
ALTER TABLE `sets`
  ADD PRIMARY KEY (`set_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `shared_sets`
--
ALTER TABLE `shared_sets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_share` (`set_id`,`user_id`),
  ADD KEY `owner_id` (`owner_id`),
  ADD KEY `user_id` (`user_id`);

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
-- AUTO_INCREMENT for table `flashcards`
--
ALTER TABLE `flashcards`
  MODIFY `flashcard_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `friendships`
--
ALTER TABLE `friendships`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sets`
--
ALTER TABLE `sets`
  MODIFY `set_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `shared_sets`
--
ALTER TABLE `shared_sets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `flashcards`
--
ALTER TABLE `flashcards`
  ADD CONSTRAINT `flashcards_ibfk_1` FOREIGN KEY (`set_id`) REFERENCES `sets` (`set_id`);

--
-- Constraints for table `friendships`
--
ALTER TABLE `friendships`
  ADD CONSTRAINT `friendships_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `friendships_ibfk_2` FOREIGN KEY (`friend_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sets`
--
ALTER TABLE `sets`
  ADD CONSTRAINT `sets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `shared_sets`
--
ALTER TABLE `shared_sets`
  ADD CONSTRAINT `shared_sets_ibfk_1` FOREIGN KEY (`set_id`) REFERENCES `sets` (`set_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shared_sets_ibfk_2` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shared_sets_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
