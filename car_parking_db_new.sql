-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 29, 2025 at 06:10 PM
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
-- Database: `car_parking_db_new`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `award_retroactive_milestone_bonuses` ()   BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE user_name VARCHAR(50);
    DECLARE completed_count INT;
    DECLARE bonus_due INT;
    DECLARE bonus_given INT;
    DECLARE bonus_to_give INT;
    
    -- Cursor to get all users with completed bookings
    DECLARE user_cursor CURSOR FOR 
        SELECT username, COUNT(*) as booking_count
        FROM points_transactions 
        WHERE transaction_type = 'earned'
        GROUP BY username
        HAVING booking_count >= 10;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN user_cursor;
    
    user_loop: LOOP
        FETCH user_cursor INTO user_name, completed_count;
        IF done THEN
            LEAVE user_loop;
        END IF;
        
        -- Calculate bonus due for this user
        SET bonus_due = calculate_milestone_bonus(completed_count);
        
        -- Get bonus already given
        SELECT COALESCE(SUM(points_amount), 0) INTO bonus_given
        FROM points_transactions 
        WHERE username = user_name 
        AND transaction_type = 'bonus' 
        AND description LIKE '%milestone%';
        
        -- Calculate bonus to give
        SET bonus_to_give = bonus_due - bonus_given;
        
        -- Award bonus if due
        IF bonus_to_give > 0 THEN
            UPDATE account_information 
            SET points = points + bonus_to_give 
            WHERE username = user_name;
            
            INSERT INTO points_transactions (username, transaction_type, points_amount, description)
            VALUES (user_name, 'bonus', bonus_to_give, 
                   CONCAT('🎉 Retroactive milestone bonus - ', completed_count, ' bookings completed'));
        END IF;
        
    END LOOP;
    
    CLOSE user_cursor;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `check_user_milestone_status` (IN `target_username` VARCHAR(50))   BEGIN
    DECLARE completed_count INT DEFAULT 0;
    DECLARE bonus_due INT DEFAULT 0;
    DECLARE bonus_given INT DEFAULT 0;
    DECLARE next_milestone INT DEFAULT 0;
    
    -- Get completed bookings count
    SELECT COUNT(*) INTO completed_count
    FROM points_transactions 
    WHERE username = target_username AND transaction_type = 'earned';
    
    -- Calculate bonus due and given
    SET bonus_due = calculate_milestone_bonus(completed_count);
    
    SELECT COALESCE(SUM(points_amount), 0) INTO bonus_given
    FROM points_transactions 
    WHERE username = target_username 
    AND transaction_type = 'bonus' 
    AND description LIKE '%milestone%';
    
    -- Calculate next milestone
    SET next_milestone = (FLOOR(completed_count / 10) + 1) * 10;
    
    -- Display status
    SELECT 
        target_username as username,
        completed_count as completed_bookings,
        bonus_due as total_bonus_due,
        bonus_given as bonus_received,
        (bonus_due - bonus_given) as bonus_missing,
        next_milestone as next_milestone_at,
        (next_milestone - completed_count) as bookings_to_next_milestone;
        
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `get_user_level_info` (IN `username_param` VARCHAR(50))   BEGIN
    DECLARE total_earned INT DEFAULT 0;
    DECLARE current_level VARCHAR(10);
    DECLARE next_level VARCHAR(10) DEFAULT NULL;
    DECLARE next_level_points INT DEFAULT NULL;
    
    -- Get user's total earned points and current level
    SELECT total_earned_points, user_level 
    INTO total_earned, current_level
    FROM account_information 
    WHERE username = username_param;
    
    -- Determine next level and points needed
    IF total_earned < 15 THEN
        SET next_level = 'bronze';
        SET next_level_points = 15;
    ELSEIF total_earned < 100 THEN
        SET next_level = 'gold';
        SET next_level_points = 100;
    ELSEIF total_earned < 161 THEN
        SET next_level = 'diamond';
        SET next_level_points = 161;
    END IF;
    
    SELECT 
        username_param as username,
        total_earned,
        current_level,
        next_level,
        next_level_points,
        CASE 
            WHEN next_level_points IS NULL THEN 0
            ELSE next_level_points - total_earned 
        END as points_to_next_level,
        CASE 
            WHEN current_level = 'bronze' THEN 15
            WHEN current_level = 'gold' THEN 100
            WHEN current_level = 'diamond' THEN 161
        END as current_level_min_points;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `use_points_for_payment` (IN `user` VARCHAR(50), IN `booking_id_param` INT, IN `points_to_use` INT, IN `description_text` VARCHAR(255))   BEGIN
    DECLARE current_points INT DEFAULT 0;
    
    -- Get current points
    SELECT points INTO current_points FROM account_information WHERE username = user;
    
    -- Check if user has enough points
    IF current_points >= points_to_use THEN
        -- Deduct points
        UPDATE account_information 
        SET points = points - points_to_use 
        WHERE username = user;
        
        -- Record the transaction
        INSERT INTO points_transactions (username, transaction_type, points_amount, description, booking_id)
        VALUES (user, 'spent', points_to_use, description_text, booking_id_param);
        
        -- Update booking to mark points were used AND set payment status to paid
        UPDATE bookings 
        SET paid_with_points = TRUE, 
            points_used = points_to_use,
            payment_status = 'paid'  -- THIS LINE WAS MISSING!
        WHERE id = booking_id_param;
        
        -- Create payment record with payment_method = 'points'
        INSERT INTO payments (booking_id, transaction_id, amount, payment_method, payment_status, points_used)
        VALUES (booking_id_param, CONCAT('PTS_', DATE_FORMAT(NOW(), '%Y%m%d%H%i%s'), FLOOR(RAND() * 10000)), 0, 'points', 'paid', points_to_use);
    END IF;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `calculate_milestone_bonus` (`completed_bookings` INT) RETURNS INT(11) DETERMINISTIC BEGIN
    DECLARE bonus_tiers INT DEFAULT 0;
    DECLARE total_bonus INT DEFAULT 0;
    
    -- Calculate how many 10-booking milestones reached
    SET bonus_tiers = FLOOR(completed_bookings / 10);
    
    -- Each milestone = 150 bonus points
    SET total_bonus = bonus_tiers * 150;
    
    RETURN total_bonus;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `can_garage_close_now` (`garage_id_param` VARCHAR(30)) RETURNS TINYINT(1) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE active_count INT DEFAULT 0;
    
    -- Count active and immediate upcoming bookings
    SELECT COUNT(*) INTO active_count
    FROM bookings b
    WHERE b.garage_id = garage_id_param 
    AND b.status IN ('upcoming', 'active')
    AND (
        -- Currently active bookings
        (CONCAT(b.booking_date, ' ', b.booking_time) <= NOW() 
         AND DATE_ADD(CONCAT(b.booking_date, ' ', b.booking_time), INTERVAL b.duration HOUR) > NOW())
        OR
        -- Upcoming bookings within next 30 minutes
        (CONCAT(b.booking_date, ' ', b.booking_time) > NOW() 
         AND CONCAT(b.booking_date, ' ', b.booking_time) <= DATE_ADD(NOW(), INTERVAL 30 MINUTE))
    );
    
    RETURN (active_count = 0);
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `get_garage_current_status` (`garage_id_param` VARCHAR(30)) RETURNS VARCHAR(50) CHARSET utf8mb4 COLLATE utf8mb4_general_ci DETERMINISTIC READS SQL DATA BEGIN
    DECLARE final_status VARCHAR(50) DEFAULT 'UNKNOWN';
    DECLARE current_status_val ENUM('open', 'closed', 'maintenance', 'emergency_closed');
    DECLARE is_manual_override_val BOOLEAN;
    DECLARE override_until_val DATETIME;
    DECLARE is_schedule_open BOOLEAN;
    
    -- Get real-time status
    SELECT current_status, is_manual_override, override_until
    INTO current_status_val, is_manual_override_val, override_until_val
    FROM garage_real_time_status 
    WHERE garage_id = garage_id_param;
    
    -- Get schedule status
    SET is_schedule_open = is_scheduled_open(garage_id_param, NOW());
    
    -- Determine final status
    CASE 
        WHEN current_status_val = 'emergency_closed' THEN 
            SET final_status = 'EMERGENCY CLOSED';
        WHEN current_status_val = 'maintenance' THEN 
            SET final_status = 'MAINTENANCE';
        WHEN current_status_val = 'closed' AND is_manual_override_val THEN 
            SET final_status = 'MANUALLY CLOSED';
        WHEN override_until_val IS NOT NULL AND override_until_val > NOW() THEN 
            SET final_status = 'TEMPORARY OVERRIDE';
        WHEN current_status_val = 'open' AND is_schedule_open THEN 
            SET final_status = 'OPEN';
        WHEN NOT is_schedule_open THEN 
            SET final_status = 'CLOSED (SCHEDULE)';
        ELSE 
            SET final_status = 'CLOSED';
    END CASE;
    
    RETURN final_status;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `get_safe_close_time` (`garage_id_param` VARCHAR(30)) RETURNS DATETIME DETERMINISTIC READS SQL DATA BEGIN
    DECLARE safe_close_time DATETIME DEFAULT NOW();
    
    -- Get the latest booking end time
    SELECT MAX(DATE_ADD(CONCAT(booking_date, ' ', booking_time), INTERVAL duration HOUR))
    INTO safe_close_time
    FROM bookings 
    WHERE garage_id = garage_id_param 
    AND status IN ('upcoming', 'active')
    AND CONCAT(booking_date, ' ', booking_time) > DATE_SUB(NOW(), INTERVAL 1 HOUR);
    
    -- If no conflicting bookings, can close now
    IF safe_close_time IS NULL THEN
        SET safe_close_time = NOW();
    END IF;
    
    RETURN safe_close_time;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `get_user_level_by_earned` (`total_earned` INT) RETURNS VARCHAR(10) CHARSET utf8mb4 COLLATE utf8mb4_general_ci DETERMINISTIC BEGIN
    IF total_earned >= 161 THEN
        RETURN 'diamond';
    ELSEIF total_earned >= 100 THEN
        RETURN 'gold';
    ELSEIF total_earned >= 15 THEN
        RETURN 'bronze';
    ELSE
        RETURN 'bronze'; -- Default for users with less than 15 earned
    END IF;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `is_garage_schedule_open` (`garage_id_param` VARCHAR(30), `check_time` DATETIME) RETURNS TINYINT(1) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE is_open BOOLEAN DEFAULT FALSE;
    DECLARE opening_time_val TIME;
    DECLARE closing_time_val TIME;
    DECLARE is_24_7_val BOOLEAN;
    DECLARE operating_days_val SET('monday','tuesday','wednesday','thursday','friday','saturday','sunday');
    DECLARE current_day VARCHAR(10);
    DECLARE check_time_only TIME;
    
    -- Get operating info
    SELECT opening_time, closing_time, is_24_7, operating_days 
    INTO opening_time_val, closing_time_val, is_24_7_val, operating_days_val
    FROM garage_operating_control 
    WHERE garage_id = garage_id_param;
    
    SET current_day = LOWER(DAYNAME(check_time));
    SET check_time_only = TIME(check_time);
    
    -- Check if day is in operating days
    IF FIND_IN_SET(current_day, operating_days_val) > 0 THEN
        -- If 24/7
        IF is_24_7_val THEN
            SET is_open = TRUE;
        ELSE
            -- Check time range
            IF closing_time_val < opening_time_val THEN
                -- Overnight operation (e.g., 22:00 to 06:00)
                IF check_time_only >= opening_time_val OR check_time_only <= closing_time_val THEN
                    SET is_open = TRUE;
                END IF;
            ELSE
                -- Normal day operation
                IF check_time_only >= opening_time_val AND check_time_only <= closing_time_val THEN
                    SET is_open = TRUE;
                END IF;
            END IF;
        END IF;
    END IF;
    
    RETURN is_open;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `is_scheduled_open` (`garage_id_param` VARCHAR(30), `check_datetime` DATETIME) RETURNS TINYINT(1) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE is_open BOOLEAN DEFAULT FALSE;
    DECLARE opening_time_val TIME;
    DECLARE closing_time_val TIME;
    DECLARE is_24_7_val BOOLEAN;
    DECLARE operating_days_val SET('monday','tuesday','wednesday','thursday','friday','saturday','sunday');
    DECLARE current_day VARCHAR(10);
    DECLARE check_time_only TIME;
    
    -- Get schedule data
    SELECT opening_time, closing_time, is_24_7, operating_days 
    INTO opening_time_val, closing_time_val, is_24_7_val, operating_days_val
    FROM garage_operating_schedule 
    WHERE garage_id = garage_id_param;
    
    SET current_day = LOWER(DAYNAME(check_datetime));
    SET check_time_only = TIME(check_datetime);
    
    -- Check if today is in operating days
    IF FIND_IN_SET(current_day, operating_days_val) > 0 THEN
        IF is_24_7_val THEN
            SET is_open = TRUE;
        ELSE
            -- Handle overnight schedules (e.g., 22:00 to 06:00)
            IF closing_time_val < opening_time_val THEN
                IF check_time_only >= opening_time_val OR check_time_only <= closing_time_val THEN
                    SET is_open = TRUE;
                END IF;
            ELSE
                -- Normal day schedule
                IF check_time_only >= opening_time_val AND check_time_only <= closing_time_val THEN
                    SET is_open = TRUE;
                END IF;
            END IF;
        END IF;
    END IF;
    
    RETURN is_open;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `account_information`
--

