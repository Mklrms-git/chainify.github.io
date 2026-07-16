-- Fix: Add missing 'rating' column to supplier_ratings table
-- Run this in phpMyAdmin SQL tab if you get "Unknown column 'rating'" error

USE supply_chain_db;

-- First, check the current table structure
DESCRIBE supplier_ratings;

-- Add the rating column (if it doesn't exist, this will add it)
-- If you get an error saying the column already exists, that's fine - skip this step
ALTER TABLE supplier_ratings 
ADD COLUMN rating DECIMAL(2,1) NOT NULL DEFAULT 0 COMMENT 'Rating 1-5 stars' AFTER rated_by;

-- Add the comments column if it doesn't exist
ALTER TABLE supplier_ratings 
ADD COLUMN comments TEXT NULL COMMENT 'Additional comments about the delivery' AFTER rating;

-- Verify the table structure after adding columns
DESCRIBE supplier_ratings;
