# AI Search Block

## Overview
AI Search Block provides a block for AI-powered search functionality in Backdrop CMS.
This module integrates with the AI and Search API modules to provide enhanced search
capabilities using artificial intelligence.

## Requirements
This module requires the following modules:
- [AI](https://github.com/backdrop-contrib/ai)
- [Search API](https://backdropcms.org/project/search_api)
- [AI Search](https://github.com/backdrop-contrib/ai_search)

## Installation
1. Install this module using the official Backdrop CMS instructions at
   https://backdropcms.org/guide/modules.
2. Go to Structure > Blocks and place the "AI Search" block in your desired region.

## Configuration
No additional configuration is needed for the block itself. However, you should
ensure that the AI and Search API modules are properly configured.

## Usage
Once the AI Search block is placed in a region, users with the "Access AI search block"
permission can use it to search your site with AI-enhanced results.

## Submodules
### AI Search Block Log
This submodule provides logging functionality for AI search queries. It stores search
queries and responses in the database, allowing site administrators to track usage and
analyze search patterns.

To enable logging, simply enable the AI Search Block Log module, which will automatically
begin recording search queries and responses.

## Port from Drupal
This module has been ported from Drupal 11 to Backdrop CMS. The following changes were made:
- Changed .info.yml files to .info format for both the main module and submodule
- Removed Drupal 8+ style dependency injection in favor of Backdrop's function-based approach
- Updated form handling to match Backdrop's conventions
- Updated template files from Twig to PHPTemplate format
- Created helper classes that are loaded using hook_autoload_info()

## Issues
Bugs and feature requests should be reported in the Issue Queue:
https://github.com/backdrop-contrib/ai_search_block/issues.

## Current Maintainers
- Seeking maintainers

## License
This project is GPL v2 software. See the LICENSE.txt file in this directory for complete text.
