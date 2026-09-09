<?php

/**
 * @file
 * Standalone format regression checks; no API requests.
 */

require_once __DIR__ . '/../src/Service/ScriptExporter.php';

use Drupal\ai_storyboard\Service\ScriptExporter;

$check = static function (bool $pass, string $message): void {
  if (!$pass) {
    throw new RuntimeException($message);
  }
  print "PASS: $message\n";
};
$script = "INT. CAFÉ - DAY\r\n\r\nMira holds a cup & smiles <quietly>.\r\n\r\nMIRA\r\n(softly)\r\nHello, 世界 😀 {yes} \\ path.\r\n\r\nCUT TO:\r\n\r\nEXT. ROAD - NIGHT\r\n\r\nShe leaves.";
$paragraphs = ScriptExporter::paragraphs($script);
$check(array_column($paragraphs, 'type') === ['Scene Heading', 'Action', 'Character', 'Parenthetical', 'Dialogue', 'Transition', 'Scene Heading', 'Action'], 'Basic scene/action/character/dialogue/parenthetical/transition typing');
$check(ScriptExporter::render('txt', 'Title', $script) === $script, 'Original text retains exact bytes and line endings');
$json = json_decode(ScriptExporter::render('json', 'Title', $script, ['scene' => ['id' => 7]]), TRUE, 512, JSON_THROW_ON_ERROR);
$check($json['script'] === $script && $json['snapshot']['scene']['id'] === 7 && $json['version'] === 1, 'Versioned JSON retains original script and snapshot');
foreach (['fdx', 'osf'] as $format) {
  $xml = new DOMDocument();
  $check($xml->loadXML(ScriptExporter::render($format, 'Title', $script), LIBXML_NONET), "$format is well-formed XML");
  $xpath = new DOMXPath($xml);
  $nodes = $xpath->query($format === 'fdx' ? '/FinalDraft/Content/Paragraph/Text' : '/document/paragraphs/para/text');
  $check(array_map(static fn ($node) => $node->textContent, iterator_to_array($nodes)) === array_column($paragraphs, 'text'), "$format preserves all paragraph text, Unicode and XML-sensitive characters");
}
$fountain = ScriptExporter::render('fountain', 'Title', $script);
$check(str_contains($fountain, "@MIRA\n(softly)\nHello") && str_contains($fountain, '.INT. CAFÉ'), 'Fountain emits forced character and scene cues with adjacent dialogue');
$rtf = ScriptExporter::render('rtf', 'Title', $script);
$check(str_starts_with($rtf, '{\\rtf1') && str_contains($rtf, '\\u-10179?\\u-8704?') && str_contains($rtf, '\\{yes\\}'), 'RTF escapes braces and supplementary Unicode using signed UTF-16');
$check(ScriptExporter::paragraphs('Ordinary prose stays intact.')[0]['type'] === 'Action', 'Unstructured prose remains action');
foreach (array_keys(ScriptExporter::FORMATS) as $format) {
  $check(is_string(ScriptExporter::render($format, 'Empty', '')), "$format supports a project before breakdown or media generation");
}
try {
  ScriptExporter::render('../secret', 'Title', $script);
  $check(FALSE, 'Unknown format should fail');
}
catch (InvalidArgumentException) {
  $check(TRUE, 'Unsupported formats are rejected');
}
