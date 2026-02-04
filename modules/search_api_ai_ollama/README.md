# Search API AI Ollama

Provides Ollama embedding model integration for Search API AI.

## Features

- **All Ollama Models Available**: Lists all models from your Ollama instance
- **Dynamic Dimension Detection**: Automatically detects embedding dimensions
- **Multi-Provider Support**: Leverages the OpenAI module's Ollama adapter

## Important Notes

### Model Selection

This module returns **ALL** Ollama models because Ollama doesn't provide reliable metadata to identify which models support embeddings. You must know which of your Ollama models support embeddings and select accordingly.

Common Ollama embedding models:
-- `nomic-embed-text` (768 dimensions)
- `mxbai-embed-large` (1024 dimensions)
- `all-minilm` (384 dimensions)

### Requirements

- `search_api_ai` module
- `openai` module (base module)
- `openai_ollama` adapter module
- Ollama server running and accessible

## Configuration

1. Enable this module
2. Go to Search API index configuration
3. Select "Ollama" as your embedding engine
4. Choose your embedding model from the dropdown
5. The dimension will be auto-detected

## Dimension Detection

Dimensions are automatically detected using:
1. Test embedding call to the model (cached for 24 hours)
2. Fallback to default (1536) if detection fails

You can override the detected dimension in the configuration form if needed.
