<?php
/**
 * @file
 * Default theme implementation to display AI search block log.
 *
 * Available variables:
 * - $content: An array of content items. Use render($content) to print them all,
 *   or print a subset such as render($content['field_example']). Use
 *   hide($content['field_example']) to temporarily suppress the printing of a
 *   given element.
 * - $view_mode: View mode; for example, "full", "teaser".
 *
 * @see template_preprocess_ai_search_block_log()
 */
?>
<div class="<?php print $classes; ?> clearfix"<?php print $attributes; ?>>
  <?php
    // Render the content
    print render($content);
  ?>
</div>
