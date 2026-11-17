<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use GuzzleHttp\ClientInterface;

/**
 * Loads a stable narration-text snapshot from an approved source.
 */
final class NarrationSourceLoader {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Loads readable text from a public, non-redirecting HTTP(S) URL.
   *
   * @return array{title: string, text: string}
   */
  public function loadUrl(string $url): array {
    $this->assertPublicHttpUrl($url);
    $response = $this->httpClient->request('GET', $url, [
      'allow_redirects' => FALSE,
      'connect_timeout' => 5,
      'timeout' => 15,
      'stream' => TRUE,
      'headers' => ['Accept' => 'text/html, text/plain;q=0.9'],
    ]);
    $contentType = strtolower($response->getHeaderLine('Content-Type'));
    if ($contentType !== '' && !str_starts_with($contentType, 'text/html') && !str_starts_with($contentType, 'text/plain') && !str_starts_with($contentType, 'application/xhtml+xml')) {
      throw new \RuntimeException('The remote URL did not return HTML or plain text.');
    }
    if ((int) $response->getHeaderLine('Content-Length') > 1048576) {
      throw new \RuntimeException('The remote page is larger than the 1 MB source-loading limit.');
    }
    $stream = $response->getBody();
    $html = '';
    while (!$stream->eof()) {
      $html .= $stream->read(8192);
      if (strlen($html) > 1048576) {
        throw new \RuntimeException('The remote page is larger than the 1 MB source-loading limit.');
      }
    }
    return $this->extractHtml($html, $url);
  }

  /**
   * Loads readable fields from a local content entity the user can view.
   *
   * @return array{title: string, text: string}
   */
  public function loadEntity(string $entityTypeId, int $entityId): array {
    $definition = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    if (!$definition || $definition->getGroup() !== 'content') {
      throw new \RuntimeException('Choose a valid local content entity type.');
    }
    $entity = $this->entityTypeManager->getStorage($entityTypeId)->load($entityId);
    if (!$entity || !$entity->access('view', $this->currentUser)) {
      throw new \RuntimeException('The selected local entity is unavailable or cannot be viewed.');
    }
    $values = [];
    foreach ($entity->getFieldDefinitions() as $name => $fieldDefinition) {
      if ($fieldDefinition->isComputed() || $fieldDefinition->getFieldStorageDefinition()->isBaseField() && in_array($name, ['uuid', 'langcode', 'revision_id', 'vid', 'uid', 'created', 'changed', 'status'], TRUE)) {
        continue;
      }
      $type = $fieldDefinition->getType();
      if (in_array($type, ['entity_reference', 'entity_reference_revisions', 'file', 'image'], TRUE)) {
        continue;
      }
      foreach ($entity->get($name) as $item) {
        $value = $item->getValue();
        $text = $value['value'] ?? $value['summary'] ?? $value['uri'] ?? NULL;
        if (is_scalar($text) && trim((string) $text) !== '') {
          $values[] = trim(strip_tags((string) $text));
        }
      }
    }
    $text = $this->normaliseText(implode("\n\n", array_unique($values)));
    if ($text === '') {
      throw new \RuntimeException('The selected entity has no readable text fields to narrate.');
    }
    return ['title' => (string) $entity->label(), 'text' => $text];
  }

  /** @return array{title: string, text: string} */
  private function extractHtml(string $html, string $url): array {
    if (trim($html) === '') {
      throw new \RuntimeException('The remote page was empty.');
    }
    libxml_use_internal_errors(TRUE);
    $document = new \DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
    $xpath = new \DOMXPath($document);
    foreach ($xpath->query('//script|//style|//noscript|//svg|//nav|//footer|//header|//aside|//form') as $node) {
      $node->parentNode?->removeChild($node);
    }
    $title = trim((string) $xpath->evaluate('string(//h1[1] | //title[1])'));
    $content = $xpath->query('//article[1] | //main[1] | //body[1]')->item(0);
    $text = $this->normaliseText($content ? (string) $content->textContent : '');
    if ($text === '') {
      throw new \RuntimeException('No readable narration text could be found at the remote URL.');
    }
    return ['title' => $title !== '' ? $title : $url, 'text' => $text];
  }

  private function assertPublicHttpUrl(string $url): void {
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], TRUE) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
      throw new \RuntimeException('Use a public HTTP or HTTPS URL.');
    }
    if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
      throw new \RuntimeException('Private or local URLs cannot be loaded.');
    }
    $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if ($addresses === []) {
      throw new \RuntimeException('The remote URL host could not be resolved.');
    }
    foreach ($addresses as $address) {
      if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        throw new \RuntimeException('Private or reserved network addresses cannot be loaded.');
      }
    }
  }

  private function normaliseText(string $text): string {
    return trim((string) preg_replace('/[ \t]+/u', ' ', (string) preg_replace('/\R{3,}/u', "\n\n", html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
  }

}
