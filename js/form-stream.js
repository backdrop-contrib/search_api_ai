(function ($, Drupal, drupalSettings) {

  'use strict';

  Drupal.behaviors.searchApiAiStream = {
    attach: (context) => {

      let streamElements = $('[data-search-api-ai-ajax]', context);
      once('data-streamed', streamElements).forEach((item) => {
        const element = $(item);
        const form = element.closest('form');

        // Set up a key down handler to submit the form on Enter press in textarea.
        form.find('.chat-form-query').on('keydown', (event) => {
          if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.find('.chat-form-send').click();
          }
        });

        // Set up a click handler to submit the form and stream the response back.
        element.click((event) => {
          event.preventDefault();

          const clickedElement = $(event.currentTarget);
          const responseField = $('#' + clickedElement.attr('data-search-api-ai-ajax'));
          let data = form.serializeArray();

          // Push an event for the current submission.
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
                responseField.html(event.currentTarget.response.replaceAll("\n", "<br />"));
                responseField.scrollTop(responseField[0].scrollHeight);
              }
            }
          });
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
