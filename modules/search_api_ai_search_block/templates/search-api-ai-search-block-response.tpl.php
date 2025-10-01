<?php
/**
 * @file
 * Template for the AI Search response container.
 *
 * Variables:
 * - $output        string HTML for the AI answer
 * - $rendered_form render array OR string for the form
 * - $suffix_text   string HTML shown under the answer (optional)
 */
?>
<div id="search-api-ai-search-block-response"
     class="search-api-ai-search-block-response">

  <?php if (!empty($rendered_form)): ?>
    <div class="search-api-ai-search-block-form-wrap">
      <?php
      // If the form is a render array, render it; if it's already HTML, print it.
      if (is_array($rendered_form)) {
        print backdrop_render($rendered_form);
      }
      else {
        print $rendered_form;
      }
      ?>
    </div>
  <?php endif; ?>

  <!-- IMPORTANT: hyphens, not underscores, so JS can find it -->
  <div class="search-api-ai-search-block-output">
    <?php print isset($output) ? $output : ''; ?>
  </div>

  <!-- Database results container will be inserted here by JavaScript -->

  <!-- Suffix appears after everything -->
  <?php if (!empty($suffix_text)): ?>
    <div class="suffix_text"><?php print $suffix_text; ?></div>
  <?php else: ?>
    <div class="suffix_text" style="display:none"></div>
  <?php endif; ?>
</div>
