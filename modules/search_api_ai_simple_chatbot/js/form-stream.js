(function ($, Backdrop) {

  'use strict';

  // ✅ once() polyfill for Backdrop
  function once(id, $elements) {
    return $elements.filter(function () {
      const alreadyProcessed = $(this).data('once-' + id);
      if (alreadyProcessed) return false;
      $(this).data('once-' + id, true);
      return true;
    });
  }

  Backdrop.behaviors.searchApiAiStream = {
    attach: function (context) {
      const streamElements = $('[data-search-api-ai-ajax]', context);

      once('data-streamed', streamElements).each(function () {
        const element = $(this);
        const form = element.closest('form');

        form.find('.chat-form-query').on('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.find('.chat-form-send').trigger('click');
          }
        });

        element.on('click', function (event) {
          event.preventDefault();

          const clickedElement = $(event.currentTarget);
          const responseField = $('#' + clickedElement.attr('data-search-api-ai-ajax'));
          let data = form.serializeArray();

          data.push({
            name: event.currentTarget.name,
            value: event.currentTarget.value
          });

          $.ajax({
            url: form.attr('action'),
            method: 'POST',
            data: data,
            xhrFields: {
              onprogress: function (event) {
                if (responseField.length && responseField[0]) {
                  responseField.html(event.currentTarget.response.replaceAll("\n", "<br />"));
                  responseField.scrollTop(responseField[0].scrollHeight);
                }
              }
            }
          });
        });
      });
    }
  };

})(jQuery, Backdrop);

(function ($, Backdrop) {
  'use strict';

  Backdrop.behaviors.searchApiAiEnterSubmit = {
    attach: function (context) {
      $('.chat-form-query', context).each(function () {
        const $textarea = $(this);

        if ($textarea.hasClass('enter-submit-attached')) return;
        $textarea.addClass('enter-submit-attached');

        $textarea.on('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();

            // Trigger mousedown instead of click for Backdrop AJAX compatibility
            const $sendButton = $textarea.closest('form').find('.chat-form-send');
            $sendButton.trigger('mousedown');
          }
        });
      });
    }
  };

})(jQuery, Backdrop);

(function ($, Backdrop) {
  'use strict';

  Backdrop.behaviors.chatToggleBehavior = {
    attach: function (context) {
      $('.chat-toggle-button', context).once('chat-toggle').on('click', function () {
        const $button = $(this);
        const $block = $button.closest('.block-search-api-ai-simple-chatbot-search-api-ai-chat-form');

        // Just toggle the class, don’t use slideToggle.
        $block.toggleClass('collapsed');

        const isCollapsed = $block.hasClass('collapsed');
        $button.text(isCollapsed ? '💬 Open Chat' : '💬 Chat');
      });
    }
  };
})(jQuery, Backdrop);



