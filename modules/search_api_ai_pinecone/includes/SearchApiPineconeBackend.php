<?php
/**
 * @file
 * Contains the SearchApiAiPineconeBackend class for Backdrop CMS.
 */

class SearchApiAiPineconeBackend extends SearchApiBackend {

  protected $client;

  public function __construct($server, $options = []) {
    parent::__construct($server, $options);
    $plugin_id = config_get('openai_embeddings.settings', 'vector_client_plugin');
    $this->client = search_api_vector_client_load($plugin_id);
  }

  public function defaultConfiguration() {
    return [
      'namespace' => NULL,
    ];
  }

  public function viewSettingsForm(&$form, &$form_state) {
    $form['namespace'] = [
      '#type' => 'textfield',
      '#title' => t('Namespace'),
      '#description' => t('An optional override for the default namespace. The index ID will always be appended.'),
      '#default_value' => $this->options['namespace'],
      '#pattern' => '[a-zA-Z0-9_]*',
    ];
  }

  public function indexItems($index, array $items) {
    $server_id = $this->server->machine_name;
    $index_id = $index->machine_name;
    $namespace = $this->getNamespace($index);
    $successful_item_ids = [];

    foreach ($items as $item) {
      $this->deleteItems($index, [$item->item_id]);

      $item_base = [
        'metadata' => [
          'server_id' => $server_id,
          'index_id' => $index_id,
          'item_id' => $item->item_id,
        ],
      ];
      $chunked_items = [];

      foreach ($item->fields as $field) {
        if ($field['type'] === 'embeddings') {
          foreach ($field['values'] as $delta1 => $values) {
            foreach ($values as $delta2 => $value) {
              $chunked_items[] = [
                'id' => $item->item_id . ':' . $field['field_identifier'] . ':' . $delta1 . ':' . $delta2,
                'values' => $value['vectors'],
                'metadata' => [
                  'content' => $value['content'],
                ],
              ];
            }
          }
        } else {
          $item_base['metadata'][$field['field_identifier']] = is_array($field['values']) ? implode(',', $field['values']) : $field['values'];
        }
      }

      foreach ($chunked_items as $chunked_item) {
        $chunked_item += $item_base;
        $chunked_item['metadata'] += $item_base['metadata'];
        $this->client->upsert(['vectors' => $chunked_item, 'collection' => $namespace]);
      }
      $successful_item_ids[] = $item->item_id;
    }

    return $successful_item_ids;
  }

  public function deleteItems($index, array $item_ids) {
    $this->client->delete([
      'collection' => $this->getNamespace($index),
      'filter' => [
        'item_id' => ['$in' => $item_ids],
      ],
    ]);
  }

  public function deleteAllIndexItems($index, $datasource_id = NULL) {
    $this->client->delete([
      'deleteAll' => TRUE,
      'collection' => $this->getNamespace($index),
    ]);
  }

  public function search($query) {
    $index = $query->index;

    if (in_array('server_index_status', $query->tags)) {
      return NULL;
    }
    $response = $this->client->query([
      'vector' => $query->options['query_embedding'],
      'top_k' => $query->options['top_k'],
      'include_metadata' => $query->options['include_metadata'],
      'include_values' => TRUE,
      'filter' => $query->options['filters'],
      'collection' => $query->options['namespace'],
    ]);

    $results = $query->results;
    $decoded_response = json_decode($response->getBody()->getContents(), TRUE);

    foreach ($decoded_response['matches'] as $match) {
      $item = search_api_create_item($index, $match['id']);
      $item->score = $match['score'];
      $this->extractMetadata($match, $item);
      $results->addResultItem($item);
    }
    $results->setResultCount(count($decoded_response['matches']));
  }

  public function extractMetadata($result_row, $item) {
    $item->extra_data['metadata'] = $result_row['metadata'];
  }

  public function getNamespace($index) {
    return ($this->options['namespace'] ?? "searchapi:{$this->server->machine_name}") . ":{$index->machine_name}";
  }

  public function supportsDataType($type) {
    return in_array($type, ['embeddings']);
  }
}
