# AI Search Block Log

## Overview
AI Search Block Log provides logging functionality for the AI Search Block module.
It records search queries and responses in the database, allowing site administrators
to track usage and analyze search patterns.

## Requirements
This module requires the following modules:
- [AI Search Block](https://github.com/backdrop-contrib/ai_search_block)

## Installation
1. Enable the AI Search Block Log module through the Modules admin page or using Drush.
2. The module will automatically begin recording search queries and responses.

## Features
- Records user search queries
- Stores AI-generated responses
- Tracks user ID and timestamp
- Automatically cleans up old logs (older than 30 days) via cron

## Database Schema
The module creates a database table called `ai_search_block_log` with the following fields:
- `id`: Unique identifier for each log entry
- `block_id`: The ID of the block where the search was performed
- `uid`: The user ID of the person who performed the search
- `query`: The search query text
- `response`: The AI-generated response text
- `created`: Timestamp when the log entry was created
- `changed`: Timestamp when the log entry was last updated

## Port from Drupal
This module has been ported from Drupal 11 to Backdrop CMS. The following changes were made:
- Changed info.yml file to Backdrop's .info format
- Removed Drupal 8+ style dependency injection
- Implemented a helper class that is loaded using hook_autoload_info()
- Updated template files from Twig to PHPTemplate format
- Added database schema in .install file

## Current Maintainers
- Seeking maintainers

## License
This project is GPL v2 software. See the LICENSE.txt file in the module root directory for complete text.
