<?php
/**
 * @file
 * Default theme implementation to display AI search results.
 *
 * Available variables:
 * - $results: The search results array.
 * - $query: The search query string.
 *
 * @see template_preprocess_search_api_ai_search_block_results()
 */
?>
<div class="search-api-ai-search-block-results">
  <?php if (!empty($query)): ?>
    <h2><?php print t('Search results for "@query"', array('@query' => $query)); ?></h2>
  <?php endif; ?>

  <?php if (!empty($results)): ?>
    <div class="search-api-ai-search-block-results-list">
      <?php foreach ($results as $result): ?>
        <div class="search-api-ai-search-block-result">
          <h3 class="search-api-ai-search-block-result-title">
            <?php if (!empty($result['link'])): ?>
              <a href="<?php print $result['link']; ?>"><?php print $result['title']; ?></a>
            <?php else: ?>
              <?php print $result['title']; ?>
            <?php endif; ?>
          </h3>
          <?php if (!empty($result['snippet'])): ?>
            <div class="search-api-ai-search-block-result-snippet">
              <?php print $result['snippet']; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="search-api-ai-search-block-no-results">
      <p><?php print t('No results found for "@query". Please try a different search.', array('@query' => $query)); ?></p>
    </div>
  <?php endif; ?>
</div>
