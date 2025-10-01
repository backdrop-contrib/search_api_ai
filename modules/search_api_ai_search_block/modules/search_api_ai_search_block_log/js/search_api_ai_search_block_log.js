(function ($, Backdrop) {
  'use strict';

  Backdrop.behaviors.searchApiAiSearchBlockLog = {
    attach: function (context, settings) {
      var $suffix_text = $('#search-api-ai-search-block-response .suffix_text');
      
      $('.search_api_ai_search_block_log_score', context).once('ai-log-score').each(function () {
        $(this).on('click', function () {
          var $score = $(this).data('aiSearchBlockLogScore');
          var $logId = null;
          
          // Get log ID from Backdrop settings
          if (typeof Backdrop.settings.search_api_ai_search_block !== 'undefined' && 
              typeof Backdrop.settings.search_api_ai_search_block.logId !== 'undefined') {
            $logId = Backdrop.settings.search_api_ai_search_block.logId;
          }
          
          if (!$logId) {
            console.error('No log ID found');
            return;
          }
          
          // Send score to server
          $.post('/search-api-ai-search-block-log/score', {
            log_id: $logId,
            score: $score
          })
          .done(function (data) {
            if (data && data.response) {
              $suffix_text.html(data.response);
              Backdrop.attachBehaviors($suffix_text[0]);
              $suffix_text.show();
            }
          })
          .fail(function () {
            console.error('Failed to submit score');
          });
        });
      });
    }
  };
})(jQuery, Backdrop);

