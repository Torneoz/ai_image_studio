<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Service;

/**
 * Serializes saved script text into common editable interchange formats.
 */
final class ScriptExporter {

  public const FORMATS = [
    'json' => 'JSON (project and script)',
    'fountain' => 'Fountain (.fountain)',
    'fdx' => 'Final Draft (.fdx)',
    'osf' => 'Open Screenplay Format (.osf)',
    'rtf' => 'Rich Text Format (.rtf)',
    'txt' => 'Original script (.txt)',
  ];

  /**
   * Recognizes basic screenplay lines without inventing missing content.
   */
  public static function paragraphs(string $script): array {
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $script));
    $result = [];
    $dialogue = FALSE;
    foreach ($lines as $index => $line) {
      $text = trim($line);
      if ($text === '') {
        $dialogue = FALSE;
        continue;
      }
      $type = 'Action';
      if (preg_match('/^(?:INT\.?\/EXT\.?|I\/E\.?|INT\.|EXT\.|EST\.)\s/iu', $text)) {
        $type = 'Scene Heading';
        $dialogue = FALSE;
      }
      elseif (str_starts_with($text, '.') && !str_starts_with($text, '..')) {
        $type = 'Scene Heading';
        $text = substr($text, 1);
        $dialogue = FALSE;
      }
      elseif (preg_match('/^(?:FADE OUT\.|FADE IN:|CUT TO:|DISSOLVE TO:|.* TO:)$/u', $text)) {
        $type = 'Transition';
        $dialogue = FALSE;
      }
      elseif (str_starts_with($text, '>') && !str_ends_with($text, '<')) {
        $type = 'Transition';
        $text = ltrim(substr($text, 1));
        $dialogue = FALSE;
      }
      elseif (str_starts_with($text, '!')) {
        $text = substr($text, 1);
        $dialogue = FALSE;
      }
      elseif (str_starts_with($text, '@') || (!$dialogue
        && ($index === 0 || trim($lines[$index - 1]) === '')
        && trim($lines[$index + 1] ?? '') !== ''
        && preg_match('/^[\p{Lu}\p{N}][\p{Lu}\p{N} .\x{2019}\x{0027}()\/-]{0,59}$/u', $text))) {
        $type = 'Character';
        $text = ltrim($text, '@');
        $dialogue = TRUE;
      }
      elseif ($dialogue) {
        $type = str_starts_with($text, '(') && str_ends_with($text, ')') ? 'Parenthetical' : 'Dialogue';
      }
      $result[] = ['type' => $type, 'text' => $text];
    }
    return $result;
  }

  /**
   * Exports content. JSON is an AIIS snapshot, not a promised import format.
   */
  public static function render(string $format, string $title, string $script, array $snapshot = []): string {
    if (!isset(self::FORMATS[$format])) {
      throw new \InvalidArgumentException('Unsupported script export format.');
    }
    if ($format === 'txt') {
      return $script;
    }
    $paragraphs = self::paragraphs($script);
    if ($format === 'json') {
      return json_encode([
        'format' => 'ai_storyboard.script',
        'version' => 1,
        'title' => $title,
        'script' => $script,
        'paragraphs' => $paragraphs,
        'snapshot' => $snapshot,
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
    if ($format === 'fountain') {
      $result = '';
      foreach ($paragraphs as $paragraph) {
        $text = str_replace(['\\', '*', '_', '[', ']'], ['\\\\', '\\*', '\\_', '\\[', '\\]'], $paragraph['text']);
        $prefix = match ($paragraph['type']) {
          'Scene Heading' => '.', 'Character' => '@',
          'Transition' => '>', 'Action' => '!', default => '',
        };
        $result .= (in_array($paragraph['type'], ['Dialogue', 'Parenthetical'], TRUE) ? '' : "\n") . $prefix . $text . "\n";
      }
      return ltrim($result, "\n");
    }
    if ($format === 'rtf') {
      $escape = static function (string $text): string {
        $result = '';
        foreach (mb_str_split($text) as $char) {
          if (mb_ord($char) < 128) {
            $result .= in_array($char, ['\\', '{', '}'], TRUE) ? '\\' . $char : $char;
          }
          else {
            foreach (unpack('n*', mb_convert_encoding($char, 'UTF-16BE', 'UTF-8')) as $unit) {
              $result .= '\\u' . ($unit > 32767 ? $unit - 65536 : $unit) . '?';
            }
          }
        }
        return $result;
      };
      $result = '{\\rtf1\\ansi\\deff0\\uc1{\\fonttbl{\\f0 Courier New;}}\\paperw12240\\paperh15840\\margl2160\\margr1440\\f0\\fs24' . "\n";
      foreach ($paragraphs as $paragraph) {
        $layout = match ($paragraph['type']) {
          'Character' => '\\li3600\\keepn',
          'Dialogue' => '\\li2160\\ri2160',
          'Parenthetical' => '\\li2880\\ri2880\\keepn',
          'Transition' => '\\qr', 'Scene Heading' => '\\keepn', default => '',
        };
        $result .= '\\pard' . $layout . ' ' . $escape($paragraph['text']) . "\\par\n";
      }
      return $result . '}';
    }
    $xml = new \DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = TRUE;
    $fdx = $format === 'fdx';
    $root = $xml->appendChild($xml->createElement($fdx ? 'FinalDraft' : 'document'));
    $attributes = $fdx
      ? ['DocumentType' => 'Script', 'Template' => 'No', 'Version' => '1']
      : ['type' => 'Open Screenplay Format document', 'version' => '21'];
    foreach ($attributes as $key => $value) {
      $root->setAttribute($key, $value);
    }
    if (!$fdx) {
      $styles = $root->appendChild($xml->createElement('styles'));
      foreach (['Normal Text', 'Scene Heading', 'Action', 'Character', 'Parenthetical', 'Dialogue', 'Transition'] as $index => $name) {
        $style = $styles->appendChild($xml->createElement('style'));
        foreach ([
          'name' => $name,
          'builtin' => '1',
          'builtInIndex' => (string) $index,
          'font' => 'Courier New',
          'size' => '12',
        ] as $key => $value) {
          $style->setAttribute($key, $value);
        }
        if ($index > 0) {
          $style->setAttribute('baseStyleName', 'Normal Text');
        }
      }
    }
    $content = $root->appendChild($xml->createElement($fdx ? 'Content' : 'paragraphs'));
    foreach ($paragraphs as $paragraph) {
      $node = $content->appendChild($xml->createElement($fdx ? 'Paragraph' : 'para'));
      if ($fdx) {
        $node->setAttribute('Type', $paragraph['type']);
      }
      else {
        $node->appendChild($xml->createElement('style'))->setAttribute('baseStyleName', $paragraph['type']);
      }
      $text = $node->appendChild($xml->createElement($fdx ? 'Text' : 'text'));
      $text->appendChild($xml->createTextNode($paragraph['text']));
    }
    return $xml->saveXML();
  }

}
