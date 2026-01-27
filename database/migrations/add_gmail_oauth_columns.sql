-- Add Gmail OAuth token columns to users table
-- Migration: Add gmail_token and gmail_authorized_at
-- Date: 2026-01-27

USE expense_tracker;

-- Add gmail_token column to store OAuth token JSON
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS gmail_token JSON COMMENT 'Gmail OAuth access token and refresh token' AFTER profile_picture;

-- Add gmail_authorized_at column to track when user authorized
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS gmail_authorized_at TIMESTAMP NULL COMMENT 'When user authorized Gmail access' AFTER gmail_token;
