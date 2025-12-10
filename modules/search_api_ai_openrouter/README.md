# Search API AI OpenRouter

Provides OpenRouter embedding model integration for Search API AI.

## Features

- **Filtered Model List**: Only shows models that support embeddings (via OpenRouter's `/models/embeddings` endpoint)
- **Dynamic Dimension Detection**: Automatically detects embedding dimensions
- **Wide Model Selection**: Access to Voyage AI, Cohere, and other embedding providers through OpenRouter

## Model Filtering

Unlike Ollama, this module **only shows embedding-capable models** because OpenRouter provides a dedicated embeddings endpoint that returns filtered results.

Common OpenRouter embedding models:
- `voyage-ai/voyage-3` (1024 dimensions)
- `voyage-ai/voyage-3-lite` (512 dimensions)
- `cohere/embed-english-v3.0` (1024 dimensions)
- `cohere/embed-multilingual-v3.0` (1024 dimensions)

## Requirements

- `search_api_ai` module
- `openai` module (base module)
- `openai_openrouter` adapter module
- OpenRouter API key configured

## Configuration

1. Enable this module
2. Go to Search API index configuration
3. Select "OpenRouter" as your embedding engine
4. Choose your embedding model from the dropdown (only embedding models shown)
5. The dimension will be auto-detected

## Dimension Detection

Dimensions are automatically detected using:
1. Test embedding call to the model (cached for 24 hours)
2. Fallback to known dimensions for common models
3. Default (1536) if all else fails

You can override the detected dimension in the configuration form if needed.

## Cost Considerations

OpenRouter charges per token for embeddings. Check their pricing at https://openrouter.ai/docs#models for current rates.