CREATE TABLE `account_information` (
  `username` varchar(50) NOT NULL,
  `password` varchar(50) NOT NULL,
  `status` enum('verified','unverified') NOT NULL DEFAULT 'unverified',
  `owner_id` varchar(100) DEFAULT NULL,
  `default_dashboard` enum('business','user') DEFAULT 'user',
  `registration_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `points` int(11) DEFAULT 0,
  `user_level` enum('bronze','gold','diamond') DEFAULT 'bronze',
  `total_earned_points` int(11) DEFAULT 0,
  `level_updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `account_information`
--

INSERT INTO `account_information` (`username`, `password`, `status`, `owner_id`, `default_dashboard`, `registration_date`, `last_login`, `points`, `user_level`, `total_earned_points`, `level_updated_at`) VALUES
('admin', 'admin123', 'verified', NULL, 'user', '2024-01-01 06:00:00', '2025-06-29 16:06:23', 0, 'bronze', 0, NULL),
('ashiq', '5555', 'verified', NULL, 'user', '2025-05-21 06:22:43', '2025-06-29 02:07:46', 65, 'bronze', 45, NULL),
('M.Abir', '23320', 'verified', NULL, 'user', '2024-02-01 06:00:00', '2025-05-29 09:13:46', 100, 'bronze', 60, NULL),
('saba', '12345', 'verified', 'U_owner_saba', 'user', '2024-03-01 06:00:00', '2025-06-29 15:42:18', 885, 'diamond', 195, '2025-06-29 02:07:00'),
('safa', '12345', 'verified', NULL, 'user', '2025-06-04 06:08:53', '2025-06-04 07:52:58', 0, 'bronze', 0, NULL),
('sami', '12345', 'verified', 'G_owner_sami', 'business', '2024-04-01 06:00:00', NULL, 0, 'bronze', 0, NULL),
('shakib', '12345', 'verified', 'U_owner_shakib', 'user', '2024-05-01 06:00:00', '2025-06-29 16:05:12', 1240, 'diamond', 270, NULL),
('SifatRahman', '12345', 'verified', 'U_owner_SifatRahman', 'user', '2024-06-01 06:00:00', NULL, 0, 'bronze', 0, NULL),
('tanvir', '12345', 'verified', 'G_owner_tanvir', 'business', '2024-07-01 06:00:00', '2025-06-29 02:06:29', 0, 'bronze', 0, NULL),
('test', '12345', 'verified', NULL, 'user', '2025-06-27 06:06:00', '2025-06-27 06:06:09', 0, 'bronze', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `garage_id` varchar(30) NOT NULL,
  `licenseplate` varchar(50) NOT NULL,
  `booking_date` date NOT NULL,
  `booking_time` time NOT NULL,
  `duration` int(11) NOT NULL,
  `status` enum('upcoming','active','completed','cancelled') NOT NULL DEFAULT 'upcoming',
  `payment_status` enum('pending','paid','refunded') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `paid_with_points` tinyint(1) DEFAULT 0,
  `points_used` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`id`, `username`, `garage_id`, `licenseplate`, `booking_date`, `booking_time`, `duration`, `status`, `payment_status`, `created_at`, `updated_at`, `paid_with_points`, `points_used`) VALUES
(3, 'saba', 'shakib_G_002', 'DHA-D-12-4545', '2025-05-02', '21:00:00', 1, 'completed', 'paid', '2025-05-02 14:09:06', '2025-05-02 17:23:10', 0, 0),
(4, 'saba', 'shakib_G_001', 'DHA-D-12-4545', '2025-05-03', '10:00:00', 1, 'completed', 'paid', '2025-05-03 03:57:21', '2025-05-03 11:25:11', 0, 0),
(5, 'M.Abir', 'shakib_G_001', 'DHA-C-12-1012', '2025-05-03', '11:00:00', 1, 'completed', 'paid', '2025-05-03 04:19:30', '2025-05-03 09:49:16', 0, 0),
(6, 'shakib', 'saba_G_001', 'gha 252525', '2025-05-03', '13:00:00', 2, 'completed', 'paid', '2025-05-03 07:31:17', '2025-05-03 09:28:48', 0, 0),
(7, 'M.Abir', 'shakib_G_002', 'DHA-C-12-1012', '2025-05-03', '16:00:00', 1, 'completed', 'paid', '2025-05-03 09:07:18', '2025-05-03 11:28:26', 0, 0),
(15, 'saba', 'sami_G_001', 'DHA-D-12-4545', '2025-05-04', '00:00:00', 1, 'completed', 'paid', '2025-05-03 17:02:09', '2025-05-04 06:34:01', 0, 0),
(32, 'shakib', 'tanvir_G_001', 'gha 252525', '2025-05-04', '11:30:00', 1, 'completed', 'paid', '2025-05-04 05:25:46', '2025-05-04 14:08:27', 0, 0),
(65, 'shakib', 'tanvir_G_001', 'gha 252525', '2025-05-04', '13:00:00', 1, 'completed', 'paid', '2025-05-04 06:31:18', '2025-05-04 14:09:44', 0, 0),
(66, 'M.Abir', 'tanvir_G_001', 'DHA-C-12-1012', '2025-05-04', '13:00:00', 1, 'completed', 'paid', '2025-05-04 06:32:01', '2025-05-04 14:11:27', 0, 0),
(68, 'shakib', 'saba_G_002', 'gha 252525', '2025-05-05', '10:00:00', 1, 'completed', 'paid', '2025-05-05 03:29:30', '2025-05-30 08:50:55', 1, 150),
(71, 'shakib', 'shakib_G_003', 'gha 252525', '2025-05-05', '13:00:00', 1, 'completed', 'paid', '2025-05-05 06:07:09', '2025-05-28 15:00:16', 0, 0),
(72, 'shakib', 'shakib_G_002', 'gha 252525', '2025-05-05', '13:00:00', 1, 'completed', 'paid', '2025-05-05 06:16:44', '2025-05-28 14:55:07', 0, 0),
(73, 'shakib', 'tanvir_G_001', 'gha 252525', '2025-05-05', '13:00:00', 1, 'completed', 'paid', '2025-05-05 06:16:55', '2025-05-28 14:47:07', 0, 0),
(88, 'shakib', 'shakib_G_003', 'gha 252525', '2025-05-06', '12:00:00', 1, 'cancelled', 'paid', '2025-05-06 05:54:02', '2025-05-28 14:46:40', 0, 0),
(89, 'shakib', 'tanvir_G_001', 'gha 252525', '2025-05-06', '13:00:00', 1, 'completed', 'paid', '2025-05-06 05:58:50', '2025-05-28 14:44:34', 0, 0),
(91, 'saba', 'saba_G_001', 'DHA-D-12-4545', '2025-05-06', '15:00:00', 1, 'completed', 'pending', '2025-05-06 07:57:01', '2025-05-06 10:03:06', 0, 0),
(95, 'shakib', 'saba_G_002', 'gha 252525', '2025-05-06', '22:00:00', 1, 'cancelled', 'refunded', '2025-05-06 15:02:43', '2025-05-18 06:20:39', 0, 0),
(96, 'shakib', 'saba_G_002', 'gha 252525', '2025-05-06', '22:00:00', 1, 'completed', 'refunded', '2025-05-06 15:35:23', '2025-05-24 06:39:53', 0, 0),
(98, 'saba', 'saba_G_002', 'DHA-D-12-4545', '2025-05-07', '17:00:00', 1, 'completed', 'pending', '2025-05-07 10:08:53', '2025-05-17 17:19:16', 0, 0),
(112, 'saba', 'shakib_G_001', 'DHA-D-12-4545', '2025-05-23', '02:00:00', 1, 'completed', 'paid', '2025-05-22 19:40:11', '2025-06-29 15:43:38', 0, 0),
(118, 'saba', 'shakib_G_002', 'DHA-D-12-4545', '2025-05-25', '17:00:00', 1, 'completed', 'paid', '2025-05-25 10:38:14', '2025-06-29 15:43:22', 0, 0),
(119, 'shakib', 'saba_G_002', 'gha 252525', '2025-05-27', '02:00:00', 1, 'completed', 'paid', '2025-05-26 19:20:47', '2025-05-27 07:41:01', 0, 0),
(120, 'shakib', 'saba_G_001', 'gha 252525', '2025-05-27', '12:00:00', 1, 'completed', 'paid', '2025-05-27 05:40:12', '2025-05-27 07:12:47', 0, 0),
(121, 'shakib', 'saba_G_001', 'gha 252525', '2025-05-28', '20:00:00', 1, 'completed', 'paid', '2025-05-28 13:51:42', '2025-05-28 15:00:01', 0, 0),
(122, 'shakib', 'tanvir_G_001', 'gha 252525', '2025-05-29', '11:00:00', 1, 'completed', 'paid', '2025-05-29 04:39:32', '2025-05-29 07:01:13', 0, 0),
(123, 'saba', 'shakib_G_003', 'DHA-D-12-4545', '2025-05-29', '11:00:00', 1, 'completed', 'refunded', '2025-05-29 04:41:06', '2025-06-27 15:33:49', 0, 0),
(124, 'shakib', 'sami_G_001', 'gha 252525', '2025-05-29', '15:05:00', 1, 'completed', 'refunded', '2025-05-29 09:01:54', '2025-06-28 17:21:14', 0, 0),
(125, 'M.Abir', 'tanvir_G_001', 'DHA-C-12-1012', '2025-05-29', '15:10:00', 1, 'completed', 'paid', '2025-05-29 09:07:02', '2025-05-29 10:10:01', 0, 0),
(126, 'shakib', 'saba_G_002', 'gha 252525', '2025-05-30', '15:00:00', 2, 'completed', 'refunded', '2025-05-30 08:57:47', '2025-06-27 14:23:54', 0, 0),
(127, 'saba', 'tanvir_G_001', 'DHA-D-12-4545', '2025-05-30', '15:00:00', 3, 'completed', 'refunded', '2025-05-30 08:58:07', '2025-06-04 05:11:48', 0, 0),
(128, 'shakib', 'tanvir_G_001', 'gha 252525', '2025-05-31', '00:05:00', 1, 'completed', 'pending', '2025-05-30 18:01:33', '2025-05-31 03:23:27', 0, 0),
(129, 'shakib', 'sami_G_001', 'gha 252525', '2025-05-31', '01:00:00', 1, 'completed', 'pending', '2025-05-30 18:12:23', '2025-05-31 03:23:27', 0, 0),
(130, 'shakib', 'saba_G_002', 'gha 252525', '2025-05-31', '03:00:00', 3, 'completed', 'refunded', '2025-05-30 18:12:54', '2025-06-04 04:21:35', 0, 0),
(131, 'saba', 'shakib_G_003', 'DHA-D-12-4545', '2025-06-28', '00:00:00', 1, 'cancelled', 'paid', '2025-06-28 17:07:54', '2025-06-29 15:42:55', 0, 0),
(132, 'saba', 'tanvir_G_002', 'DHA-D-12-4545', '2025-06-28', '11:30:00', 4, 'cancelled', 'paid', '2025-06-28 17:08:35', '2025-06-29 15:42:32', 0, 0),
(133, 'saba', 'tanvir_G_002', 'DHA-D-12-4545', '2025-06-28', '23:30:00', 4, 'completed', 'paid', '2025-06-28 17:09:04', '2025-06-29 02:07:00', 0, 0),
(134, 'shakib', 'tanvir_G_002', 'gha 252525', '2025-06-28', '23:30:00', 2, 'completed', 'paid', '2025-06-28 17:10:27', '2025-06-29 02:07:00', 0, 0),
(135, 'ashiq', 'tanvir_G_002', 'DHA-A-25-1010', '2025-06-28', '23:30:00', 3, 'completed', 'paid', '2025-06-28 17:12:27', '2025-06-29 02:07:00', 0, 0),
(136, 'shakib', 'saba_G_001', 'gha 252525', '2025-06-29', '09:00:00', 1, 'completed', 'pending', '2025-06-29 02:48:17', '2025-06-29 15:40:31', 0, 0),
(137, 'shakib', 'sami_G_001', 'gha 252525', '2025-06-29', '09:00:00', 1, 'completed', 'pending', '2025-06-29 02:48:25', '2025-06-29 15:40:31', 0, 0),
(138, 'shakib', 'saba_G_002', 'gha 252525', '2025-06-29', '09:00:00', 1, 'completed', 'pending', '2025-06-29 02:49:10', '2025-06-29 15:40:31', 0, 0),
(139, 'shakib', 'shakib_G_003', 'gha 252525', '2025-06-29', '22:00:00', 1, 'active', 'pending', '2025-06-29 16:05:26', '2025-06-29 16:05:37', 0, 0);

--
-- Triggers `bookings`
--
DELIMITER $$
CREATE TRIGGER `adjust_profit_on_refund` AFTER UPDATE ON `bookings` FOR EACH ROW BEGIN
    DECLARE refund_payment_id INT DEFAULT NULL;
    DECLARE refund_amount DECIMAL(10,2) DEFAULT 0.00;
    DECLARE owner_refund_loss DECIMAL(10,2) DEFAULT 0.00;
    DECLARE platform_refund_loss DECIMAL(10,2) DEFAULT 0.00;
    DECLARE commission_rate_val DECIMAL(5,2) DEFAULT 30.00;
    DECLARE garage_owner_username VARCHAR(50);
    DECLARE owner_id_val VARCHAR(100) DEFAULT NULL;
    DECLARE garage_id_val VARCHAR(30);
    DECLARE garage_name_val VARCHAR(255);
    
    -- Check if payment status changed to 'refunded' (and wasn't already refunded)
    IF NEW.payment_status = 'refunded' AND OLD.payment_status != 'refunded' THEN
        
        -- Get the most recent payment for this booking
        SELECT payment_id, amount 
        INTO refund_payment_id, refund_amount
        FROM payments 
        WHERE booking_id = NEW.id 
        AND payment_status = 'refunded'
        ORDER BY payment_date DESC 
        LIMIT 1;
        
        -- If we found a refunded payment, process the profit adjustment
        IF refund_payment_id IS NOT NULL AND refund_amount > 0 THEN
            
            -- Get garage owner and garage info
            SELECT g.username, g.garage_id, g.Parking_Space_Name 
            INTO garage_owner_username, garage_id_val, garage_name_val
            FROM garage_information g 
            WHERE g.garage_id = NEW.garage_id;
            
            -- Get owner_id (try garage_owners first, then dual_user)
            SELECT owner_id INTO owner_id_val 
            FROM garage_owners 
            WHERE username = garage_owner_username;
            
            IF owner_id_val IS NULL THEN
                SELECT owner_id INTO owner_id_val
                FROM dual_user 
                WHERE username = garage_owner_username;
            END IF;
            
            -- Get commission rate for this owner
            IF owner_id_val IS NOT NULL THEN
                SELECT rate INTO commission_rate_val
                FROM owner_commissions 
                WHERE owner_id = owner_id_val
                ORDER BY created_at DESC 
                LIMIT 1;
                
                -- If no commission rate found, use default 30%
                IF commission_rate_val IS NULL THEN
                    SET commission_rate_val = 30.00;
                END IF;
            END IF;
            
            -- Calculate negative profit amounts (losses due to refund)
            SET platform_refund_loss = -(refund_amount * commission_rate_val / 100);
            SET owner_refund_loss = -(refund_amount - ABS(platform_refund_loss));
            
            -- Insert negative profit tracking record for the refund
            INSERT INTO profit_tracking (
                payment_id, 
                booking_id, 
                owner_id,
                garage_id,
                garage_name,
                total_amount, 
                commission_rate, 
                owner_profit, 
                platform_profit
            ) VALUES (
                refund_payment_id,
                NEW.id,
                owner_id_val,
                garage_id_val,
                garage_name_val,
                -refund_amount,  -- Negative amount to indicate refund
                commission_rate_val,
                owner_refund_loss,   -- Negative owner profit (loss)
                platform_refund_loss -- Negative platform profit (loss)
            );
            
        END IF;
        
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `award_points_on_booking_update` AFTER UPDATE ON `bookings` FOR EACH ROW BEGIN
    DECLARE points_already_awarded INT DEFAULT 0;
    DECLARE completed_bookings_count INT DEFAULT 0;
    DECLARE milestone_bonus_due INT DEFAULT 0;
    DECLARE current_milestone_bonuses INT DEFAULT 0;
    DECLARE points_to_award INT DEFAULT 0;

    -- Check if booking is completed and paid
    IF NEW.status = 'completed' AND NEW.payment_status = 'paid' THEN
        -- Check if points already awarded for this booking
        SELECT COUNT(*) INTO points_already_awarded
        FROM points_transactions pt
        WHERE pt.booking_id = NEW.id AND pt.transaction_type = 'earned';

        -- Award base points if not already given
        IF points_already_awarded = 0 THEN
            -- Calculate base points: 15 × duration
            SET points_to_award = 15 * NEW.duration;
            
            -- Add base points to account
            UPDATE account_information
            SET points = points + points_to_award
            WHERE username = NEW.username;

            -- Record base points transaction
            INSERT INTO points_transactions (username, transaction_type, points_amount, description, booking_id)
            VALUES (NEW.username, 'earned', points_to_award, 
                   CONCAT('Points earned for completed booking #', NEW.id, ' (', NEW.duration, ' hours × 15)'), 
                   NEW.id);
            
            -- ============================================
            -- CHECK FOR MILESTONE BONUS
            -- ============================================
            
            -- Count total completed bookings for this user
            SELECT COUNT(*) INTO completed_bookings_count
            FROM points_transactions 
            WHERE username = NEW.username AND transaction_type = 'earned';
            
            -- Check if this is a milestone (10, 20, 30, etc.)
            IF completed_bookings_count % 10 = 0 THEN
                -- Get milestone bonuses already awarded
                SELECT COALESCE(SUM(points_amount), 0) INTO current_milestone_bonuses
                FROM points_transactions 
                WHERE username = NEW.username 
                AND transaction_type = 'bonus' 
                AND description LIKE '%milestone bonus%';
                
                -- Calculate total milestone bonus due
                SET milestone_bonus_due = calculate_milestone_bonus(completed_bookings_count);
                
                -- Award bonus if not already given
                IF milestone_bonus_due > current_milestone_bonuses THEN
                    -- Add 150 milestone bonus points
                    UPDATE account_information
                    SET points = points + 150
                    WHERE username = NEW.username;
                    
                    -- Record milestone bonus transaction
                    INSERT INTO points_transactions (username, transaction_type, points_amount, description, booking_id)
                    VALUES (NEW.username, 'bonus', 150, 
                           CONCAT('? Milestone bonus for ', completed_bookings_count, ' completed bookings!'), 
                           NEW.id);
                END IF;
            END IF;
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `award_refund_points_on_refund` AFTER UPDATE ON `bookings` FOR EACH ROW BEGIN
    DECLARE refund_points INT DEFAULT 0;
    DECLARE points_already_awarded INT DEFAULT 0;
    
    -- Check if payment status changed to 'refunded' (and wasn't already refunded)
    IF NEW.payment_status = 'refunded' AND OLD.payment_status != 'refunded' THEN
        
        -- Check if refund points already awarded for this booking
        SELECT COUNT(*) INTO points_already_awarded
        FROM points_transactions 
        WHERE booking_id = NEW.id AND transaction_type = 'refund';
        
        -- Award refund points only if not already given
        IF points_already_awarded = 0 THEN
            -- Calculate refund points: 150 × duration
            SET refund_points = 150 * NEW.duration;
            
            -- Add refund points to user's account
            UPDATE account_information
            SET points = points + refund_points
            WHERE username = NEW.username;
            
            -- Record the refund points transaction
            INSERT INTO points_transactions (username, transaction_type, points_amount, description, booking_id)
            VALUES (NEW.username, 'refund', refund_points, 
                   CONCAT('? Refund compensation for booking #', NEW.id, ' (', NEW.duration, ' hours)'), 
                   NEW.id);
        END IF;
        
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `dual_user`
--

CREATE TABLE `dual_user` (
  `owner_id` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `registration_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `account_status` enum('active','suspended','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dual_user`
--

INSERT INTO `dual_user` (`owner_id`, `username`, `is_verified`, `registration_date`, `last_login`, `account_status`) VALUES
('U_owner_saba', 'saba', 1, '2025-05-17 17:20:04', '2025-06-29 11:42:18', 'active'),
('U_owner_shakib', 'shakib', 1, '2025-05-17 17:20:07', '2025-06-29 12:05:12', 'active'),
('U_owner_SifatRahman', 'SifatRahman', 1, '2025-05-17 21:47:30', NULL, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `garagelocation`
--

CREATE TABLE `garagelocation` (
  `garage_id` varchar(30) NOT NULL,
  `Latitude` decimal(10,6) NOT NULL,
  `Longitude` decimal(10,6) NOT NULL,
  `username` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `garagelocation`
--

INSERT INTO `garagelocation` (`garage_id`, `Latitude`, `Longitude`, `username`) VALUES
('saba_G_001', 23.793251, 90.404040, 'saba'),
('saba_G_002', 23.790739, 90.365845, 'saba'),
('sami_G_001', 23.793643, 90.388993, 'sami'),
('shakib_G_001', 23.810083, 90.370950, 'shakib'),
('shakib_G_002', 23.798539, 90.392391, 'shakib'),
('shakib_G_003', 23.817672, 90.397732, 'shakib'),
('SifatRahman_G_001', 23.872652, 90.404391, 'SifatRahman'),
('SifatRahman_G_002', 23.796772, 90.429325, 'SifatRahman'),
('tanvir_G_001', 23.792898, 90.376000, 'tanvir'),
('tanvir_G_002', 23.781771, 90.376024, 'tanvir');

-- --------------------------------------------------------

--
-- Table structure for table `garage_information`
--

CREATE TABLE `garage_information` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `Parking_Space_Name` varchar(255) NOT NULL,
  `Parking_Lot_Address` varchar(255) NOT NULL,
  `Parking_Type` varchar(100) DEFAULT NULL,
  `Parking_Space_Dimensions` varchar(100) DEFAULT NULL,
  `Parking_Capacity` int(11) DEFAULT NULL,
  `Availability` tinyint(1) DEFAULT 1,
  `PriceperHour` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `garage_id` varchar(30) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `garage_information`
--

INSERT INTO `garage_information` (`id`, `username`, `Parking_Space_Name`, `Parking_Lot_Address`, `Parking_Type`, `Parking_Space_Dimensions`, `Parking_Capacity`, `Availability`, `PriceperHour`, `created_at`, `updated_at`, `garage_id`, `is_verified`) VALUES
(16, 'shakib', 'Shakibs Home', '551, Mirpur 10', 'Covered', 'Standard', 2, 2, 55.00, '2025-05-01 13:23:18', '2025-06-17 14:26:17', 'shakib_G_001', 1),
(17, 'shakib', 'Ashiq\'s Home', '121, Dhaka Cant', 'Open', 'Standard', 1, 1, 65.00, '2025-05-01 15:12:52', '2025-05-26 19:18:48', 'shakib_G_002', 1),
(18, 'shakib', 'Abir HOME', '445, Matikata', 'Open', 'Compact', 2, 1, 45.00, '2025-05-02 06:15:02', '2025-06-29 16:05:26', 'shakib_G_003', 1),
(20, 'saba', 'Saba\'s Garage 02', '420, Banani', 'Covered', 'Standard', 1, 1, 25.00, '2025-05-02 08:26:57', '2025-06-29 15:40:31', 'saba_G_001', 1),
(24, 'sami', 'Sami\'s House', '651, Ibahimpur , Dhaka -1206', 'Covered', 'Standard', 5, 5, 90.00, '2025-05-03 16:59:55', '2025-06-29 15:40:31', 'sami_G_001', 1),
(25, 'saba', 'Saba\'s Garage 01', '112, Nirsongo', 'Open', 'Large', 3, 3, 83.00, '2025-05-03 17:10:21', '2025-06-29 15:40:31', 'saba_G_002', 1),
(26, 'tanvir', 'Tanvir\'s Parking Zone', '850, Ibrahimpur, Dhaka', 'Covered', 'Large', 10, 10, 45.00, '2025-05-04 04:04:30', '2025-05-31 03:23:27', 'tanvir_G_001', 1),
(27, 'SifatRahman', 'Sifat\'s House', '699 , Uttra', 'Open', 'Standard', 1, 1, 50.00, '2025-05-18 09:47:30', '2025-05-23 05:50:29', 'SifatRahman_G_001', 1),
(32, 'tanvir', 'Tanvir Parking Spot 2', '22/23, Agargaon , Dhaka', 'Open', 'Large', 10, 10, 55.00, '2025-06-27 14:29:59', '2025-06-29 02:07:00', 'tanvir_G_002', 1);

-- --------------------------------------------------------

--
-- Table structure for table `garage_operating_schedule`
--

CREATE TABLE `garage_operating_schedule` (
  `garage_id` varchar(30) NOT NULL,
  `garage_name` varchar(255) DEFAULT NULL,
  `opening_time` time DEFAULT '06:00:00',
  `closing_time` time DEFAULT '22:00:00',
  `operating_days` set('monday','tuesday','wednesday','thursday','friday','saturday','sunday') DEFAULT 'monday,tuesday,wednesday,thursday,friday,saturday,sunday',
  `is_24_7` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `garage_operating_schedule`
--

INSERT INTO `garage_operating_schedule` (`garage_id`, `garage_name`, `opening_time`, `closing_time`, `operating_days`, `is_24_7`, `created_at`, `updated_at`) VALUES
('saba_G_001', 'Saba\'s Garage 02', '06:00:00', '22:00:00', 'monday,tuesday,wednesday,thursday,friday,saturday,sunday', 0, '2025-06-04 15:14:42', '2025-06-23 13:58:02'),
('saba_G_002', 'Saba\'s Garage 01', '06:00:00', '22:00:00', 'monday,tuesday,wednesday,thursday,friday,saturday,sunday', 0, '2025-06-04 15:14:42', '2025-06-23 13:58:02'),
('sami_G_001', 'Sami\'s House', '06:00:00', '22:00:00', 'monday,tuesday,wednesday,thursday,friday,saturday,sunday', 0, '2025-06-04 15:14:42', '2025-06-23 13:58:02'),
('shakib_G_001', 'Shakibs Home', '00:00:00', '23:59:00', 'monday,tuesday,wednesday,thursday,friday,saturday,sunday', 1, '2025-06-04 15:14:42', '2025-06-23 14:58:21'),
('shakib_G_002', 'Ashiq\'s Home', '09:00:00', '23:00:00', 'thursday,friday,saturday', 0, '2025-06-04 15:14:42', '2025-06-26 16:24:28'),
('shakib_G_003', 'Abir HOME', '06:00:00', '23:30:00', 'monday,tuesday,wednesday,thursday,friday,saturday,sunday', 0, '2025-06-04 15:14:42', '2025-06-26 17:03:37'),
('SifatRahman_G_001', 'Sifat\'s House', '06:00:00', '22:00:00', 'monday,tuesday,wednesday,thursday,friday,saturday,sunday', 0, '2025-06-04 15:14:42', '2025-06-23 13:58:02'),
('tanvir_G_001', 'Tanvir\'s Parking Zone', '06:00:00', '22:00:00', 'monday,tuesday,wednesday,thursday,friday,saturday,sunday', 0, '2025-06-04 15:14:42', '2025-06-23 13:58:02');

--
-- Triggers `garage_operating_schedule`
--
DELIMITER $$
CREATE TRIGGER `update_garage_name_in_schedule` BEFORE INSERT ON `garage_operating_schedule` FOR EACH ROW BEGIN
    DECLARE garage_name_val VARCHAR(255);
    
    SELECT Parking_Space_Name INTO garage_name_val
    FROM garage_information 
    WHERE garage_id = NEW.garage_id;
    
    SET NEW.garage_name = garage_name_val;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `update_garage_name_in_schedule_update` BEFORE UPDATE ON `garage_operating_schedule` FOR EACH ROW BEGIN
    DECLARE garage_name_val VARCHAR(255);
    
    -- Only update if garage_id changed or garage_name is NULL
    IF NEW.garage_id != OLD.garage_id OR NEW.garage_name IS NULL THEN
        SELECT Parking_Space_Name INTO garage_name_val
        FROM garage_information 
        WHERE garage_id = NEW.garage_id;
        
        SET NEW.garage_name = garage_name_val;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `garage_owners`
--

CREATE TABLE `garage_owners` (
  `owner_id` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `registration_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `account_status` enum('active','suspended','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `garage_owners`
--

INSERT INTO `garage_owners` (`owner_id`, `username`, `is_verified`, `registration_date`, `last_login`, `account_status`) VALUES
('G_owner_sami', 'sami', 1, '2025-05-03 13:06:54', NULL, 'active'),
('G_owner_tanvir', 'tanvir', 1, '2025-05-04 04:04:30', '2025-06-28 22:06:29', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `garage_ratings_summary`
--

CREATE TABLE `garage_ratings_summary` (
  `garage_id` varchar(30) NOT NULL,
  `garage_name` varchar(255) DEFAULT NULL,
  `total_ratings` int(11) NOT NULL DEFAULT 0,
  `average_rating` decimal(3,2) NOT NULL DEFAULT 0.00,
  `five_star` int(11) NOT NULL DEFAULT 0,
  `four_star` int(11) NOT NULL DEFAULT 0,
  `three_star` int(11) NOT NULL DEFAULT 0,
  `two_star` int(11) NOT NULL DEFAULT 0,
  `one_star` int(11) NOT NULL DEFAULT 0,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `garage_ratings_summary`
--

INSERT INTO `garage_ratings_summary` (`garage_id`, `garage_name`, `total_ratings`, `average_rating`, `five_star`, `four_star`, `three_star`, `two_star`, `one_star`, `last_updated`) VALUES
('saba_G_001', 'Saba\'s Garage 02', 3, 3.33, 0, 1, 2, 0, 0, '2025-05-31 06:13:31'),
('saba_G_002', 'Saba\'s Garage 01', 3, 4.00, 1, 1, 1, 0, 0, '2025-06-04 04:20:29'),
('sami_G_001', 'Sami\'s House', 2, 5.00, 2, 0, 0, 0, 0, '2025-05-29 10:27:39'),
('shakib_G_001', 'Shakib Home', 2, 4.50, 1, 1, 0, 0, 0, '2025-05-28 18:04:16'),
('shakib_G_002', 'Ashiq\'s Home', 2, 4.50, 1, 1, 0, 0, 0, '2025-05-28 18:04:16'),
('shakib_G_003', 'Abir HOME', 2, 3.00, 1, 0, 0, 0, 1, '2025-05-29 09:09:35'),
('tanvir_G_001', 'Tanvir\'s Parking Zone', 4, 3.25, 1, 1, 1, 0, 1, '2025-05-30 15:19:04'),
('tanvir_G_002', 'Tanvir Parking Spot 2', 2, 3.50, 0, 1, 1, 0, 0, '2025-06-29 02:07:53');

-- --------------------------------------------------------

--
-- Table structure for table `garage_real_time_status`
--

CREATE TABLE `garage_real_time_status` (
  `garage_id` varchar(30) NOT NULL,
  `current_status` enum('open','closed','maintenance','emergency_closed') DEFAULT 'open',
  `is_manual_override` tinyint(1) DEFAULT 0,
  `override_until` datetime DEFAULT NULL,
  `override_reason` varchar(255) DEFAULT NULL,
  `force_closed` tinyint(1) DEFAULT 0,
  `active_bookings_count` int(11) DEFAULT 0,
  `can_close_after` datetime DEFAULT NULL,
  `last_changed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `changed_by` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `garage_real_time_status`
--

INSERT INTO `garage_real_time_status` (`garage_id`, `current_status`, `is_manual_override`, `override_until`, `override_reason`, `force_closed`, `active_bookings_count`, `can_close_after`, `last_changed_at`, `changed_by`) VALUES
('saba_G_001', 'open', 0, NULL, NULL, 0, 0, NULL, '2025-06-04 15:14:42', 'saba'),
('saba_G_002', 'open', 0, NULL, NULL, 0, 0, NULL, '2025-06-04 15:14:42', 'saba'),
('sami_G_001', 'open', 0, NULL, NULL, 0, 0, NULL, '2025-06-04 15:14:42', 'sami'),
('shakib_G_001', 'open', 1, NULL, 'Quick open action by admin', 0, 0, '2025-06-29 21:17:49', '2025-06-29 15:17:49', 'admin'),
('shakib_G_002', 'open', 0, NULL, 'Schedule update', 0, 0, '2025-06-26 22:24:28', '2025-06-26 16:24:28', 'shakib'),
('shakib_G_003', 'open', 0, NULL, 'Schedule update', 0, 0, '2025-06-26 23:03:37', '2025-06-26 17:03:37', 'shakib'),
('SifatRahman_G_001', 'open', 0, NULL, NULL, 0, 0, NULL, '2025-06-04 15:14:42', 'SifatRahman'),
('tanvir_G_001', 'open', 0, NULL, NULL, 0, 0, NULL, '2025-06-04 15:14:42', 'tanvir'),
('tanvir_G_002', 'open', 0, NULL, NULL, 0, 0, NULL, '2025-06-27 14:30:28', 'system');

--
-- Triggers `garage_real_time_status`
--
DELIMITER $$
CREATE TRIGGER `log_status_changes` AFTER UPDATE ON `garage_real_time_status` FOR EACH ROW BEGIN
    -- Only log if status actually changed
    IF NEW.current_status != OLD.current_status THEN
        -- Insert into a log table (we'll create this)
        INSERT INTO garage_status_log (garage_id, old_status, new_status, changed_by, change_reason, changed_at)
        VALUES (NEW.garage_id, OLD.current_status, NEW.current_status, NEW.changed_by, 
                COALESCE(NEW.override_reason, 'Manual change'), NOW());
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `update_booking_protection_on_status_change` BEFORE UPDATE ON `garage_real_time_status` FOR EACH ROW BEGIN
    DECLARE active_count INT DEFAULT 0;
    DECLARE safe_close DATETIME DEFAULT NULL;
    
    -- Count current active bookings
    SELECT COUNT(*) INTO active_count
    FROM bookings b
    WHERE b.garage_id = NEW.garage_id 
    AND b.status IN ('upcoming', 'active')
    AND CONCAT(b.booking_date, ' ', b.booking_time) <= DATE_ADD(NOW(), INTERVAL 30 MINUTE)
    AND DATE_ADD(CONCAT(b.booking_date, ' ', b.booking_time), INTERVAL b.duration HOUR) > NOW();
    
    -- Calculate safe close time
    SET safe_close = get_safe_close_time(NEW.garage_id);
    
    -- Update calculated fields
    SET NEW.active_bookings_count = active_count;
    SET NEW.can_close_after = safe_close;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `validate_garage_closure` BEFORE UPDATE ON `garage_real_time_status` FOR EACH ROW BEGIN
    DECLARE can_close BOOLEAN DEFAULT TRUE;
    DECLARE error_message VARCHAR(255);
    
    -- Only validate if trying to close the garage
    IF NEW.current_status IN ('closed', 'maintenance') AND OLD.current_status = 'open' THEN
        -- Check if garage can be closed
        SET can_close = can_garage_close_now(NEW.garage_id);
        
        -- If cannot close and not force closing
        IF NOT can_close AND NOT NEW.force_closed THEN
            SET error_message = CONCAT('Cannot close garage ', NEW.garage_id, ' - active bookings exist. Use force_closed = TRUE to override.');
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = error_message;
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `garage_status_log`
--

CREATE TABLE `garage_status_log` (
  `id` int(11) NOT NULL,
  `garage_id` varchar(30) NOT NULL,
  `old_status` enum('open','closed','maintenance','emergency_closed') DEFAULT NULL,
  `new_status` enum('open','closed','maintenance','emergency_closed') DEFAULT NULL,
  `changed_by` varchar(50) DEFAULT NULL,
  `change_reason` varchar(255) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `garage_status_log`
--

INSERT INTO `garage_status_log` (`id`, `garage_id`, `old_status`, `new_status`, `changed_by`, `change_reason`, `changed_at`) VALUES
(1, 'shakib_G_001', 'open', 'closed', 'shakib', 'Manual control', '2025-06-23 15:07:11'),
(2, 'shakib_G_001', 'closed', 'maintenance', 'shakib', 'Manual control', '2025-06-23 15:07:54'),
(3, 'shakib_G_001', 'maintenance', 'open', 'shakib', 'Manual change', '2025-06-23 15:08:15'),
(4, 'shakib_G_001', 'open', 'closed', 'shakib', 'Manual control', '2025-06-23 15:27:43'),
(5, 'shakib_G_001', 'closed', 'open', 'shakib', 'Manual change', '2025-06-23 16:22:25'),
(6, 'shakib_G_001', 'open', 'closed', 'shakib', 'Manual control', '2025-06-23 17:09:35'),
(7, 'shakib_G_001', 'closed', 'open', 'shakib', 'Manual change', '2025-06-25 16:21:17'),
(8, 'shakib_G_001', 'open', 'closed', 'shakib', 'Manual control', '2025-06-26 10:57:37'),
(9, 'shakib_G_002', 'open', 'closed', 'shakib', 'Manual control', '2025-06-26 10:58:02'),
(10, 'shakib_G_002', 'closed', 'open', 'admin', 'Quick open action by admin', '2025-06-26 11:02:27'),
(11, 'shakib_G_002', 'open', 'closed', 'shakib', 'Manual control', '2025-06-26 12:26:29'),
(12, 'shakib_G_003', 'open', 'closed', 'shakib', 'Manual control', '2025-06-26 12:26:38'),
(13, 'shakib_G_001', 'closed', 'open', 'shakib', 'Manual change', '2025-06-26 12:37:39'),
(14, 'shakib_G_002', 'closed', 'open', 'shakib', 'Manual change', '2025-06-26 12:38:00'),
(15, 'shakib_G_003', 'closed', 'open', 'shakib', 'Manual change', '2025-06-26 12:38:04'),
(16, 'shakib_G_001', 'open', 'closed', 'shakib', 'Manual control', '2025-06-26 12:44:28'),
(17, 'shakib_G_001', 'closed', 'open', 'shakib', 'Manual change', '2025-06-26 12:49:07'),
(18, 'shakib_G_001', 'open', 'closed', 'shakib', 'Manual control', '2025-06-26 15:38:17'),
(19, 'shakib_G_003', 'open', 'closed', 'shakib', 'Manual control', '2025-06-26 15:38:42'),
(20, 'shakib_G_001', 'closed', 'open', 'shakib', 'Manual change', '2025-06-26 15:39:19'),
(21, 'shakib_G_003', 'closed', 'open', 'shakib', 'Manual change', '2025-06-26 15:39:34'),
(22, 'shakib_G_001', 'open', 'closed', 'shakib', 'Manual control', '2025-06-26 15:41:11'),
(23, 'shakib_G_001', 'closed', 'open', 'shakib', 'Manual change', '2025-06-26 15:53:03'),
(24, 'shakib_G_001', 'open', 'closed', 'shakib', 'Manual control', '2025-06-26 15:53:08'),
(25, 'shakib_G_001', 'closed', 'open', 'shakib', 'Manual change', '2025-06-26 15:53:28'),
(26, 'shakib_G_001', 'open', 'closed', 'admin', 'Quick closed action by admin', '2025-06-27 13:43:40'),
(27, 'shakib_G_001', 'closed', 'open', 'admin', 'Quick open action by admin', '2025-06-28 14:28:14'),
(28, 'shakib_G_001', 'open', 'closed', 'admin', 'Quick closed action by admin', '2025-06-28 17:01:45'),
(29, 'shakib_G_001', 'closed', 'open', 'admin', 'Quick open action by admin', '2025-06-29 15:17:49');

-- --------------------------------------------------------

--
-- Table structure for table `owner_commissions`
--

CREATE TABLE `owner_commissions` (
  `id` int(11) NOT NULL,
  `owner_id` varchar(100) NOT NULL,
  `owner_type` enum('garage','dual') NOT NULL DEFAULT 'garage',
  `rate` decimal(5,2) NOT NULL DEFAULT 10.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `owner_commissions`
--

INSERT INTO `owner_commissions` (`id`, `owner_id`, `owner_type`, `rate`, `created_at`, `updated_at`) VALUES
(1, 'G_owner_tanvir', 'garage', 30.00, '2025-05-18 04:40:31', '2025-05-20 06:40:57'),
(2, 'U_owner_saba', 'dual', 30.00, '2025-05-18 07:27:35', '2025-05-20 06:40:57'),
(3, 'G_owner_sami', 'garage', 30.00, '2025-05-18 07:27:35', '2025-05-20 06:40:57'),
(4, 'U_owner_shakib', 'dual', 30.00, '2025-05-18 07:27:35', '2025-05-20 06:40:57'),
(5, 'U_owner_SifatRahman', 'dual', 30.00, '2025-05-20 06:40:57', '2025-05-24 03:57:47'),
(6, 'U_owner_ashiq', 'dual', 30.00, '2025-05-21 12:41:21', '2025-05-21 12:41:21');

--
-- Triggers `owner_commissions`
--
DELIMITER $$
CREATE TRIGGER `before_insert_owner_commission` BEFORE INSERT ON `owner_commissions` FOR EACH ROW BEGIN
    IF NEW.owner_type = 'garage' THEN
        IF NOT EXISTS (SELECT 1 FROM garage_owners WHERE owner_id = NEW.owner_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referenced garage owner does not exist';
        END IF;
    ELSEIF NEW.owner_type = 'dual' THEN
        IF NOT EXISTS (SELECT 1 FROM dual_user WHERE owner_id = NEW.owner_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referenced dual user does not exist';
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `before_update_owner_commission` BEFORE UPDATE ON `owner_commissions` FOR EACH ROW BEGIN
    IF NEW.owner_type = 'garage' THEN
        IF NOT EXISTS (SELECT 1 FROM garage_owners WHERE owner_id = NEW.owner_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referenced garage owner does not exist';
        END IF;
    ELSEIF NEW.owner_type = 'dual' THEN
        IF NOT EXISTS (SELECT 1 FROM dual_user WHERE owner_id = NEW.owner_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referenced dual user does not exist';
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `transaction_id` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(20) NOT NULL,
  `payment_status` enum('pending','paid','refunded') NOT NULL DEFAULT 'pending',
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `points_used` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `booking_id`, `transaction_id`, `amount`, `payment_method`, `payment_status`, `payment_date`, `points_used`) VALUES
(1, 3, 'TRX202505021923109648', 75.00, 'bkash', 'paid', '2025-05-02 17:23:10', 0),
(4, 3, 'TRX202505031011599115', 75.00, 'bkash', 'paid', '2025-05-03 08:11:59', 0),
(5, 3, 'TRX202505031021448816', 75.00, 'nagad', 'paid', '2025-05-03 08:21:44', 0),
(6, 6, 'TRX202505031128486903', 60.00, 'nagad', 'paid', '2025-05-03 09:28:48', 0),
(7, 6, 'TRX202505031130505365', 60.00, 'nagad', 'paid', '2025-05-03 09:30:50', 0),
(8, 6, 'TRX202505031134163441', 60.00, 'nagad', 'paid', '2025-05-03 09:34:16', 0),
(9, 5, 'TRX202505031149164834', 45.00, 'nagad', 'paid', '2025-05-03 09:49:16', 0),
(10, 7, 'TRX202505031153558547', 75.00, 'bkash', 'paid', '2025-05-03 09:53:55', 0),
(11, 4, 'TRX202505031325119073', 45.00, 'nagad', 'paid', '2025-05-03 11:25:11', 0),
(15, 15, 'TRX202505040834015012', 100.00, 'nagad', 'paid', '2025-05-04 06:34:01', 0),
(18, 32, 'TRX202505041608275116', 55.00, 'bkash', 'paid', '2025-05-04 14:08:27', 0),
(20, 65, 'TRX202505041609446452', 55.00, 'nagad', 'paid', '2025-05-04 14:09:44', 0),
(22, 66, 'TRX202505041611276332', 55.00, 'nagad', 'paid', '2025-05-04 14:11:27', 0),
(26, 96, 'TRX202505171947279917', 93.00, 'nagad', 'refunded', '2025-05-17 17:47:27', 0),
(27, 95, 'TRX202505171947507035', 93.00, 'bkash', 'refunded', '2025-05-17 17:47:50', 0),
(29, 120, 'TRX202505270912475891', 32.50, 'bkash', 'paid', '2025-05-27 07:12:47', 0),
(30, 119, 'TRX202505270941015724', 83.00, 'bkash', 'paid', '2025-05-27 07:41:01', 0),
(31, 121, 'TRX202505281638046096', 25.00, 'bkash', 'paid', '2025-05-28 14:38:04', 0),
(32, 89, 'TRX202505281644341571', 45.00, 'bkash', 'paid', '2025-05-28 14:44:34', 0),
(33, 88, 'TRX202505281646405060', 45.00, 'bkash', 'paid', '2025-05-28 14:46:40', 0),
(34, 73, 'TRX202505281647072055', 45.00, 'bkash', 'paid', '2025-05-28 14:47:07', 0),
(35, 72, 'TRX202505281655078182', 65.00, 'bkash', 'paid', '2025-05-28 14:55:07', 0),
(36, 71, 'TRX202505281700169692', 45.00, 'bkash', 'paid', '2025-05-28 15:00:16', 0),
(37, 122, 'TRX202505290901139394', 45.00, 'bkash', 'paid', '2025-05-29 07:01:13', 0),
(38, 123, 'TRX202505291109138399', 45.00, 'bkash', 'refunded', '2025-05-29 09:09:13', 0),
(39, 125, 'TRX202505291148273320', 45.00, 'bkash', 'paid', '2025-05-29 09:48:27', 0),
(40, 124, 'TRX202505291227279594', 90.00, 'bkash', 'refunded', '2025-05-29 10:27:27', 0),
(41, 68, 'PTS_202505301419242381', 0.00, 'points', 'paid', '2025-05-30 08:19:24', 150),
(42, 126, 'TRX202505301301318145', 166.00, 'nagad', 'refunded', '2025-05-30 11:01:31', 0),
(43, 127, 'TRX202505301718547964', 135.00, 'nagad', 'refunded', '2025-05-30 15:18:54', 0),
(44, 130, 'TRX202506040620151824', 249.00, 'bkash', 'refunded', '2025-06-04 04:20:15', 0),
(45, 133, 'TRX202506281913381946', 220.00, 'bkash', 'paid', '2025-06-28 17:13:38', 0),
(46, 134, 'TRX202506281915445085', 110.00, 'nagad', 'paid', '2025-06-28 17:15:44', 0),
(47, 135, 'TRX202506281916326946', 165.00, 'nagad', 'paid', '2025-06-28 17:16:32', 0),
(48, 132, 'TRX202506291742328568', 220.00, 'bkash', 'paid', '2025-06-29 15:42:32', 0),
(49, 131, 'TRX202506291742552717', 45.00, 'bkash', 'paid', '2025-06-29 15:42:55', 0),
(50, 118, 'TRX202506291743222787', 65.00, 'nagad', 'paid', '2025-06-29 15:43:22', 0),
(51, 112, 'TRX202506291743386354', 55.00, 'bkash', 'paid', '2025-06-29 15:43:38', 0);

--
-- Triggers `payments`
--
DELIMITER $$
CREATE TRIGGER `calculate_profit_after_payment` AFTER INSERT ON `payments` FOR EACH ROW BEGIN
    DECLARE garage_owner_username VARCHAR(50);
    DECLARE owner_id_val VARCHAR(100) DEFAULT NULL;
    DECLARE commission_rate_val DECIMAL(5,2) DEFAULT 30.00;
    DECLARE owner_profit_val DECIMAL(10,2);
    DECLARE platform_profit_val DECIMAL(10,2);
    DECLARE garage_id_val VARCHAR(30);
    DECLARE garage_name_val VARCHAR(255);
    
    -- Get garage owner username, garage_id and garage_name from booking
    SELECT g.username, g.garage_id, g.Parking_Space_Name 
    INTO garage_owner_username, garage_id_val, garage_name_val
    FROM bookings b 
    INNER JOIN garage_information g ON b.garage_id = g.garage_id 
    WHERE b.id = NEW.booking_id;
    
    -- Try to get owner_id from garage_owners table first
    SELECT owner_id INTO owner_id_val 
    FROM garage_owners 
    WHERE username = garage_owner_username;
    
    -- If not found in garage_owners, try dual_user table
    IF owner_id_val IS NULL THEN
        SELECT owner_id INTO owner_id_val
        FROM dual_user 
        WHERE username = garage_owner_username;
    END IF;
    
    -- If we found an owner_id, get their commission rate
    IF owner_id_val IS NOT NULL THEN
        -- Get commission rate for this owner
        SELECT rate INTO commission_rate_val
        FROM owner_commissions 
        WHERE owner_id = owner_id_val
        ORDER BY created_at DESC 
        LIMIT 1;
        
        -- If no commission rate found, use default 30%
        IF commission_rate_val IS NULL THEN
            SET commission_rate_val = 30.00;
        END IF;
        
        -- Calculate commission and profit (commission_rate is platform's percentage)
        SET platform_profit_val = (NEW.amount * commission_rate_val / 100);
        SET owner_profit_val = (NEW.amount - platform_profit_val);
        
        -- Insert profit tracking record with garage information
        INSERT INTO profit_tracking (
            payment_id, 
            booking_id, 
            owner_id,
            garage_id,
            garage_name,
            total_amount, 
            commission_rate, 
            owner_profit, 
            platform_profit
        ) VALUES (
            NEW.payment_id,
            NEW.booking_id,
            owner_id_val,
            garage_id_val,
            garage_name_val,
            NEW.amount,
            commission_rate_val,
            owner_profit_val,
            platform_profit_val
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `personal_information`
--

CREATE TABLE `personal_information` (
  `firstName` varchar(50) NOT NULL,
  `lastName` varchar(50) NOT NULL,
  `email` varchar(50) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `address` varchar(50) NOT NULL,
  `username` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `personal_information`
--

INSERT INTO `personal_information` (`firstName`, `lastName`, `email`, `phone`, `address`, `username`) VALUES
('Ashiq', 'Khan', 'ashiq@gmail.com', '01874126157', '222, Dhaka Cantonment', 'ashiq'),
('Mohammad ', 'Abir', 'M.abir@233gmail.com', '+880 1572-912140', 'Mothijeel', 'M.Abir'),
('Shakib', 'Rahman', 'nayemshakib2018@gmail.com', '01874126156', 'Mirpur 14', 'shakib'),
('ms', 'saba', 'saba@gmail.com', '01874126157', 'Banasree, Dhaka', 'saba'),
('Safa', 'Rahman', 'safa@gmail.com', '01874126156', '651, Sajer Maya, Ibrahimpur ,Dhaka', 'safa'),
('S.M Mahmudur Rahman', 'Sifat', 'sifatrahman@gmail.com', '+8801705845665', 'Uttara,Dhaka', 'SifatRahman'),
('Tanvir', 'Rahman', 'tanvirrahmanaz@gmail.com', '018741212121', '850, Ibrahimpur, Dhaka', 'tanvir'),
('Tasve', 'Samir', 'tasve@yahoo.com', '01874126145', '651, Sajer Maya, Ibrahimpur ,Dhaka', 'sami'),
('test', 'test', 'test@gmail.com', '1874126156', '651, Sajer Maya, Ibrahimpur ,Dhaka 1206', 'test');

-- --------------------------------------------------------

--
-- Table structure for table `points_transactions`
--

CREATE TABLE `points_transactions` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `transaction_type` enum('earned','spent','bonus','refund') NOT NULL,
  `points_amount` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `points_transactions`
--

INSERT INTO `points_transactions` (`id`, `username`, `transaction_type`, `points_amount`, `description`, `booking_id`, `created_at`) VALUES
(1, 'saba', 'earned', 15, 'Points earned for completed booking #3', 3, '2025-05-02 17:23:10'),
(2, 'saba', 'earned', 15, 'Points earned for completed booking #4', 4, '2025-05-03 11:25:11'),
(3, 'M.Abir', 'earned', 15, 'Points earned for completed booking #5', 5, '2025-05-03 09:49:16'),
(4, 'shakib', 'earned', 15, 'Points earned for completed booking #6', 6, '2025-05-03 09:28:48'),
(5, 'M.Abir', 'earned', 15, 'Points earned for completed booking #7', 7, '2025-05-03 11:28:26'),
(6, 'saba', 'earned', 15, 'Points earned for completed booking #15', 15, '2025-05-04 06:34:01'),
(7, 'shakib', 'earned', 15, 'Points earned for completed booking #32', 32, '2025-05-04 14:08:27'),
(8, 'shakib', 'earned', 15, 'Points earned for completed booking #65', 65, '2025-05-04 14:09:44'),
(9, 'M.Abir', 'earned', 15, 'Points earned for completed booking #66', 66, '2025-05-04 14:11:27'),
(10, 'shakib', 'earned', 15, 'Points earned for completed booking #71', 71, '2025-05-28 15:00:16'),
(11, 'shakib', 'earned', 15, 'Points earned for completed booking #72', 72, '2025-05-28 14:55:07'),
(12, 'shakib', 'earned', 15, 'Points earned for completed booking #73', 73, '2025-05-28 14:47:07'),
(13, 'shakib', 'earned', 15, 'Points earned for completed booking #89', 89, '2025-05-28 14:44:34'),
(14, 'shakib', 'earned', 15, 'Points earned for completed booking #119', 119, '2025-05-27 07:41:01'),
(15, 'shakib', 'earned', 15, 'Points earned for completed booking #120', 120, '2025-05-27 07:12:47'),
(16, 'shakib', 'earned', 15, 'Points earned for completed booking #121', 121, '2025-05-28 15:00:01'),
(17, 'shakib', 'earned', 15, 'Points earned for completed booking (manual fix)', 122, '2025-05-29 01:01:13'),
(18, 'saba', 'earned', 15, 'Points earned for completed booking #123', 123, '2025-05-29 09:09:13'),
(19, 'M.Abir', 'earned', 15, 'Points earned for completed booking #125', 125, '2025-05-29 10:10:01'),
(20, 'shakib', 'earned', 15, 'Points earned for completed booking #124', 124, '2025-05-29 10:27:27'),
(21, 'shakib', 'spent', 150, 'Points payment for booking #68 - 1 hour parking', 68, '2025-05-30 08:19:24'),
(23, 'shakib', 'bonus', 70, 'Points adjustment by admin: Admin bonus', NULL, '2025-05-30 09:21:46'),
(24, 'shakib', 'bonus', 70, 'Points adjustment by admin: Admin bonus', NULL, '2025-05-30 09:22:01'),
(25, 'shakib', 'spent', 70, 'Points adjustment by admin: System adjustment', NULL, '2025-05-30 09:28:41'),
(26, 'saba', 'bonus', 40, 'Points adjustment by admin: System adjustment', NULL, '2025-05-30 09:30:19'),
(27, 'M.Abir', 'bonus', 40, 'Points adjustment by admin: Admin bonus', NULL, '2025-05-30 09:35:03'),
(28, 'ashiq', 'bonus', 20, 'Admin adjustment by admin: Admin bonus', NULL, '2025-05-30 09:36:00'),
(29, 'shakib', 'earned', 15, 'Points earned for completed booking #126', 126, '2025-05-30 11:01:31'),
(30, 'saba', 'earned', 45, 'Points earned for completed booking #127 (3 hours - 45 points)', 127, '2025-05-30 15:18:54'),
(31, 'shakib', 'bonus', 150, '🎉 Retroactive milestone bonus - 13 bookings completed', NULL, '2025-05-30 15:29:01'),
(32, 'saba', 'bonus', 50, 'Admin adjustment by admin: Admin bonus', NULL, '2025-05-31 06:17:35'),
(33, 'shakib', 'earned', 45, 'Points earned for completed booking #130 (3 hours × 15)', 130, '2025-06-04 04:20:15'),
(34, 'shakib', 'refund', 450, '💰 Refund compensation for booking #130 (3 hours)', 130, '2025-06-04 04:21:35'),
(35, 'saba', 'refund', 450, '💰 Refund compensation for booking #127 (3 hours)', 127, '2025-06-04 05:11:48'),
(36, 'safa', 'bonus', 100, 'Admin adjustment by admin: Promotion reward', NULL, '2025-06-27 05:22:19'),
(37, 'safa', 'spent', 100, 'Admin adjustment by admin: System adjustment', NULL, '2025-06-27 05:24:48'),
(38, 'shakib', 'refund', 300, '💰 Refund compensation for booking #126 (2 hours)', 126, '2025-06-27 14:23:54'),
(39, 'saba', 'refund', 150, '💰 Refund compensation for booking #123 (1 hours)', 123, '2025-06-27 15:33:49'),
(40, 'shakib', 'refund', 150, '💰 Refund compensation for booking #124 (1 hours)', 124, '2025-06-28 17:21:14'),
(41, 'saba', 'earned', 60, 'Points earned for completed booking #133 (4 hours × 15)', 133, '2025-06-29 02:07:00'),
(42, 'shakib', 'earned', 30, 'Points earned for completed booking #134 (2 hours × 15)', 134, '2025-06-29 02:07:00'),
(43, 'ashiq', 'earned', 45, 'Points earned for completed booking #135 (3 hours × 15)', 135, '2025-06-29 02:07:00'),
(44, 'saba', 'earned', 15, 'Points earned for completed booking #118 (1 hours × 15)', 118, '2025-06-29 15:43:22'),
(45, 'saba', 'earned', 15, 'Points earned for completed booking #112 (1 hours × 15)', 112, '2025-06-29 15:43:38');

--
-- Triggers `points_transactions`
--
DELIMITER $$
CREATE TRIGGER `update_user_level_on_earn` AFTER INSERT ON `points_transactions` FOR EACH ROW BEGIN
    DECLARE total_earned INT DEFAULT 0;
    DECLARE new_level VARCHAR(10);
    DECLARE current_level VARCHAR(10);
    
    -- Only process if this is an 'earned' transaction
    IF NEW.transaction_type = 'earned' THEN
        -- Calculate total earned points for this user
        SELECT COALESCE(SUM(points_amount), 0) INTO total_earned
        FROM points_transactions 
        WHERE username = NEW.username AND transaction_type = 'earned';
        
        -- Get current level
        SELECT user_level INTO current_level
        FROM account_information 
        WHERE username = NEW.username;
        
        -- Determine new level based on total earned
        SET new_level = get_user_level_by_earned(total_earned);
        
        -- Update user's total earned points and level if changed
        UPDATE account_information 
        SET 
            total_earned_points = total_earned,
            user_level = new_level,
            level_updated_at = CASE 
                WHEN new_level != current_level THEN NOW() 
                ELSE level_updated_at 
            END
        WHERE username = NEW.username;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `profit_tracking`
--

CREATE TABLE `profit_tracking` (
  `id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `owner_id` varchar(100) NOT NULL,
  `garage_id` varchar(30) DEFAULT NULL,
  `garage_name` varchar(255) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `commission_rate` decimal(5,2) NOT NULL,
  `owner_profit` decimal(10,2) NOT NULL,
  `platform_profit` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `profit_tracking`
--

INSERT INTO `profit_tracking` (`id`, `payment_id`, `booking_id`, `owner_id`, `garage_id`, `garage_name`, `total_amount`, `commission_rate`, `owner_profit`, `platform_profit`, `created_at`) VALUES
(1, 11, 4, 'U_owner_shakib', 'shakib_G_001', 'Shakib Home', 45.00, 30.00, 31.50, 13.50, '2025-05-27 06:47:50'),
(2, 9, 5, 'U_owner_shakib', 'shakib_G_001', 'Shakib Home', 45.00, 30.00, 31.50, 13.50, '2025-05-27 06:47:50'),
(3, 1, 3, 'U_owner_shakib', 'shakib_G_002', 'Ashiq\'s Home', 75.00, 30.00, 52.50, 22.50, '2025-05-27 06:47:50'),
(4, 4, 3, 'U_owner_shakib', 'shakib_G_002', 'Ashiq\'s Home', 75.00, 30.00, 52.50, 22.50, '2025-05-27 06:47:50'),
(5, 5, 3, 'U_owner_shakib', 'shakib_G_002', 'Ashiq\'s Home', 75.00, 30.00, 52.50, 22.50, '2025-05-27 06:47:50'),
(6, 10, 7, 'U_owner_shakib', 'shakib_G_002', 'Ashiq\'s Home', 75.00, 30.00, 52.50, 22.50, '2025-05-27 06:47:50'),
(7, 6, 6, 'U_owner_saba', 'saba_G_001', 'Saba\'s Garage 02', 60.00, 30.00, 42.00, 18.00, '2025-05-27 06:47:50'),
(8, 7, 6, 'U_owner_saba', 'saba_G_001', 'Saba\'s Garage 02', 60.00, 30.00, 42.00, 18.00, '2025-05-27 06:47:50'),
(9, 8, 6, 'U_owner_saba', 'saba_G_001', 'Saba\'s Garage 02', 60.00, 30.00, 42.00, 18.00, '2025-05-27 06:47:50'),
(10, 15, 15, 'G_owner_sami', 'sami_G_001', 'Sami\'s House', 100.00, 30.00, 70.00, 30.00, '2025-05-27 06:47:50'),
(11, 18, 32, 'G_owner_tanvir', 'tanvir_G_001', 'Tanvir\'s Parking Zone', 55.00, 30.00, 38.50, 16.50, '2025-05-27 06:47:50'),
(12, 20, 65, 'G_owner_tanvir', 'tanvir_G_001', 'Tanvir\'s Parking Zone', 55.00, 30.00, 38.50, 16.50, '2025-05-27 06:47:50'),
(13, 22, 66, 'G_owner_tanvir', 'tanvir_G_001', 'Tanvir\'s Parking Zone', 55.00, 30.00, 38.50, 16.50, '2025-05-27 06:47:50'),
(16, 29, 120, 'U_owner_saba', 'saba_G_001', 'Saba\'s Garage 02', 32.50, 30.00, 22.75, 9.75, '2025-05-27 07:12:47'),
(17, 30, 119, 'U_owner_saba', 'saba_G_002', 'Saba\'s Garage 01', 83.00, 30.00, 58.10, 24.90, '2025-05-27 07:41:01'),
(18, 31, 121, 'U_owner_saba', 'saba_G_001', 'Saba\'s Garage 02', 25.00, 30.00, 17.50, 7.50, '2025-05-28 14:38:04'),
(19, 32, 89, 'G_owner_tanvir', 'tanvir_G_001', 'Tanvir\'s Parking Zone', 45.00, 30.00, 31.50, 13.50, '2025-05-28 14:44:34'),
(20, 33, 88, 'U_owner_shakib', 'shakib_G_003', 'Abir HOME', 45.00, 30.00, 31.50, 13.50, '2025-05-28 14:46:40'),
(21, 34, 73, 'G_owner_tanvir', 'tanvir_G_001', 'Tanvir\'s Parking Zone', 45.00, 30.00, 31.50, 13.50, '2025-05-28 14:47:07'),
(22, 35, 72, 'U_owner_shakib', 'shakib_G_002', 'Ashiq\'s Home', 65.00, 30.00, 45.50, 19.50, '2025-05-28 14:55:07'),
(23, 36, 71, 'U_owner_shakib', 'shakib_G_003', 'Abir HOME', 45.00, 30.00, 31.50, 13.50, '2025-05-28 15:00:16'),
(24, 37, 122, 'G_owner_tanvir', 'tanvir_G_001', 'Tanvir\'s Parking Zone', 45.00, 30.00, 31.50, 13.50, '2025-05-29 07:01:13'),
(25, 38, 123, 'U_owner_shakib', 'shakib_G_003', 'Abir HOME', 45.00, 30.00, 31.50, 13.50, '2025-05-29 09:09:13'),
(26, 39, 125, 'G_owner_tanvir', 'tanvir_G_001', 'Tanvir\'s Parking Zone', 45.00, 30.00, 31.50, 13.50, '2025-05-29 09:48:27'),
(27, 40, 124, 'G_owner_sami', 'sami_G_001', 'Sami\'s House', 90.00, 30.00, 63.00, 27.00, '2025-05-29 10:27:27'),
(28, 41, 68, 'U_owner_saba', 'saba_G_002', 'Saba\'s Garage 01', 0.00, 30.00, 0.00, 0.00, '2025-05-30 08:19:24'),
(29, 42, 126, 'U_owner_saba', 'saba_G_002', 'Saba\'s Garage 01', 166.00, 30.00, 116.20, 49.80, '2025-05-30 11:01:31'),
(30, 43, 127, 'G_owner_tanvir', 'tanvir_G_001', 'Tanvir\'s Parking Zone', 135.00, 30.00, 94.50, 40.50, '2025-05-30 15:18:54'),
(31, 44, 130, 'U_owner_saba', 'saba_G_002', 'Saba\'s Garage 01', 249.00, 30.00, 174.30, 74.70, '2025-06-04 04:20:15'),
(32, 44, 130, 'U_owner_saba', 'saba_G_002', 'Saba\'s Garage 01', -249.00, 30.00, -174.30, -74.70, '2025-06-04 05:11:18'),
(33, 43, 127, 'G_owner_tanvir', 'tanvir_G_001', 'Tanvir\'s Parking Zone', -135.00, 30.00, -94.50, -40.50, '2025-06-04 05:11:48'),
(34, 42, 126, 'U_owner_saba', 'saba_G_002', 'Saba\'s Garage 01', -166.00, 30.00, -116.20, -49.80, '2025-06-27 14:23:54'),
(35, 38, 123, 'U_owner_shakib', 'shakib_G_003', 'Abir HOME', -45.00, 30.00, -31.50, -13.50, '2025-06-27 15:33:49'),
(36, 45, 133, 'G_owner_tanvir', 'tanvir_G_002', 'Tanvir Parking Spot 2', 220.00, 30.00, 154.00, 66.00, '2025-06-28 17:13:38'),
(37, 46, 134, 'G_owner_tanvir', 'tanvir_G_002', 'Tanvir Parking Spot 2', 110.00, 30.00, 77.00, 33.00, '2025-06-28 17:15:44'),
(38, 47, 135, 'G_owner_tanvir', 'tanvir_G_002', 'Tanvir Parking Spot 2', 165.00, 30.00, 115.50, 49.50, '2025-06-28 17:16:32'),
(39, 40, 124, 'G_owner_sami', 'sami_G_001', 'Sami\'s House', -90.00, 30.00, -63.00, -27.00, '2025-06-28 17:21:14'),
(40, 48, 132, 'G_owner_tanvir', 'tanvir_G_002', 'Tanvir Parking Spot 2', 220.00, 30.00, 154.00, 66.00, '2025-06-29 15:42:32'),
(41, 49, 131, 'U_owner_shakib', 'shakib_G_003', 'Abir HOME', 45.00, 30.00, 31.50, 13.50, '2025-06-29 15:42:55'),
(42, 50, 118, 'U_owner_shakib', 'shakib_G_002', 'Ashiq\'s Home', 65.00, 30.00, 45.50, 19.50, '2025-06-29 15:43:22'),
(43, 51, 112, 'U_owner_shakib', 'shakib_G_001', 'Shakibs Home', 55.00, 30.00, 38.50, 16.50, '2025-06-29 15:43:38');

-- --------------------------------------------------------

--
-- Table structure for table `ratings`
--

CREATE TABLE `ratings` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `garage_id` varchar(30) NOT NULL,
  `garage_name` varchar(255) DEFAULT NULL,
  `rater_username` varchar(50) NOT NULL,
  `garage_owner_username` varchar(50) NOT NULL,
  `rating` decimal(2,1) NOT NULL CHECK (`rating` >= 1.0 and `rating` <= 5.0),
  `review_text` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ratings`
--

INSERT INTO `ratings` (`id`, `booking_id`, `garage_id`, `garage_name`, `rater_username`, `garage_owner_username`, `rating`, `review_text`, `created_at`, `updated_at`) VALUES
(1, 3, 'shakib_G_002', 'Ashiq\'s Home', 'saba', 'shakib', 5.0, 'Excellent parking space, very convenient location!', '2025-05-28 13:41:43', '2025-05-28 14:51:37'),
(2, 4, 'shakib_G_001', 'Shakib Home', 'saba', 'shakib', 4.0, 'Good parking, safe and secure.', '2025-05-28 13:41:43', '2025-05-28 14:51:37'),
(3, 5, 'shakib_G_001', 'Shakib Home', 'M.Abir', 'shakib', 5.0, 'Perfect spot for my needs, highly recommended.', '2025-05-28 13:41:43', '2025-05-28 14:51:37'),
(4, 6, 'saba_G_001', 'Saba\'s Garage 02', 'shakib', 'saba', 4.0, 'Nice parking area, owner was very helpful.', '2025-05-28 13:41:43', '2025-05-28 14:51:37'),
(5, 15, 'sami_G_001', 'Sami\'s House', 'saba', 'sami', 5.0, 'Amazing parking facility with great amenities!', '2025-05-28 13:41:43', '2025-05-28 14:51:37'),
(6, 120, 'saba_G_001', 'Saba\'s Garage 02', 'shakib', 'saba', 3.0, 'not that good', '2025-05-28 14:31:46', '2025-05-28 14:51:37'),
(7, 89, 'tanvir_G_001', 'Tanvir\'s Parking Zone', 'shakib', 'tanvir', 1.0, 'nope', '2025-05-28 14:46:06', '2025-05-28 14:51:37'),
(8, 73, 'tanvir_G_001', 'Tanvir\'s Parking Zone', 'shakib', 'tanvir', 3.0, 'good', '2025-05-28 14:47:39', '2025-05-28 14:51:37'),
(9, 72, 'shakib_G_002', 'Ashiq\'s Home', 'shakib', 'shakib', 4.0, 'good man', '2025-05-28 14:55:29', '2025-05-28 14:55:29'),
(10, 71, 'shakib_G_003', 'Abir HOME', 'shakib', 'shakib', 1.0, 'vala nah', '2025-05-28 15:00:45', '2025-05-28 15:00:45'),
(11, 122, 'tanvir_G_001', 'Tanvir\'s Parking Zone', 'shakib', 'tanvir', 4.0, 'good', '2025-05-29 07:04:26', '2025-05-29 07:04:26'),
(12, 123, 'shakib_G_003', 'Abir HOME', 'saba', 'shakib', 5.0, 'great', '2025-05-29 09:09:35', '2025-05-29 09:09:35'),
(13, 124, 'sami_G_001', 'Sami\'s House', 'shakib', 'sami', 5.0, '', '2025-05-29 10:27:39', '2025-05-29 10:27:39'),
(14, 68, 'saba_G_002', 'Saba\'s Garage 01', 'shakib', 'saba', 4.0, '', '2025-05-30 08:20:01', '2025-05-30 08:20:01'),
(15, 126, 'saba_G_002', 'Saba\'s Garage 01', 'shakib', 'saba', 3.0, 'avg', '2025-05-30 11:01:57', '2025-05-30 11:01:57'),
(16, 127, 'tanvir_G_001', 'Tanvir\'s Parking Zone', 'saba', 'tanvir', 5.0, '', '2025-05-30 15:19:04', '2025-05-30 15:19:04'),
(17, 121, 'saba_G_001', 'Saba\'s Garage 02', 'shakib', 'saba', 3.0, '', '2025-05-31 06:13:31', '2025-05-31 06:13:31'),
(18, 130, 'saba_G_002', 'Saba\'s Garage 01', 'shakib', 'saba', 5.0, '', '2025-06-04 04:20:29', '2025-06-04 04:20:29'),
(19, 133, 'tanvir_G_002', 'Tanvir Parking Spot 2', 'saba', 'tanvir', 3.0, '', '2025-06-29 02:07:26', '2025-06-29 02:07:26'),
(20, 135, 'tanvir_G_002', 'Tanvir Parking Spot 2', 'ashiq', 'tanvir', 4.0, '', '2025-06-29 02:07:53', '2025-06-29 02:07:53');

--
-- Triggers `ratings`
--
DELIMITER $$
CREATE TRIGGER `auto_set_garage_name_insert` BEFORE INSERT ON `ratings` FOR EACH ROW BEGIN
    DECLARE garage_name_var VARCHAR(255);
    
    -- Get garage name from garage_information table
    SELECT Parking_Space_Name INTO garage_name_var
    FROM garage_information 
    WHERE garage_id = NEW.garage_id;
    
    -- Set the garage_name for the new record
    SET NEW.garage_name = garage_name_var;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `auto_set_garage_name_update` BEFORE UPDATE ON `ratings` FOR EACH ROW BEGIN
    DECLARE garage_name_var VARCHAR(255);
    
    -- Only update garage_name if garage_id has changed or garage_name is NULL
    IF NEW.garage_id != OLD.garage_id OR NEW.garage_name IS NULL THEN
        SELECT Parking_Space_Name INTO garage_name_var
        FROM garage_information 
        WHERE garage_id = NEW.garage_id;
        
        SET NEW.garage_name = garage_name_var;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `update_rating_summary_after_insert` AFTER INSERT ON `ratings` FOR EACH ROW BEGIN
    DECLARE garage_name_var VARCHAR(255);
    
    -- Get garage name
    SELECT Parking_Space_Name INTO garage_name_var
    FROM garage_information 
    WHERE garage_id = NEW.garage_id;
    
    -- Insert or update summary with garage name
    INSERT INTO garage_ratings_summary (garage_id, garage_name, total_ratings, average_rating, five_star, four_star, three_star, two_star, one_star)
    VALUES (NEW.garage_id, garage_name_var, 1, NEW.rating,
        CASE WHEN NEW.rating = 5.0 THEN 1 ELSE 0 END,
        CASE WHEN NEW.rating = 4.0 THEN 1 ELSE 0 END,
        CASE WHEN NEW.rating = 3.0 THEN 1 ELSE 0 END,
        CASE WHEN NEW.rating = 2.0 THEN 1 ELSE 0 END,
        CASE WHEN NEW.rating = 1.0 THEN 1 ELSE 0 END)
    ON DUPLICATE KEY UPDATE
        garage_name = garage_name_var,
        total_ratings = total_ratings + 1,
        average_rating = (SELECT AVG(rating) FROM ratings WHERE garage_id = NEW.garage_id),
        five_star = five_star + CASE WHEN NEW.rating = 5.0 THEN 1 ELSE 0 END,
        four_star = four_star + CASE WHEN NEW.rating = 4.0 THEN 1 ELSE 0 END,
        three_star = three_star + CASE WHEN NEW.rating = 3.0 THEN 1 ELSE 0 END,
        two_star = two_star + CASE WHEN NEW.rating = 2.0 THEN 1 ELSE 0 END,
        one_star = one_star + CASE WHEN NEW.rating = 1.0 THEN 1 ELSE 0 END;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `update_rating_summary_after_update` AFTER UPDATE ON `ratings` FOR EACH ROW BEGIN
    DECLARE garage_name_var VARCHAR(255);
    
    -- Get garage name
    SELECT Parking_Space_Name INTO garage_name_var
    FROM garage_information 
    WHERE garage_id = NEW.garage_id;
    
    -- Update summary with garage name
    UPDATE garage_ratings_summary 
    SET 
        garage_name = garage_name_var,
        average_rating = (SELECT AVG(rating) FROM ratings WHERE garage_id = NEW.garage_id),
        five_star = (SELECT COUNT(*) FROM ratings WHERE garage_id = NEW.garage_id AND rating = 5.0),
        four_star = (SELECT COUNT(*) FROM ratings WHERE garage_id = NEW.garage_id AND rating = 4.0),
        three_star = (SELECT COUNT(*) FROM ratings WHERE garage_id = NEW.garage_id AND rating = 3.0),
        two_star = (SELECT COUNT(*) FROM ratings WHERE garage_id = NEW.garage_id AND rating = 2.0),
        one_star = (SELECT COUNT(*) FROM ratings WHERE garage_id = NEW.garage_id AND rating = 1.0)
    WHERE garage_id = NEW.garage_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `user_login_history`
--

CREATE TABLE `user_login_history` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `login_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_login_history`
--

INSERT INTO `user_login_history` (`id`, `username`, `login_time`, `ip_address`, `user_agent`) VALUES
(3, 'shakib', '2025-05-20 08:25:06', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(4, 'tanvir', '2025-05-20 08:30:37', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(5, 'saba', '2025-05-20 08:38:42', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(6, 'admin', '2025-05-20 14:02:45', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(7, 'admin', '2025-05-21 05:46:48', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(9, 'admin', '2025-05-21 05:58:50', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(11, 'ashiq', '2025-05-21 06:23:06', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(12, 'ashiq', '2025-05-21 06:35:38', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(13, 'ashiq', '2025-05-21 08:21:40', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(14, 'shakib', '2025-05-21 08:23:51', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(15, 'ashiq', '2025-05-21 08:24:40', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(16, 'shakib', '2025-05-21 08:27:20', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(17, 'ashiq', '2025-05-21 08:35:33', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(18, 'shakib', '2025-05-21 08:39:34', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(19, 'ashiq', '2025-05-21 08:54:59', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(20, 'ashiq', '2025-05-21 11:04:47', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(21, 'shakib', '2025-05-21 11:18:22', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(22, 'ashiq', '2025-05-21 11:20:51', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(23, 'shakib', '2025-05-21 11:58:25', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(24, 'ashiq', '2025-05-21 11:58:49', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(25, 'shakib', '2025-05-21 12:22:13', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(26, 'ashiq', '2025-05-21 12:40:30', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(27, 'shakib', '2025-05-21 12:42:15', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(28, 'admin', '2025-05-21 12:42:36', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(29, 'ashiq', '2025-05-21 13:04:53', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(30, 'shakib', '2025-05-21 13:20:16', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(31, 'shakib', '2025-05-21 13:23:35', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(32, 'ashiq', '2025-05-21 14:09:29', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(33, 'shakib', '2025-05-21 14:18:45', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(34, 'ashiq', '2025-05-21 14:20:34', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(35, 'admin', '2025-05-21 14:38:17', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(36, 'shakib', '2025-05-21 14:42:04', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(37, 'ashiq', '2025-05-21 14:57:43', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(38, 'ashiq', '2025-05-21 15:22:47', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(39, 'ashiq', '2025-05-22 08:16:16', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(40, 'shakib', '2025-05-22 10:29:44', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(41, 'shakib', '2025-05-22 10:32:17', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(42, 'shakib', '2025-05-22 12:12:06', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(43, 'shakib', '2025-05-22 17:27:44', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(44, 'shakib', '2025-05-22 17:57:32', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(45, 'saba', '2025-05-22 18:32:25', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(46, 'shakib', '2025-05-22 18:39:35', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(47, 'admin', '2025-05-22 18:47:56', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(48, 'ashiq', '2025-05-22 18:48:27', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(49, 'admin', '2025-05-22 18:49:15', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(50, 'ashiq', '2025-05-22 18:49:35', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(51, 'shakib', '2025-05-22 18:56:12', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(52, 'ashiq', '2025-05-22 19:16:18', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(53, 'saba', '2025-05-22 19:25:03', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(54, 'shakib', '2025-05-22 19:42:19', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(55, 'ashiq', '2025-05-22 19:44:06', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(56, 'shakib', '2025-05-23 03:40:31', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(57, 'saba', '2025-05-23 03:45:13', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(58, 'ashiq', '2025-05-23 03:46:23', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(59, 'shakib', '2025-05-23 04:06:19', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(60, 'shakib', '2025-05-23 05:42:28', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(61, 'shakib', '2025-05-23 08:10:52', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(62, 'ashiq', '2025-05-23 10:05:12', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(63, 'shakib', '2025-05-23 10:05:39', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(64, 'saba', '2025-05-23 10:06:11', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(65, 'shakib', '2025-05-23 12:45:06', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(67, 'shakib', '2025-05-23 16:15:20', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(69, 'shakib', '2025-05-23 16:28:11', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(70, 'admin', '2025-05-23 16:31:17', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(71, 'shakib', '2025-05-23 17:03:00', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(72, 'admin', '2025-05-23 17:18:28', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(73, 'admin', '2025-05-24 02:51:10', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(74, 'admin', '2025-05-24 03:43:28', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(75, 'admin', '2025-05-24 03:51:51', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(77, 'admin', '2025-05-24 04:47:34', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(78, 'admin', '2025-05-24 04:55:40', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(79, 'admin', '2025-05-24 05:39:46', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(80, 'shakib', '2025-05-24 06:18:18', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(81, 'shakib', '2025-05-24 06:26:19', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(82, 'admin', '2025-05-24 06:37:07', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(83, 'shakib', '2025-05-24 06:40:48', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(84, 'admin', '2025-05-24 06:54:42', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(85, 'shakib', '2025-05-24 06:54:55', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(86, 'shakib', '2025-05-24 07:00:42', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(89, 'shakib', '2025-05-24 07:30:36', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(90, 'shakib', '2025-05-25 07:21:53', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(91, 'shakib', '2025-05-25 09:46:39', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(92, 'saba', '2025-05-25 10:32:27', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(93, 'shakib', '2025-05-25 10:33:25', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(94, 'saba', '2025-05-25 10:37:54', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(95, 'shakib', '2025-05-25 10:38:39', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(96, 'shakib', '2025-05-25 17:28:32', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(97, 'shakib', '2025-05-26 19:17:17', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(98, 'saba', '2025-05-26 19:19:04', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(99, 'shakib', '2025-05-26 19:20:35', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(100, 'saba', '2025-05-26 19:22:00', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(101, 'shakib', '2025-05-27 05:37:26', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(102, 'saba', '2025-05-27 05:38:15', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(103, 'shakib', '2025-05-27 05:39:55', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(104, 'saba', '2025-05-27 05:42:32', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(105, 'shakib', '2025-05-27 06:18:30', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(106, 'saba', '2025-05-27 06:18:53', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(107, 'admin', '2025-05-27 06:35:12', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(108, 'shakib', '2025-05-27 07:10:54', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(109, 'admin', '2025-05-27 07:12:27', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(110, 'shakib', '2025-05-27 07:12:40', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(111, 'admin', '2025-05-27 07:13:12', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(112, 'saba', '2025-05-27 07:24:59', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(113, 'shakib', '2025-05-27 07:40:19', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(114, 'shakib', '2025-05-27 07:41:44', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(115, 'admin', '2025-05-27 07:41:54', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(116, 'admin', '2025-05-27 09:39:51', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(117, 'shakib', '2025-05-27 09:42:29', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0'),
(118, 'shakib', '2025-05-28 06:39:16', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(119, 'admin', '2025-05-28 07:08:32', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(120, 'shakib', '2025-05-28 07:20:42', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(121, 'shakib', '2025-05-28 13:34:59', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(122, 'shakib', '2025-05-28 17:52:58', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(123, 'admin', '2025-05-28 18:41:42', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(124, 'shakib', '2025-05-28 18:56:53', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(125, 'saba', '2025-05-28 19:54:28', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(126, 'shakib', '2025-05-29 04:39:06', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(127, 'saba', '2025-05-29 04:41:00', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(128, 'shakib', '2025-05-29 04:49:52', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(129, 'shakib', '2025-05-29 07:00:24', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(130, 'M.Abir', '2025-05-29 09:06:36', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(131, 'saba', '2025-05-29 09:08:36', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(132, 'M.Abir', '2025-05-29 09:13:46', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(133, 'shakib', '2025-05-29 10:27:08', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(134, 'shakib', '2025-05-30 08:04:00', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(135, 'saba', '2025-05-30 08:57:59', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(136, 'shakib', '2025-05-30 09:01:07', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(137, 'admin', '2025-05-30 09:04:05', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(138, 'shakib', '2025-05-30 10:58:57', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(139, 'shakib', '2025-05-30 14:13:23', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(140, 'saba', '2025-05-30 15:18:20', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(141, 'shakib', '2025-05-30 15:56:06', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(142, 'saba', '2025-05-30 17:25:21', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(143, 'admin', '2025-05-30 17:35:43', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(144, 'shakib', '2025-05-30 17:39:44', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(145, 'shakib', '2025-05-30 17:42:02', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(146, 'admin', '2025-05-30 18:04:37', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(147, 'shakib', '2025-05-30 18:06:42', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(148, 'shakib', '2025-05-31 03:23:22', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(149, 'admin', '2025-05-31 03:24:36', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(150, 'shakib', '2025-05-31 04:34:11', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(151, 'admin', '2025-05-31 05:31:58', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(152, 'shakib', '2025-05-31 05:47:25', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(153, 'shakib', '2025-05-31 05:49:06', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(154, 'admin', '2025-05-31 06:11:06', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(155, 'shakib', '2025-05-31 06:12:47', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(156, 'admin', '2025-05-31 06:17:24', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(157, 'shakib', '2025-05-31 06:18:25', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(158, 'admin', '2025-05-31 06:22:37', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(159, 'admin', '2025-06-04 04:19:01', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(160, 'shakib', '2025-06-04 04:19:57', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(161, 'admin', '2025-06-04 04:21:07', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(162, 'shakib', '2025-06-04 04:22:46', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(163, 'admin', '2025-06-04 05:09:29', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(164, 'shakib', '2025-06-04 05:13:52', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(165, 'saba', '2025-06-04 05:41:50', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(166, 'shakib', '2025-06-04 05:45:43', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(167, 'saba', '2025-06-04 05:58:29', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(168, 'shakib', '2025-06-04 06:01:48', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(169, 'safa', '2025-06-04 06:08:59', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(170, 'admin', '2025-06-04 07:29:39', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(171, 'safa', '2025-06-04 07:52:58', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(172, 'shakib', '2025-06-04 07:58:34', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(173, 'admin', '2025-06-04 08:01:18', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(174, 'shakib', '2025-06-04 08:29:03', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(176, 'shakib', '2025-06-04 13:02:19', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(177, 'shakib', '2025-06-11 17:34:50', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(178, 'admin', '2025-06-11 17:59:58', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(179, 'shakib', '2025-06-11 18:08:44', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(180, 'shakib', '2025-06-12 05:20:31', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(181, 'shakib', '2025-06-13 06:57:00', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(182, 'shakib', '2025-06-13 06:57:07', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(183, 'shakib', '2025-06-13 06:57:30', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(185, 'shakib', '2025-06-17 10:40:09', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(186, 'shakib', '2025-06-18 06:49:28', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(187, 'admin', '2025-06-18 07:48:35', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(188, 'shakib', '2025-06-18 08:32:31', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(189, 'shakib', '2025-06-18 12:38:13', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(190, 'shakib', '2025-06-18 17:38:41', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(191, 'shakib', '2025-06-23 13:19:58', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(192, 'shakib', '2025-06-25 14:32:06', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(193, 'admin', '2025-06-26 09:49:16', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(194, 'shakib', '2025-06-26 10:57:30', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(195, 'admin', '2025-06-26 10:58:34', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(196, 'shakib', '2025-06-26 11:03:19', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(197, 'admin', '2025-06-26 11:08:46', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(198, 'shakib', '2025-06-26 12:11:47', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(199, 'shakib', '2025-06-26 15:22:29', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(200, 'admin', '2025-06-26 17:18:09', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(201, 'shakib', '2025-06-26 17:19:58', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(202, 'saba', '2025-06-26 17:20:54', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(203, 'admin', '2025-06-27 04:26:31', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(204, 'admin', '2025-06-27 04:38:06', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(205, 'test', '2025-06-27 06:06:09', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(206, 'admin', '2025-06-27 06:07:12', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(207, 'admin', '2025-06-27 12:59:30', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(208, 'tanvir', '2025-06-27 14:25:58', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(209, 'admin', '2025-06-27 14:30:28', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(210, 'admin', '2025-06-28 14:28:05', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(211, 'saba', '2025-06-28 17:07:30', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(212, 'shakib', '2025-06-28 17:09:48', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(213, 'ashiq', '2025-06-28 17:12:09', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(214, 'tanvir', '2025-06-28 17:13:03', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(215, 'saba', '2025-06-28 17:13:27', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(216, 'shakib', '2025-06-28 17:15:14', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(217, 'ashiq', '2025-06-28 17:16:23', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(218, 'admin', '2025-06-28 17:16:58', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(219, 'admin', '2025-06-29 01:58:47', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(220, 'tanvir', '2025-06-29 02:06:29', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(221, 'shakib', '2025-06-29 02:06:54', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(222, 'saba', '2025-06-29 02:07:16', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(223, 'ashiq', '2025-06-29 02:07:46', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(224, 'admin', '2025-06-29 02:09:02', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(225, 'shakib', '2025-06-29 02:48:10', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(226, 'admin', '2025-06-29 02:50:02', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(227, 'admin', '2025-06-29 15:13:25', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(228, 'shakib', '2025-06-29 15:40:28', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(229, 'admin', '2025-06-29 15:40:51', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(230, 'shakib', '2025-06-29 15:41:38', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(231, 'saba', '2025-06-29 15:42:18', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(232, 'shakib', '2025-06-29 15:44:01', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(233, 'admin', '2025-06-29 15:44:51', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(234, 'shakib', '2025-06-29 16:05:12', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0'),
(235, 'admin', '2025-06-29 16:06:23', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0');

--
-- Triggers `user_login_history`
--
DELIMITER $$
CREATE TRIGGER `update_account_last_login` AFTER INSERT ON `user_login_history` FOR EACH ROW BEGIN
    UPDATE account_information 
    SET last_login = NEW.login_time 
    WHERE username = NEW.username;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `user_notification_checks`
--

CREATE TABLE `user_notification_checks` (
  `username` varchar(255) NOT NULL,
  `last_check_time` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_notification_checks`
--

INSERT INTO `user_notification_checks` (`username`, `last_check_time`) VALUES
('ashiq', '2025-05-23 01:50:48'),
('saba', '2025-06-04 11:42:45'),
('shakib', '2025-05-30 23:13:33'),
('SifatRahman', '2025-05-18 17:22:45');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_information`
--

CREATE TABLE `vehicle_information` (
  `licensePlate` varchar(50) NOT NULL,
  `vehicleType` varchar(50) NOT NULL,
  `make` varchar(50) NOT NULL,
  `model` varchar(50) NOT NULL,
  `color` varchar(10) NOT NULL,
  `username` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle_information`
--

INSERT INTO `vehicle_information` (`licensePlate`, `vehicleType`, `make`, `model`, `color`, `username`) VALUES
('DHA-A-25-1010', 'sedan', 'Nissan', '2020', 'blue', 'ashiq'),
('DHA-A-25-1020', 'sedan', 'Toyota', '2022', 'Blue', 'safa'),
('DHA-A-25-4580', 'sedan', 'Toyota', '2020', 'Blue', 'test'),
('DHA-A-42-0420', 'sedan', 'BMW', '2020', 'Blue', 'SifatRahman'),
('DHA-C-12-1012', 'sedan', 'Hyundai', '2025', 'Blue', 'M.Abir'),
('DHA-D-12-4545', 'sedan', 'Nissan', '2024', 'Red', 'saba'),
('gha 252525', 'suv', 'Honda', 'yaris', 'red', 'shakib');

-- --------------------------------------------------------

--
-- Table structure for table `verification_documents`
--

CREATE TABLE `verification_documents` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `document_type` enum('nid','driving_license','passport','vehicle_registration','vehicle_insurance') NOT NULL,
  `document_number` varchar(100) DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` varchar(50) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `verification_documents`
--

INSERT INTO `verification_documents` (`id`, `username`, `document_type`, `document_number`, `file_path`, `original_filename`, `file_size`, `mime_type`, `status`, `submitted_at`, `reviewed_at`, `reviewed_by`, `rejection_reason`) VALUES
(1, 'safa', 'nid', '1221212212', 'uploads/verification/safa/nid_1749021988_7238.png', 'Screenshot 2025-05-10 224631.png', 216355, 'image/png', 'approved', '2025-06-04 07:26:28', '2025-06-04 07:52:43', 'admin', NULL),
(11, 'test', 'nid', '1515151511515', 'uploads/verification/test/nid_1751004414_5626.jpg', 'nid_1749035219_2480.jpg', 89807, 'image/jpeg', 'approved', '2025-06-27 06:06:54', '2025-06-29 15:17:27', 'admin', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `verification_requests`
--

CREATE TABLE `verification_requests` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `request_type` enum('identity','vehicle','full') NOT NULL DEFAULT 'identity',
  `overall_status` enum('pending','under_review','approved','rejected','incomplete') DEFAULT 'pending',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `admin_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `verification_requests`
--

INSERT INTO `verification_requests` (`id`, `username`, `request_type`, `overall_status`, `requested_at`, `completed_at`, `admin_notes`) VALUES
(1, 'safa', 'full', 'approved', '2025-06-04 07:26:28', '2025-06-04 07:52:43', 'everything looks good'),
(27, 'test', 'full', 'approved', '2025-06-27 06:06:54', '2025-06-29 15:17:27', 'good');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account_information`
--
ALTER TABLE `account_information`
  ADD PRIMARY KEY (`username`);

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `username` (`username`),
  ADD KEY `garage_id` (`garage_id`),
  ADD KEY `fk_vehicle_license` (`licenseplate`);

--
-- Indexes for table `dual_user`
--
ALTER TABLE `dual_user`
  ADD PRIMARY KEY (`owner_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `garagelocation`
--
ALTER TABLE `garagelocation`
  ADD PRIMARY KEY (`garage_id`);

--
-- Indexes for table `garage_information`
--
ALTER TABLE `garage_information`
  ADD PRIMARY KEY (`id`),
  ADD KEY `username` (`username`),
  ADD KEY `idx_garage_id` (`garage_id`);

--
-- Indexes for table `garage_operating_schedule`
--
ALTER TABLE `garage_operating_schedule`
  ADD PRIMARY KEY (`garage_id`);

--
-- Indexes for table `garage_owners`
--
ALTER TABLE `garage_owners`
  ADD PRIMARY KEY (`owner_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `garage_ratings_summary`
--
ALTER TABLE `garage_ratings_summary`
  ADD PRIMARY KEY (`garage_id`);

--
-- Indexes for table `garage_real_time_status`
--
ALTER TABLE `garage_real_time_status`
  ADD PRIMARY KEY (`garage_id`);

--
-- Indexes for table `garage_status_log`
--
ALTER TABLE `garage_status_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_garage_status_log` (`garage_id`,`changed_at`);

--
-- Indexes for table `owner_commissions`
--
ALTER TABLE `owner_commissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `owner_id` (`owner_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `booking_id` (`booking_id`);

--
-- Indexes for table `personal_information`
--
ALTER TABLE `personal_information`
  ADD PRIMARY KEY (`email`),
  ADD KEY `fk_personal_username` (`username`);

--
-- Indexes for table `points_transactions`
--
ALTER TABLE `points_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `username` (`username`),
  ADD KEY `booking_id` (`booking_id`);

--
-- Indexes for table `profit_tracking`
--
ALTER TABLE `profit_tracking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `payment_id` (`payment_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `owner_id` (`owner_id`);

--
-- Indexes for table `ratings`
--
ALTER TABLE `ratings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_booking_rating` (`booking_id`),
  ADD KEY `garage_id` (`garage_id`),
  ADD KEY `rater_username` (`rater_username`),
  ADD KEY `garage_owner_username` (`garage_owner_username`);

--
-- Indexes for table `user_login_history`
--
ALTER TABLE `user_login_history`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user_notification_checks`
--
ALTER TABLE `user_notification_checks`
  ADD PRIMARY KEY (`username`);

--
-- Indexes for table `vehicle_information`
--
ALTER TABLE `vehicle_information`
  ADD PRIMARY KEY (`licensePlate`),
  ADD KEY `fk_username` (`username`);

--
-- Indexes for table `verification_documents`
--
ALTER TABLE `verification_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `username` (`username`);

--
-- Indexes for table `verification_requests`
--
ALTER TABLE `verification_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=140;

--
-- AUTO_INCREMENT for table `garage_information`
--
ALTER TABLE `garage_information`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `garage_status_log`
--
ALTER TABLE `garage_status_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `owner_commissions`
--
ALTER TABLE `owner_commissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `points_transactions`
--
ALTER TABLE `points_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `profit_tracking`
--
ALTER TABLE `profit_tracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `ratings`
--
ALTER TABLE `ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `user_login_history`
--
ALTER TABLE `user_login_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=236;

--
-- AUTO_INCREMENT for table `verification_documents`
--
ALTER TABLE `verification_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `verification_requests`
--
ALTER TABLE `verification_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`username`) REFERENCES `account_information` (`username`),
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`garage_id`) REFERENCES `garagelocation` (`garage_id`),
  ADD CONSTRAINT `fk_vehicle_license` FOREIGN KEY (`licenseplate`) REFERENCES `vehicle_information` (`licensePlate`) ON UPDATE CASCADE;

--
-- Constraints for table `dual_user`
--
ALTER TABLE `dual_user`
  ADD CONSTRAINT `dual_user_ibfk_1` FOREIGN KEY (`username`) REFERENCES `account_information` (`username`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `garage_information`
--
ALTER TABLE `garage_information`
  ADD CONSTRAINT `garage_information_ibfk_1` FOREIGN KEY (`username`) REFERENCES `account_information` (`username`);

--
-- Constraints for table `garage_operating_schedule`
--
ALTER TABLE `garage_operating_schedule`
  ADD CONSTRAINT `garage_operating_schedule_ibfk_1` FOREIGN KEY (`garage_id`) REFERENCES `garage_information` (`garage_id`) ON DELETE CASCADE;

--
-- Constraints for table `garage_owners`
--
ALTER TABLE `garage_owners`
  ADD CONSTRAINT `fk_garage_owners_username` FOREIGN KEY (`username`) REFERENCES `account_information` (`username`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `garage_ratings_summary`
--
ALTER TABLE `garage_ratings_summary`
  ADD CONSTRAINT `fk_summary_garage` FOREIGN KEY (`garage_id`) REFERENCES `garagelocation` (`garage_id`) ON DELETE CASCADE;

--
-- Constraints for table `garage_real_time_status`
--
ALTER TABLE `garage_real_time_status`
  ADD CONSTRAINT `garage_real_time_status_ibfk_1` FOREIGN KEY (`garage_id`) REFERENCES `garage_information` (`garage_id`) ON DELETE CASCADE;

--
-- Constraints for table `garage_status_log`
--
ALTER TABLE `garage_status_log`
  ADD CONSTRAINT `garage_status_log_ibfk_1` FOREIGN KEY (`garage_id`) REFERENCES `garage_information` (`garage_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `personal_information`
--
ALTER TABLE `personal_information`
  ADD CONSTRAINT `fk_personal_username` FOREIGN KEY (`username`) REFERENCES `account_information` (`username`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `points_transactions`
--
ALTER TABLE `points_transactions`
  ADD CONSTRAINT `points_transactions_ibfk_1` FOREIGN KEY (`username`) REFERENCES `account_information` (`username`) ON DELETE CASCADE,
  ADD CONSTRAINT `points_transactions_ibfk_2` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `profit_tracking`
--
ALTER TABLE `profit_tracking`
  ADD CONSTRAINT `fk_profit_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_profit_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE CASCADE;

--
-- Constraints for table `ratings`
--
ALTER TABLE `ratings`
  ADD CONSTRAINT `fk_rating_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rating_garage` FOREIGN KEY (`garage_id`) REFERENCES `garagelocation` (`garage_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rating_owner` FOREIGN KEY (`garage_owner_username`) REFERENCES `account_information` (`username`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rating_rater` FOREIGN KEY (`rater_username`) REFERENCES `account_information` (`username`) ON DELETE CASCADE;

--
-- Constraints for table `vehicle_information`
--
ALTER TABLE `vehicle_information`
  ADD CONSTRAINT `fk_username` FOREIGN KEY (`username`) REFERENCES `account_information` (`username`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `verification_documents`
--
ALTER TABLE `verification_documents`
  ADD CONSTRAINT `verification_documents_ibfk_1` FOREIGN KEY (`username`) REFERENCES `account_information` (`username`) ON DELETE CASCADE;

--
-- Constraints for table `verification_requests`
--
ALTER TABLE `verification_requests`
  ADD CONSTRAINT `verification_requests_ibfk_1` FOREIGN KEY (`username`) REFERENCES `account_information` (`username`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
