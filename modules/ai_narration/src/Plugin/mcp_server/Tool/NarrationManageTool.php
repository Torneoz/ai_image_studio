<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

#[Tool(id: 'ai_narration_manage', label: new TranslatableMarkup('Manage AI Narration'), description: new TranslatableMarkup('Lists, creates, and updates narration projects and chapters, or generates audio for a saved chapter.'), module_dependencies: ['ai_narration'], inputSchema: ['type' => 'object', 'properties' => ['operation' => ['type' => 'string', 'enum' => ['list_projects', 'save_project', 'list_chapters', 'save_chapter', 'generate_chapter']], 'id' => ['type' => 'integer'], 'project_id' => ['type' => 'integer'], 'chapter_id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'model' => ['type' => 'string'], 'voice' => ['type' => 'string'], 'format' => ['type' => 'string'], 'language' => ['type' => 'string'], 'speed' => ['type' => 'number'], 'max_input_characters' => ['type' => 'integer'], 'position' => ['type' => 'integer'], 'text' => ['type' => 'string']], 'required' => ['operation']], readOnly: FALSE, destructive: FALSE, idempotent: FALSE, openWorld: TRUE)]
final class NarrationManageTool extends NarrationMcpToolBase {

  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return match ($arguments['operation'] ?? '') {
      'list_projects' => $this->listProjects(),
      'save_project' => $this->saveProject($arguments),
      'list_chapters' => $this->listChapters($arguments),
      'save_chapter' => $this->saveChapter($arguments),
      'generate_chapter' => $this->generateChapter($arguments),
      default => throw new \InvalidArgumentException('operation must be list_projects, save_project, list_chapters, save_chapter, or generate_chapter.'),
    };
  }

  private function listProjects(): array {
    return ['projects' => array_map(fn ($project) => $this->projectData($project), $this->entityTypeManager->getStorage('ai_narration_project')->loadMultiple())];
  }

  private function saveProject(array $input): array {
    if (empty($input['title'])) { throw new \InvalidArgumentException('title is required to save a project.'); }
    $storage = $this->entityTypeManager->getStorage('ai_narration_project');
    $project = !empty($input['id']) ? $storage->load((int) $input['id']) : $storage->create(['uid' => $this->currentUser->id()]);
    if (!$project) { throw new \InvalidArgumentException('Narration project not found.'); }
    foreach (['title', 'model', 'voice', 'format', 'language', 'speed', 'max_input_characters'] as $field) { if (array_key_exists($field, $input)) { $project->set($field, $input[$field]); } }
    $project->save();
    return ['project' => $this->projectData($project)];
  }

  private function listChapters(array $input): array {
    $chapters = $this->entityTypeManager->getStorage('ai_narration_chapter')->loadMultiple();
    if (!empty($input['project_id'])) { $chapters = array_filter($chapters, fn ($chapter) => (int) $chapter->get('project_id')->target_id === (int) $input['project_id']); }
    return ['chapters' => array_map(fn ($chapter) => $this->chapterData($chapter), $chapters)];
  }

  private function saveChapter(array $input): array {
    foreach (['project_id', 'title', 'text'] as $field) { if (empty($input[$field])) { throw new \InvalidArgumentException("$field is required to save a chapter."); } }
    $storage = $this->entityTypeManager->getStorage('ai_narration_chapter');
    $chapter = !empty($input['id']) ? $storage->load((int) $input['id']) : $storage->create(['status' => 'draft']);
    if (!$chapter) { throw new \InvalidArgumentException('Narration chapter not found.'); }
    foreach (['project_id', 'title', 'position', 'text'] as $field) { if (array_key_exists($field, $input)) { $chapter->set($field, $input[$field]); } }
    $chapter->save();
    return ['chapter' => $this->chapterData($chapter)];
  }

  private function generateChapter(array $input): array {
    $chapter = $this->entityTypeManager->getStorage('ai_narration_chapter')->load((int) ($input['chapter_id'] ?? 0));
    if (!$chapter) { throw new \InvalidArgumentException('chapter_id must identify a saved narration chapter.'); }
    $project = $chapter->get('project_id')->entity;
    if (!$project || !(string) $project->get('model')->value) { throw new \RuntimeException('The chapter project must have a text-to-speech model.'); }
    $text = (string) $chapter->get('text')->value;
    $limit = (int) $project->get('max_input_characters')->value;
    if ($limit > 0 && mb_strlen($text) > $limit) { throw new \RuntimeException('Chapter text exceeds the project input-character limit.'); }
    $started = microtime(TRUE);
    $file = $this->generator->generate($text, (string) $project->get('model')->value, (string) $project->get('voice')->value, (string) $project->get('format')->value, ['language' => (string) $project->get('language')->value, 'speed' => $project->get('speed')->value ?: 1, 'text_normalization' => (bool) $project->get('text_normalization')->value], (string) $chapter->label());
    $duration = $this->durationInspector->getDuration($file);
    $seconds = microtime(TRUE) - $started;
    $chapter->get('audio_files')->appendItem(['target_id' => $file->id()]);
    $chapter->set('last_generated_audio', ['target_id' => $file->id()]);
    $chapter->set('generation_metadata', json_encode(['model' => (string) $project->get('model')->value, 'voice' => (string) $project->get('voice')->value, 'format' => (string) $project->get('format')->value, 'input_characters' => mb_strlen($text), 'file_id' => (int) $file->id(), 'bytes' => (int) $file->getSize(), 'mime' => $file->getMimeType(), 'runtime_seconds' => $duration, 'generation_seconds' => $seconds, 'generated_at' => gmdate(DATE_ATOM)], JSON_UNESCAPED_SLASHES));
    $chapter->set('status', 'complete');
    $chapter->set('error_message', '');
    $chapter->save();
    return ['chapter' => $this->chapterData($chapter), 'audio' => ['file_id' => (int) $file->id(), 'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()), 'mime' => $file->getMimeType(), 'bytes' => $file->getSize(), 'runtime_seconds' => $duration, 'generation_seconds' => $seconds]];
  }

}
