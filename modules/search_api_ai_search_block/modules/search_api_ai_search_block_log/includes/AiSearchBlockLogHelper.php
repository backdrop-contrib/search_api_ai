<?php

/**
 * @file
 * Helper class for AI Search Block Log module.
 */

/**
 * The helper class for logging AI search block interactions.
 */
class AiSearchBlockLogHelper {

  /**
   * Delete the expired logs during cron.
   *
   * @return void
   */
  public function cron() {
    // Delete entries that have passed their expiration date
    db_delete('search_api_ai_search_block_log')
      ->condition('expiration', REQUEST_TIME, '<')
      ->isNotNull('expiration')
      ->execute();
  }

  /**
   * Start a new log entry.
   *
   * @param string $block_id
   *   The block ID.
   * @param int $uid
   *   The user ID.
   * @param string $query
   *   The search query.
   *
   * @return int
   *   The ID of the created log entry.
   */
  public function start($block_id, $uid, $query) {
    // Get retention setting to calculate expiration
    $config = config('search_api_ai_search_block_log.settings');
    $retention_days = $config->get('log_retention');
    
    // Calculate expiration timestamp (NULL if never expires)
    $expiration = NULL;
    if (!empty($retention_days) && $retention_days > 0) {
      $expiration = REQUEST_TIME + ($retention_days * 86400);
    }

    $log_id = db_insert('search_api_ai_search_block_log')
      ->fields(array(
        'block_id' => $block_id,
        'uid' => $uid,
        'query' => $query,
        'created' => REQUEST_TIME,
        'changed' => REQUEST_TIME,
        'expiration' => $expiration,
      ))
      ->execute();

    return $log_id;
  }

  /**
   * Log the AI response to the database.
   *
   * @param int $id
   *   The log entry ID.
   * @param string $response
   *   The AI-generated response.
   *
   * @return void
   */
  public function logResponse($id, $response) {
    db_update('search_api_ai_search_block_log')
      ->fields(array(
        'response' => $response,
        'changed' => REQUEST_TIME,
      ))
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Update log entry with arbitrary fields.
   *
   * @param int $id
   *   The log entry ID.
   * @param array $fields
   *   Associative array of field names and values to update.
   *
   * @return void
   */
  public function update($id, array $fields) {
    // Add changed timestamp
    $fields['changed'] = REQUEST_TIME;

    db_update('search_api_ai_search_block_log')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();
  }

}
