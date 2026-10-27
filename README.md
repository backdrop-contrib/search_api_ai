# Search API AI

> **Deprecated:** This module is no longer developed. It is replaced by
> [`ai_search`](https://github.com/backdrop-contrib/ai_search), part of the [AI](https://github.com/backdrop-contrib/ai) suite for Backdrop CMS.

Vector backends are separate modules: [`ai_search_provider_milvus`](https://github.com/backdrop-contrib/ai_search_provider_milvus), [`ai_search_provider_pinecone`](https://github.com/backdrop-contrib/ai_search_provider_pinecone), [`ai_search_provider_pgvector`](https://github.com/backdrop-contrib/ai_search_provider_pgvector), [`ai_search_provider_mariadb`](https://github.com/backdrop-contrib/ai_search_provider_mariadb).
The `search_api_ai_*` submodules map to the matching `ai_search_*`
submodules.

If you have this module installed, upgrade to the replacement:

1. Add the [AI](https://github.com/backdrop-contrib/ai) module and the replacement module(s) to your site.
2. Enable **AI Upgrade UI** (`ai_upgrade`, included with AI).
3. Run the upgrade at `admin/config/ai/upgrade` (or `bee ai-upgrade`). It
   enables the replacements, copies your settings, permissions, blocks and
   stored data, and disables this module.

## What does it do?
Search API AI is a cutting-edge module that provides a collection of tools to integrate artificial intelligence (AI) and advanced search capabilities into your Drupal site. By leveraging the OpenAI Embedding API and storing vectors in Pinecone, this module enables you to create powerful, AI-driven search experiences for your users. With plans to support additional tools for creating embeddings and storing vectors, Search API AI is designed to be a flexible and extensible solution for your search needs.

For site administrators, Search API AI offers an innovative alternative to traditional search solutions, with the potential to deliver more relevant and personalized search results.

Developers will appreciate the module's flexibility and extensibility, with numerous ways to extend its functionality and integrate it with other AI tools and storage solutions. As the module grows, expect to see a growing number of additional contrib modules that provide extra functionality or help users customize various aspects of the search process.

## Getting started
To get started, you'll need to set up an index using the Pinecone backend and index your content, including the Body field using the Embeddings field type. Once you've done that, head to Admin -> Configuration -> Open AI -> Search API AI Explorer to start your conversation. Don't forget to configure your OpenAI and Pinecone credentials!

## Requirements

To use Search API AI, you will need to have the Search API and OpenAI modules installed and enabled on your Drupal site. Additionally, you will need to enable at least one AI backend, such as the provided Pinecone backend.

## Installation

- Install this module using the official [Backdrop CMS instructions](https://backdropcms.org/user-guide/modules).

## Issues

Bugs and feature requests should be reported in the [Issue Queue](https://github.com/backdrop-contrib/search_api_ai/issues).

## Current Maintainer

[Justin Keiser](https://github.com/keiserjb)

## Credits

- Ported to Backdrop CMS by [Justin Keiser](https://github.com/keiserjb).
- Created for Drupal by [Andrew Belcher](https://www.drupal.org/u/andrewbelcher).

The module is unsupported and marked as deprecated in Drupal.

## License

This project is GPL v2 software. See the LICENSE.txt file in this directory for complete text.
