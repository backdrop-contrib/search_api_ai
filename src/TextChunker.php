<?php

namespace Drupal\search_api_ai;

/**
 * Utility for chunking text in an NLP friendly manner.
 */
class TextChunker {

  /**
   * Generate chunks from a string.
   *
   * @param string $text
   *   The text to chunk.
   * @param int $maxSize
   *   The maximum size of the chunk (multibyte safe).
   * @param int $minOverlap
   *   The minimum overlap between chunks (multibyte safe).
   *
   * @return string[]
   *   An array of chunks.
   */
  public static function chunkText(string $text, int $maxSize, int $minOverlap): array {
    $remainingText = $text;
    $chunks = [];

    while ($remainingText) {
      $length = mb_strlen($remainingText);
      if ($length <= $maxSize) {
        $chunks[] = $remainingText;
        break;
      }

      // Find out the negative offset to use to achieve the max position.
      $offset = $maxSize - $length;

      // Get a chunk ending with whitespace that is less than our chunk size.
      $chunk = mb_substr(
        $remainingText,
        0,
        // Length will be the position of the last whitespace within the max
        // chunk size, or otherwise the max chunk size itself.
        self::getLastWhitespacePosition($remainingText, $offset) ?? $maxSize,
      );
      $chunks[] = trim($chunk);

      // Remove our chunk, keeping our minimum overlap, again breaking at
      // whitespace.
      $remainingText = mb_substr(
        $remainingText,
        // Length will be the last white space before the minimum overlap, or
        // otherwise the minimum overlap itself.
        self::getLastWhitespacePosition($chunk, -$minOverlap) ?? -$minOverlap,
      );
    }

    // Remove any empty chunks.
    return array_filter($chunks);
  }

  /**
   * Get the position of the last whitespace character with multibyte safety.
   *
   * @param string $haystack
   *   The text to search in.
   * @param int $offset
   *   The offset to search from.
   *
   * @return int|null
   *   The multibyte safe position of the last whitespace character or NULL if
   *   there is no whitespace.
   */
  public static function getLastWhitespacePosition(mixed $haystack, int $offset): ?int {
    $whitespacePosition = max(
      mb_strrpos($haystack, " ", $offset),
      mb_strrpos($haystack, "\n", $offset),
      mb_strrpos($haystack, "\r", $offset),
      mb_strrpos($haystack, "\t", $offset),
    );
    return $whitespacePosition ?: NULL;
  }

}
