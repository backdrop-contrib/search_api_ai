<?php

/**
 * @file
 * Provides the DatabaseBoostByAiSearch processor for Search API AI.
 */

class DatabaseBoostByAiSearch extends SearchApiAbstractProcessor {

  public function __construct(SearchApiIndex $index, array $options = array()) {
    parent::__construct($index, $options);
    $this->options += $this->defaultConfiguration();
  }

  public function preprocessSearchQuery(SearchApiQuery $query) {
    $keywords = $query->getKeys();
    if (empty($keywords)) {
      return;
    }

    $results = $this->getAiSearchResults($keywords);
    if (!empty($results)) {
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
    foreach ($indexes as $index) {
      if ($index->machine_name == $this->index->machine_name) {
        continue;
      }
      $server = search_api_server_load($index->server);
      if ($server && in_array($server->class, [
        'search_api_ai_search', 'search_api_pinecone', 'pinecone', 'search_api_milvus', 'milvus'
      ])) {
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

    $matches = array();
    if (!empty($results['results']) && is_array($results['results'])) {
      foreach ($results['results'] as $id => $item) {
        if (!empty($item['score']) && $item['score'] >= $this->options['minimum_relevance_score']) {
          $matches[$id] = $item['score'];
        }
      }
    }
    return $matches;
  }

  /**
   * Alters the query to combine AI results with DB keyword results.
   */
  public static function queryAlter(SelectQueryInterface $query, array $item_ids) {
    if (empty($item_ids)) {
      return;
    }
    $search_alias = 't';

    // 1. Find and alter the subquery over the text index table.
    foreach ($query->getTables() as &$table) {
      if (
        is_array($table) &&
        isset($table['table']) &&
        $table['table'] instanceof SelectQueryInterface
      ) {
        $subquery = &$table['table'];
        $conditions = &$subquery->conditions();

        // Debug: Log the initial conditions so you can see structure in watchdog.
        // Uncomment this if debugging:
        // watchdog('ai_search', 'Subquery initial conditions: <pre>@data</pre>', [
        //   '@data' => print_r($conditions, TRUE),
        // ]);

        // Allow for both "t.word" and "word", and "t.item_id" and "item_id".
        $word_fields = ["$search_alias.word", 'word'];
        $item_id_fields = ["$search_alias.item_id", 'item_id'];

        // Remove and collect all relevant conditions.
        $word_condition = NULL;
        $field_name_condition = NULL;

        foreach ($conditions as $key => $condition) {
          if (is_array($condition) && isset($condition['field'])) {
            if (in_array($condition['field'], $word_fields)) {
              $word_condition = $condition;
              unset($conditions[$key]);
            } elseif ($condition['field'] === 'field_name') {
              $field_name_condition = $condition;
              unset($conditions[$key]);
            } elseif (in_array($condition['field'], $item_id_fields)) {
              unset($conditions[$key]);
            }
          }
        }

        // Build the big OR group.
        $or = db_or();

        if ($word_condition && $field_name_condition) {
          $and1 = db_and();
          $and1->condition($word_condition['field'], $word_condition['value'], $word_condition['operator']);
          $and1->condition($field_name_condition['field'], $field_name_condition['value'], $field_name_condition['operator']);
          $or->condition($and1);
        }

        if ($field_name_condition) {
          $and2 = db_and();
          $and2->condition($item_id_fields[0], $item_ids, 'IN');
          $and2->condition($field_name_condition['field'], $field_name_condition['value'], $field_name_condition['operator']);
          $or->condition($and2);
        } else {
          // Fallback: If no field_name (should not happen), just match item IDs.
          $or->condition($item_id_fields[0], $item_ids, 'IN');
        }

        // Remove all numeric keys, keep #conjunction.
        foreach (array_keys($conditions) as $key) {
          if (is_int($key)) {
            unset($conditions[$key]);
          }
        }
        // Ensure root #conjunction is present.
        $conditions['#conjunction'] = 'AND';
        // Add our single OR group.
        $subquery->condition($or);
      }
    }

    // 2. Add AI boost score as CASE expression to the OUTER query
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

    // Remove interfering HAVING clauses.
    $having_conditions = &$query->havingConditions();
    foreach ($having_conditions as $key => $condition) {
      if (is_array($condition)
        && isset($condition['field'])
        && strpos($condition['field'], 'COUNT(DISTINCT') !== FALSE
      ) {
        unset($having_conditions[$key]);
      }
    }

    // Debugging: Uncomment if needed.
    // watchdog('ai_search', 'Final query: @sql', ['@sql' => (string) $query]);
  }

}
