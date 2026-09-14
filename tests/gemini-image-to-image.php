<?php

declare(strict_types=1);

/**
 * Static smoke checks for the project-owned Gemini image editing adapter.
 */
$root = dirname(__DIR__);
$provider = file_get_contents($root . '/src/Plugin/AiProvider/GeminiImageProvider.php');
$module = file_get_contents($root . '/ai_image_studio.module');
$generator = file_get_contents($root . '/src/Service/ImageGenerator.php');

$checks = [
  str_contains($provider, 'implements ImageToImageInterface'),
  str_contains($provider, "'image_to_image'"),
  str_contains($provider, 'Blob::from'),
  str_contains($provider, 'ImageToImageOutput'),
  str_contains($module, 'hook_ai_provider_info_alter'),
  str_contains($module, 'GeminiImageProvider::class'),
  str_contains($generator, '$seen_labels'),
  str_contains($generator, '$deduplicated'),
];

if (in_array(FALSE, $checks, TRUE)) {
  fwrite(STDERR, "Gemini image-to-image checks failed.\n");
  exit(1);
}

print "Gemini image-to-image checks passed; no provider requests made.\n";
