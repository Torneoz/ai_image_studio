<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

use GuzzleHttp\Exception\RequestException;

/**
 * Extracts bounded diagnostics without logging requests or credentials.
 */
final class GenerationDiagnostics {

  /**
   * Redacts credentials, URLs (including signed assets), and inline media.
   */
  public static function sanitize(string $text): string {
    $text = preg_replace('~data:[^\s"\']+~i', '[inline media omitted]', $text);
    $text = preg_replace('~https?://[^\s<>"\']+~i', '[URL omitted]', $text);
    $text = preg_replace('~\bBearer\s+\S+~i', 'Bearer [redacted]', $text);
    $text = preg_replace('~\b(?:xai-|sk-)[a-zA-Z0-9_-]+~', '[redacted]', $text);
    $text = preg_replace('~(api[_ -]?key|authorization|token|secret)\s*[=:]\s*["\']?[^\s,"\'}]+~i', '$1=[redacted]', $text);
    return mb_substr(strip_tags($text), 0, 4000);
  }

  /**
   * Inspects wrapped Guzzle failures; never stores raw bodies or headers.
   */
  public static function fromException(\Throwable $exception): array {
    $diagnostic = ['exception_class' => get_class($exception), 'message' => self::sanitize($exception->getMessage())];
    for ($error = $exception, $depth = 0; $error && $depth < 12; $error = $error->getPrevious(), $depth++) {
      if (!$error instanceof RequestException || !$response = $error->getResponse()) {
        continue;
      }
      $diagnostic['http_status'] = $response->getStatusCode();
      $body = $response->getBody();
      // Do not consume non-seekable streams or load arbitrary-sized responses.
      if ($body->isSeekable()) {
        try {
          $position = $body->tell();
          $body->rewind();
          $json = json_decode($body->read(16384), TRUE);
          $body->seek($position);
          $detail = $json['error'] ?? $json['detail'] ?? $json['message'] ?? NULL;
          if (is_array($detail)) {
            foreach (['code', 'type', 'param'] as $key) {
              if (isset($detail[$key]) && is_scalar($detail[$key])) {
                $diagnostic['provider_' . $key] = self::sanitize((string) $detail[$key]);
              }
            }
            $detail = $detail['message'] ?? NULL;
          }
          if (is_string($detail) && trim($detail) !== '') {
            $diagnostic['message'] .= ' Provider detail: ' . self::sanitize($detail);
          }
        }
        catch (\Throwable) {
          // A closed/unreadable body must not hide the original failure.
        }
      }
      break;
    }
    $diagnostic['message'] = mb_substr($diagnostic['message'], 0, 4000);
    return $diagnostic;
  }

}
