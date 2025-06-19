<?php

/**
 * Boosts database search results using AI Search matches.
 */
class DatabaseBoostByAiSearch extends SearchApiAbstractProcessor {

  public function __construct(SearchApiIndex $index, array $options = array()) {
    parent::__construct($index, $options);
    $this->options += $this->defaultConfiguration();
  }

  public function preprocessSearchQuery(SearchApiQuery $query) {
    dpm($query);
    $keywords = $query->getKeys();
    if (empty($keywords)) {
      return;
    }

    $results = $this->getAiSearchResults($keywords);
    dpm($results);
    if (!empty($results)) {
      // Instead of addTag(), we store in meta data.
      $query->setOption('ai_search_ids', array_keys($results));
    }
  }

  public function supportsIndex(SearchApiIndex $index) {
    $server = search_api_server_load($index->server);
    return $server && $server->class === 'search_api_db_service';
  }


  public function defaultConfiguration() {
    return array(
      'search_api_ai_index' => '',
      'minimum_relevance_score' => 0.4,
      'number_to_return' => 10,
    );
  }

  public function configurationForm() {
    $form = array();

    $options = array('' => t('- Select -'));
    $indexes = search_api_index_load_multiple(FALSE);
    dpm($indexes);
    foreach ($indexes as $index) {
      if ($index->machine_name == $this->index->machine_name) {
        continue;
      }
      $server = search_api_server_load($index->server);
      if ($server && in_array($server->class, ['search_api_ai_search', 'search_api_pinecone', 'pinecone', 'search_api_milvus', 'milvus'])) {
        $options[$index->machine_name] = check_plain($index->name) . ' (' . check_plain($server->name) . ')';
      }
    }

    $form['search_api_ai_index'] = array(
      '#type' => 'select',
      '#title' => t('AI Search index to combine with'),
      '#options' => $options,
      '#default_value' => isset($this->options['search_api_ai_index']) ? $this->options['search_api_ai_index'] : '',
      '#required' => TRUE,
    );

    $form['minimum_relevance_score'] = array(
      '#type' => 'textfield',
      '#title' => t('Minimum relevance score'),
      '#default_value' => isset($this->options['minimum_relevance_score']) ? $this->options['minimum_relevance_score'] : 0.4,
      '#description' => t('Only results above this score will be prepended.'),
      '#required' => TRUE,
    );

    $form['number_to_return'] = array(
      '#type' => 'textfield',
      '#title' => t('Number of AI results to return'),
      '#default_value' => isset($this->options['number_to_return']) ? $this->options['number_to_return'] : 10,
      '#required' => TRUE,
    );

    return $form;
  }

  public function configurationFormValidate(array $form, array &$values, array &$form_state) {
    parent::configurationFormValidate($form, $values, $form_state);

    $server = search_api_server_load($this->index->server);
    dpm($server);
    if ($server && $server->class !== 'search_api_db_service') {
      form_set_error('search_api_ai_index', t('This processor plugin only supports Database search backend.'));
    }

    if (empty($values['search_api_ai_index'])) {
      form_set_error('search_api_ai_index', t('Choose an AI Search index to search with.'));
    }
  }

  public function submitConfigurationForm(array $form, array &$form_state) {
    $this->options['search_api_ai_index'] = $form_state['values']['search_api_ai_index'];
    $this->options['minimum_relevance_score'] = (float) $form_state['values']['minimum_relevance_score'];
    $this->options['number_to_return'] = (int) $form_state['values']['number_to_return'];

    watchdog('database_boost_by_ai_search', 'Saved config: <pre>@data</pre>', [
      '@data' => print_r($this->options, TRUE),
    ], WATCHDOG_INFO);
  }

  protected function getAiSearchResults($keywords) {
    $limit = (int) $this->options['number_to_return'];
    if ($limit <= 0) {
      return array();
    }

    $ai_index = search_api_index_load($this->options['search_api_ai_index']);
    if (!$ai_index) {
      watchdog('ai_search', 'Failed to load AI index.', array(), WATCHDOG_WARNING);
      return array();
    }

    $query = $ai_index->query(array('limit' => $limit));
    $query->keys($keywords);
    $query->setOption('search_api_bypass_access', TRUE);
    $results = $query->execute();

    dpm($results);
    $matches = array();
    if (!empty($results['results']) && is_array($results['results'])) {
      foreach ($results['results'] as $id => $item) {
        if (!empty($item['score']) && $item['score'] >= $this->options['minimum_relevance_score']) {
          $matches[$id] = $item['score'];
        }
      }
    }
    dpm($matches);
    return $matches;
  }

  public static function queryAlter(SelectQueryInterface $query, array $item_ids) {
    if (empty($item_ids)) {
      return;
    }

    $search_alias = 't';

    // DO NOT transform IDs — your DB index uses plain numeric values like 38, 39

    // Attempt to find and remove the original keyword condition
    $conditions = &$query->conditions();
    $keyword_condition = NULL;
    foreach ($conditions as $key => $condition) {
      if (is_array($condition)
        && isset($condition['field'], $condition['value'], $condition['operator'])
        && $condition['field'] === "$search_alias.word"
      ) {
        $keyword_condition = $condition;
        unset($conditions[$key]); // Important: remove it so it's not ANDed
        break;
      }
    }

    // Build db_or() condition group: (keyword match OR item_id IN ...)
    $or = db_or();
    if ($keyword_condition) {
      $or->condition($keyword_condition['field'], $keyword_condition['value'], $keyword_condition['operator']);
    }
    $or->condition("$search_alias.item_id", $item_ids, 'IN');
    $query->condition($or); // Safe: adds (keyword OR id) as a group

    // Remove having clause if it restricts based on keyword match count
    $having_conditions = &$query->havingConditions();
    foreach ($having_conditions as $key => $condition) {
      if (is_array($condition)
        && isset($condition['field'])
        && str_contains($condition['field'], 'COUNT(DISTINCT')
      ) {
        unset($having_conditions[$key]);
      }
    }

    // Add AI boost score as CASE expression
    $placeholders = [];
    $parts = [];
    $weight = count($item_ids);
    foreach ($item_ids as $i => $id) {
      $key = ':boost_' . $i;
      $parts[] = "WHEN $search_alias.item_id = $key THEN " . ($weight - $i);
      $placeholders[$key] = $id;
    }
    $expression = 'CASE ' . implode(' ', $parts) . ' ELSE 0 END';
    $query->addExpression($expression, 'ai_boost', $placeholders);

    $query->groupBy("$search_alias.item_id");
    $query->orderBy('ai_boost', 'DESC');

    // Debugging
    watchdog('ai_search', 'Final query: @sql', ['@sql' => (string) $query]);
  }
}
