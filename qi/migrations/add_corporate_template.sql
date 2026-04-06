-- Add 'corporate' to qi_template enum (currently only modern/classic/minimal/bold)
ALTER TABLE `companies` MODIFY `qi_template` ENUM('modern','classic','minimal','bold','corporate') DEFAULT 'modern';
