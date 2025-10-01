<?php

/**
 * @file
 * Admin settings form for Search API AI Search Block Log.
 */

/**
 * Form builder for the settings form.
 */
function search_api_ai_search_block_log_settings_form($form, &$form_state) {
  $config = config('search_api_ai_search_block_log.settings');

  $form['#config'] = 'search_api_ai_search_block_log.settings';

  $form['log_enabled'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable logging'),
    '#description' => t('Enable logging of AI search queries and responses.'),
    '#default_value' => $config->get('log_enabled') !== FALSE ? $config->get('log_enabled') : 1,
  );

  $form['log_retention'] = array(
    '#type' => 'select',
    '#title' => t('Log retention period'),
    '#description' => t('How long to keep log entries before they are deleted during cron runs.'),
    '#options' => array(
      7 => t('1 week'),
      14 => t('2 weeks'),
      30 => t('1 month'),
      90 => t('3 months'),
      180 => t('6 months'),
      365 => t('1 year'),
      0 => t('Never delete'),
    ),
    '#default_value' => $config->get('log_retention') ?: 30,
    '#states' => array(
      'visible' => array(
        ':input[name="log_enabled"]' => array('checked' => TRUE),
      ),
    ),
  );

  $form['info'] = array(
    '#type' => 'item',
    '#markup' => '<p>' . t('View logs at <a href="@url">Admin > Reports > AI Search Block Logs</a>', array(
      '@url' => url('admin/reports/search-api-ai-search-block-log')
    )) . '</p>',
  );

  return system_settings_form($form);
}

/**
 * Form builder for editing a log entry.
 */
function search_api_ai_search_block_log_edit_form($form, &$form_state, $log) {
  if (!$log) {
    backdrop_not_found();
    return;
  }

  backdrop_set_title(t('Edit Log Entry #@id', array('@id' => $log->id)));

  $form['log_id'] = array(
    '#type' => 'value',
    '#value' => $log->id,
  );

  $form['info'] = array(
    '#type' => 'fieldset',
    '#title' => t('Log Information'),
    '#collapsible' => FALSE,
  );

  $user = user_load($log->uid);
  $username = $user ? $user->name : t('Anonymous');

  // Format expiration date for display
  $expiration_display = t('Never');
  if (!empty($log->expiration)) {
    $expiration_display = format_date($log->expiration, 'long');
    if ($log->expiration < REQUEST_TIME) {
      $expiration_display = '<span style="color: red;">' . t('Expired: ') . $expiration_display . '</span>';
    }
  }

  $form['info']['display'] = array(
    '#markup' => '<p>' . t('Created: @date<br>User: @user<br>Block: @block<br>Current Expiration: !expiration', array(
      '@date' => format_date($log->created, 'long'),
      '@user' => $username,
      '@block' => check_plain($log->block_id),
      '!expiration' => $expiration_display,
    )) . '</p>',
  );

  $form['query'] = array(
    '#type' => 'textarea',
    '#title' => t('Query'),
    '#default_value' => $log->query,
    '#rows' => 3,
    '#required' => TRUE,
  );

  $form['response'] = array(
    '#type' => 'textarea',
    '#title' => t('Response'),
    '#default_value' => $log->response,
    '#rows' => 10,
    '#required' => TRUE,
  );

  $form['score'] = array(
    '#type' => 'select',
    '#title' => t('Score'),
    '#options' => array(
      '' => t('- Not rated -'),
      1 => '1',
      2 => '2',
      3 => '3',
      4 => '4',
      5 => '5',
    ),
    '#default_value' => $log->score,
  );

  $form['feedback'] = array(
    '#type' => 'textarea',
    '#title' => t('User Feedback'),
    '#description' => t('Optional detailed feedback from the user about this response.'),
    '#default_value' => isset($log->feedback) ? $log->feedback : '',
    '#rows' => 4,
  );

  $form['expiration_settings'] = array(
    '#type' => 'fieldset',
    '#title' => t('Expiration Settings'),
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
  );

  $form['expiration_settings']['expiration_type'] = array(
    '#type' => 'radios',
    '#title' => t('Expiration'),
    '#options' => array(
      'keep' => t('Keep current expiration date'),
      'never' => t('Never expire'),
      'custom' => t('Set custom expiration date'),
    ),
    '#default_value' => 'keep',
  );

  // Get available retention options
  $retention_options = array(
    7 => t('1 week from now'),
    14 => t('2 weeks from now'),
    30 => t('1 month from now'),
    90 => t('3 months from now'),
    180 => t('6 months from now'),
    365 => t('1 year from now'),
  );

  $form['expiration_settings']['expiration_custom'] = array(
    '#type' => 'select',
    '#title' => t('Expiration period'),
    '#options' => $retention_options,
    '#default_value' => 30,
    '#states' => array(
      'visible' => array(
        ':input[name="expiration_type"]' => array('value' => 'custom'),
      ),
    ),
  );

  $form['actions'] = array('#type' => 'actions');
  $form['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save'),
  );
  $form['actions']['cancel'] = array(
    '#type' => 'link',
    '#title' => t('Cancel'),
    '#href' => 'admin/reports/search-api-ai-search-block-log/' . $log->id,
  );

  return $form;
}

/**
 * Submit handler for log entry edit form.
 */
function search_api_ai_search_block_log_edit_form_submit($form, &$form_state) {
  $log_id = $form_state['values']['log_id'];
  
  // Base fields to update
  $fields = array(
    'query' => $form_state['values']['query'],
    'response' => $form_state['values']['response'],
    'score' => $form_state['values']['score'] ? (int) $form_state['values']['score'] : NULL,
    'feedback' => $form_state['values']['feedback'],
  );

  // Handle expiration based on selection
  switch ($form_state['values']['expiration_type']) {
    case 'never':
      $fields['expiration'] = NULL;
      break;
    
    case 'custom':
      $days = (int) $form_state['values']['expiration_custom'];
      $fields['expiration'] = REQUEST_TIME + ($days * 86400);
      break;
    
    // 'keep' - don't update expiration field
  }

  $helper = search_api_ai_search_block_log_get_helper();
  $helper->update($log_id, $fields);

  backdrop_set_message(t('Log entry has been updated.'));
  $form_state['redirect'] = 'admin/reports/search-api-ai-search-block-log/' . $log_id;
}

/**
 * Form builder for deleting a log entry.
 */
function search_api_ai_search_block_log_delete_form($form, &$form_state, $log) {
  if (!$log) {
    backdrop_not_found();
    return;
  }

  $form['log_id'] = array(
    '#type' => 'value',
    '#value' => $log->id,
  );

  return confirm_form(
    $form,
    t('Are you sure you want to delete log entry #@id?', array('@id' => $log->id)),
    'admin/reports/search-api-ai-search-block-log/' . $log->id,
    t('This action cannot be undone.'),
    t('Delete'),
    t('Cancel')
  );
}

/**
 * Submit handler for log entry delete form.
 */
function search_api_ai_search_block_log_delete_form_submit($form, &$form_state) {
  $log_id = $form_state['values']['log_id'];

  db_delete('search_api_ai_search_block_log')
    ->condition('id', $log_id)
    ->execute();

  backdrop_set_message(t('Log entry #@id has been deleted.', array('@id' => $log_id)));
  $form_state['redirect'] = 'admin/reports/search-api-ai-search-block-log';
}

