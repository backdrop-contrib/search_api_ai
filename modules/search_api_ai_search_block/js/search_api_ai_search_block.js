/**
 * File: js/search_api_ai_search_block.js
 * Tied to module: search_api_ai_search_block
 */
(function ($, Backdrop, settings) {
  'use strict';

  // Resolve settings (no fallback).
  function getCfg() {
    return (settings && settings.search_api_ai_search_block) || {};
  }

  // Optional Markdown renderer.
  function renderMarkdown(md) {
    try {
      var cfg = getCfg();
      if (cfg.render_markdown && window.marked && window.DOMPurify) {
        marked.setOptions({ mangle: false, headerIds: false, breaks: true });
        return DOMPurify.sanitize(marked.parse(md || ''), { USE_PROFILES: { html: true } });
      }
    } catch (e) {}
    return md;
  }

  // Client-side streaming animation (like the chatbot)
  function streamText($el, text, speed, done) {
    $el.html('');
    var i = 0;
    var words = text.split(' ');

    (function next() {
      if (i < words.length) {
        var slice = words.slice(0, i + 1).join(' ');
        $el.html(renderMarkdown(slice));
        i++;
        setTimeout(next, speed || 50);
      } else if (typeof done === 'function') {
        done();
      }
    })();
  }

  // Status event dispatcher.
  function dispatchStatusEvent(status, extra) {
    try {
      var evt = new CustomEvent('ai-search-status', {
        detail: $.extend({ status: status }, extra || {})
      });
      document.dispatchEvent(evt);
    } catch (e) {}
  }

  // Ensure output wrapper / region exists.
  function ensureOutputContainer(context) {
    var $wrap = $('#search-api-ai-search-block-response', context);
    if (!$wrap.length) $wrap = $('#search-api-ai-search-block-response');
    if (!$wrap.length) {
      console.warn('[search_api_ai_search_block] response wrapper not found');
      return { $wrap: $(), $out: $(), $suffix: $(), $dbResults: $() };
    }
    var $out = $wrap.find('.search-api-ai-search-block-output');
    if (!$out.length) {
      $out = $('<div class="search-api-ai-search-block-output"></div>').appendTo($wrap);
    }
    var $suffix = $wrap.find('.suffix_text');
    if ($suffix.length) $suffix.hide();

    // NEW: Ensure DB results container exists BEFORE suffix_text
    var $dbResults = $('#ai-search-block-db-results');
    if (!$dbResults.length) {
      $dbResults = $('<div id="ai-search-block-db-results" class="ai-db-results"></div>');
      // Insert before suffix_text so it stays inside the response container
      if ($suffix.length) {
        $suffix.before($dbResults);
      } else {
        $wrap.append($dbResults);
      }
    }

    return { $wrap: $wrap, $out: $out, $suffix: $suffix, $dbResults: $dbResults };
  }

  // NEW: Fetch and display database results
  function fetchDbResults(query, blockId, page, doneCallback) {
    var cfg = getCfg();

    if (!cfg.enable_database_results) {
      return;
    }

    page = page || 0;
    var dbResultsUrl = cfg.db_results_url || '/ai-search/db-results';
    var $dbResults = $('#ai-search-block-db-results');

    if (!$dbResults.length) {
      console.warn('AI Search: DB results container not found');
      return;
    }

    $dbResults.html('<p class="loading_text">Loading database results...</p>');

    $.ajax({
      url: dbResultsUrl,
      type: 'POST',
      dataType: 'json',
      data: JSON.stringify({
        query: query,
        block_id: blockId,
        page: page
      }),
      contentType: 'application/json',
      success: function (data) {

        if (data && data.html) {
          $dbResults.html(data.html);

          // Hide Views exposed form if present
          $dbResults.find('.views-exposed-form').hide();

          // Reattach Backdrop behaviors
          Backdrop.attachBehaviors($dbResults[0]);

          // Handle pager clicks
          $dbResults.off('click.aiPager').on('click.aiPager', '.pager a, .pagination a', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var href = $(this).attr('href') || '';
            var pageMatch = href.match(/page=(\d+)/);
            var nextPage = pageMatch ? parseInt(pageMatch[1], 10) : 0;

            fetchDbResults(query, blockId, nextPage);

            // Scroll to results
            $('html, body').animate({
              scrollTop: $dbResults.offset().top - 100
            }, 300);

            return false;
          });

          // Call the done callback if provided
          if (typeof doneCallback === 'function') {
            doneCallback();
          }
        } else {
          $dbResults.html('<p>No database results found.</p>');
          if (typeof doneCallback === 'function') {
            doneCallback();
          }
        }
      },
      error: function (jqXHR, textStatus, errorThrown) {
        console.error('AI Search: DB results error:', textStatus, errorThrown);
        $dbResults.html('<p>Error loading database results.</p>');
        if (typeof doneCallback === 'function') {
          doneCallback();
        }
      }
    });
  }

  Backdrop.behaviors.searchApiAiSearchBlock = {
    attach: function (context) {
      var $forms = $('.search-api-ai-search-block-form', context);

      // DEBUG: Log settings
      var cfg = getCfg();

      // once() compatibility
      if ($.fn.once) {
        $forms = $forms.once('searchApiAiSearchBlock');
      } else {
        $forms = $forms.filter(function () {
          if ($(this).data('searchApiAiSearchBlockBound')) return false;
          $(this).data('searchApiAiSearchBlockBound', true);
          return true;
        });
      }
      if (!$forms.length) return;

      var cfg           = getCfg();
      var submitUrl     = cfg.submit_url   || '/ai-search/submit';
      var loadingText   = cfg.loading_text || 'Loading…';
      var suffixText    = cfg.suffix_text  || '';
      var streamDefault = !!cfg.stream;

      $forms.each(function () {
        var $form   = $(this);
        var regions = ensureOutputContainer(context);
        var $out    = regions.$out;
        var $suffix = regions.$suffix;
        var $dbResults = regions.$dbResults;

        $form.on('submit', function (e) {
          e.preventDefault();
          e.stopPropagation();

          var query  = ($form.find('input[name="query"], textarea[name="query"]').val() || '').trim();
          var stream = (String($form.find('input[name="stream"]').val()).toLowerCase() === 'true') && streamDefault;
          var $btn   = $form.find('input[type="submit"], button[type="submit"]').first();
          var blockId = $form.find('input[name="block_id"]').val() || '';

          if (!query) return false;


          $btn.prop('disabled', true);
          $out.html('<p class="loading_text"><span class="loader"></span>' + loadingText + '</p>');
          if ($suffix.length) $suffix.hide().empty();
          $dbResults.empty();

          dispatchStatusEvent('loading', { form: $form[0] });

          // Non-streaming fallback.
          $.ajax({
            url: submitUrl,
            type: 'POST',
            dataType: 'json',
            data: { query: query, stream: 0, block_id: blockId },
            success: function (data) {
              //  cfg.enable_database_results);

              if (data && data.response) {
                // Animate response text word by word with slower speed (150ms per word)
                streamText($out, data.response, 150, function () {
                  // Don't show suffix yet if we're fetching DB results
                  if (!cfg.enable_database_results && $suffix.length && suffixText) {
                    $suffix.html(suffixText).show();
                  }

                  //  complete');
                  //    DB results');
                  //     cfg.enable_database_results);

                  // NEW: Fetch database results after animation completes
                  if (cfg.enable_database_results && query) {
                    // Pass the suffix elements so DB results can show it when done
                    fetchDbResults(query, blockId, 0, function() {
                      // Show suffix after DB results are loaded
                      if ($suffix.length && suffixText) {
                        $suffix.html(suffixText).show();
                      }
                    });
                  }
                });
              } else {
                $out.html('<p>No response.</p>');
              }
              dispatchStatusEvent('done', { response: data, form: $form[0] });
            },
            error: function (xhr) {
              $out.html('<p>An error occurred.</p>');
              dispatchStatusEvent('error', { status: xhr.status, form: $form[0] });
            },
            complete: function () {
              $btn.prop('disabled', false);
            }
          });

          return false;
        });
      });
    }
  };
})(jQuery, Backdrop, Backdrop.settings);
