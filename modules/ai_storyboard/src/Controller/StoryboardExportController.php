<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Controller;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\ai_storyboard\Service\ScriptExporter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Exports generated storyboard frames as image collections and animatics.
 */
final class StoryboardExportController extends ControllerBase {

  /**
   * Downloads a saved script independently of generated media.
   */
  public function downloadScript(object $ai_storyboard, string $format): Response {
    if (!isset(ScriptExporter::FORMATS[$format])) {
      throw new NotFoundHttpException('Unknown script export format.');
    }
    $snapshot = [];
    if ($format === 'json') {
      $values = static function (object $entity): array {
        $result = [];
        foreach ($entity->getFields() as $name => $field) {
          if (!$field->getFieldDefinition()->isComputed() && $field->access('view')) {
            $result[$name] = $field->getValue();
          }
        }
        return $result;
      };
      $snapshot['project'] = $values($ai_storyboard);
      foreach (['ai_storyboard_scene' => 'scene_number', 'ai_storyboard_shot' => 'position', 'ai_storyboard_cast' => 'id'] as $type => $sort) {
        $storage = $this->storyboardEntityTypeManager->getStorage($type);
        $ids = $storage->getQuery()->accessCheck(FALSE)->condition('storyboard_id', $ai_storyboard->id())->sort($sort)->sort('id')->execute();
        $snapshot[$type] = [];
        foreach ($storage->loadMultiple($ids) as $entity) {
          if ($entity->access('view')) {
            $snapshot[$type][] = $values($entity);
          }
        }
      }
    }
    $body = ScriptExporter::render($format, (string) $ai_storyboard->label(), (string) $ai_storyboard->get('script')->value, $snapshot);
    $mime = match ($format) {
      'json' => 'application/json', 'fdx', 'osf' => 'application/xml',
      'rtf' => 'application/rtf', default => 'text/plain',
    };
    $response = new Response($body, 200, ['Content-Type' => $mime . '; charset=UTF-8']);
    $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->projectFilename($ai_storyboard) . '-script.' . $format));
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

  /**
   * Constructs the storyboard export controller.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $storyboardEntityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly TransliterationInterface $transliteration,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('transliteration'),
    );
  }

  /**
   * Downloads generated frames and shot metadata as a ZIP archive.
   */
  public function downloadImages(object $ai_storyboard): BinaryFileResponse {
    $frames = $this->frames($ai_storyboard);
    if ($frames === []) {
      throw new NotFoundHttpException('This storyboard has no generated frames.');
    }

    $temporary_path = tempnam($this->fileSystem->getTempDirectory(), 'ai-storyboard-');
    if ($temporary_path === FALSE) {
      throw new \RuntimeException('Could not create a temporary archive.');
    }
    $archive = new \ZipArchive();
    if ($archive->open($temporary_path, \ZipArchive::OVERWRITE) !== TRUE) {
      @unlink($temporary_path);
      throw new \RuntimeException('Could not open the temporary archive.');
    }

    $manifest = [
      'title' => (string) $ai_storyboard->label(),
      'aspect_ratio' => (string) $ai_storyboard->get('aspect_ratio')->value,
      'shots' => [],
    ];
    foreach ($frames as $index => $frame) {
      $shot = $frame['shot'];
      $extension = strtolower(pathinfo($frame['file']->getFilename(), PATHINFO_EXTENSION));
      $filename = sprintf(
        '%03d-scene-%d-shot-%d-%s%s',
        $index + 1,
        $shot->get('scene_number')->value,
        $shot->get('shot_number')->value,
        $this->safeFilename((string) $shot->label()),
        $extension !== '' ? '.' . $extension : '',
      );
      $archive->addFile($frame['path'], 'images/' . $filename);
      $manifest['shots'][] = [
        'position' => (int) $shot->get('position')->value,
        'scene' => (int) $shot->get('scene_number')->value,
        'shot' => (int) $shot->get('shot_number')->value,
        'title' => (string) $shot->label(),
        'duration' => (float) $shot->get('duration')->value,
        'action' => (string) $shot->get('action')->value,
        'dialogue' => (string) $shot->get('dialogue')->value,
        'audio' => (string) $shot->get('audio')->value,
        'image' => 'images/' . $filename,
      ];
    }
    $archive->addFromString(
      'storyboard.json',
      (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    );
    $archive->close();

    return $this->downloadResponse(
      $temporary_path,
      $this->projectFilename($ai_storyboard) . '-images.zip',
    );
  }

  /**
   * Downloads ordered video clips, falling back to timed keyframes.
   */
  public function downloadVideo(object $ai_storyboard): BinaryFileResponse|RedirectResponse {
    if ((new ExecutableFinder())->find('ffmpeg') === NULL
      || (new ExecutableFinder())->find('ffprobe') === NULL) {
      $this->messenger()->addError($this->t('MP4 export requires FFmpeg and FFprobe on the server.'));
      return new RedirectResponse($ai_storyboard->toUrl()->toString());
    }
    try {
      $frames = $this->timelineFrames($ai_storyboard);
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('The MP4 timeline could not be prepared: @message', ['@message' => $exception->getMessage()]));
      return new RedirectResponse($ai_storyboard->toUrl()->toString());
    }
    if ($frames === []) {
      $this->messenger()->addError($this->t('Generate at least one storyboard frame or video before exporting an MP4.'));
      return new RedirectResponse($ai_storyboard->toUrl()->toString());
    }

    $soundtrack = $this->storyboardAudioPath($ai_storyboard);
    $timeline_duration = array_sum(array_column($frames, 'duration'));
    $subtitle_text = $this->subtitleFileContents($ai_storyboard, $frames, $timeline_duration);

    [$width, $height] = $this->videoDimensions((string) $ai_storyboard->get('aspect_ratio')->value);
    $output_path = tempnam($this->fileSystem->getTempDirectory(), 'ai-storyboard-video-');
    if ($output_path === FALSE) {
      throw new \RuntimeException('Could not prepare the temporary MP4 file.');
    }
    $command = ['ffmpeg', '-y'];
    foreach ($frames as $frame) {
      if (!$frame['video']) {
        $command = array_merge($command, ['-loop', '1', '-t', (string) $frame['duration']]);
      }
      $command = array_merge($command, ['-i', $frame['path']]);
    }
    if ($soundtrack !== NULL) {
      $command = array_merge($command, ['-stream_loop', '-1', '-i', $soundtrack]);
    }
    $subtitle_path = NULL;
    if ($subtitle_text !== '') {
      $subtitle_path = tempnam($this->fileSystem->getTempDirectory(), 'ai-storyboard-subs-');
      if ($subtitle_path === FALSE || file_put_contents($subtitle_path, $subtitle_text) === FALSE) {
        if (is_string($subtitle_path)) {
          @unlink($subtitle_path);
        }
        @unlink($output_path);
        throw new \RuntimeException('Could not prepare the temporary subtitle file.');
      }
      $command = array_merge($command, ['-f', 'srt', '-i', $subtitle_path]);
    }
    $filters = [];
    $streams = '';
    foreach ($frames as $index => $frame) {
      $filters[] = sprintf(
        '[%1$d:v]scale=%2$d:%3$d:force_original_aspect_ratio=decrease,'
        . 'pad=%2$d:%3$d:(ow-iw)/2:(oh-ih)/2:color=black,'
        . 'setsar=1,fps=25,format=yuv420p,setpts=PTS-STARTPTS[v%1$d]',
        $index,
        $width,
        $height,
      );
      $audio = $frame['audio']
        ? sprintf('[%d:a]aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo,apad', $index)
        : 'anullsrc=r=48000:cl=stereo';
      $filters[] = sprintf('%s,atrim=duration=%s,asetpts=PTS-STARTPTS[a%d]', $audio, $frame['duration'], $index);
      $streams .= sprintf('[v%d][a%d]', $index, $index);
    }
    $filters[] = sprintf(
      '%sconcat=n=%d:v=1:a=1[outv][outa]',
      $streams,
      count($frames),
    );
    $audio_map = '[outa]';
    if ($soundtrack !== NULL) {
      $soundtrack_index = count($frames);
      $filters[] = sprintf(
        '[%d:a]aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo,atrim=duration=%s,asetpts=PTS-STARTPTS[soundtrack];[outa][soundtrack]amix=inputs=2:duration=first:dropout_transition=0:normalize=0,alimiter=limit=0.95[audioout]',
        $soundtrack_index,
        $timeline_duration,
      );
      $audio_map = '[audioout]';
    }
    $command = array_merge($command, [
      '-filter_threads', '1',
      '-filter_complex_threads', '1',
      '-filter_complex', implode(';', $filters),
      '-map', '[outv]',
      '-map', $audio_map,
      '-c:a', 'aac',
      '-b:a', '192k',
      '-c:v', 'libx264',
      '-preset', 'ultrafast',
      '-crf', '20',
      '-threads', '1',
      '-pix_fmt', 'yuv420p',
      '-movflags', '+faststart',
      '-f', 'mp4',
    ]);
    if ($subtitle_path !== NULL) {
      $subtitle_index = count($frames) + ($soundtrack !== NULL ? 1 : 0);
      $command = array_merge($command, [
        '-map', $subtitle_index . ':0',
        '-c:s', 'mov_text',
        '-metadata:s:s:0', 'language=und',
        '-metadata:s:s:0', 'title=Subtitles',
        '-metadata:s:s:0', 'handler_name=Subtitles',
        '-disposition:s:0', 'default',
      ]);
    }
    $command[] = $output_path;

    $process = new Process($command);
    $process->setTimeout(900);
    try {
      $process->run();
    }
    catch (\Throwable $exception) {
      @unlink($output_path);
      if ($subtitle_path !== NULL) {
        @unlink($subtitle_path);
      }
      $this->messenger()->addError($this->t('The MP4 export process was terminated by the server. Try exporting fewer or shorter clips at a time.'));
      return new RedirectResponse($ai_storyboard->toUrl()->toString());
    }
    if (!$process->isSuccessful() || !is_file($output_path)
      || filesize($output_path) === 0) {
      @unlink($output_path);
      if ($subtitle_path !== NULL) {
        @unlink($subtitle_path);
      }
      $this->messenger()->addError($this->t('The MP4 could not be created. FFmpeg reported: @message', [
        '@message' => mb_substr(trim($process->getErrorOutput()), 0, 500),
      ]));
      return new RedirectResponse($ai_storyboard->toUrl()->toString());
    }
    if ($subtitle_path !== NULL) {
      @unlink($subtitle_path);
    }

    return $this->downloadResponse(
      $output_path,
      $this->projectFilename($ai_storyboard) . '-animatic.mp4',
    );
  }

  /** Downloads a SubRip subtitle file for the current storyboard timeline. */
  public function downloadSubtitles(object $ai_storyboard): Response|RedirectResponse {
    try {
      $frames = $this->timelineFrames($ai_storyboard);
      $duration = array_sum(array_column($frames, 'duration'));
      $contents = $this->subtitleFileContents($ai_storyboard, $frames, $duration);
      if ($contents === '') {
        throw new \RuntimeException('No dialogue was found in the saved shots or screenplay.');
      }
      $response = new Response($contents, 200, ['Content-Type' => 'application/x-subrip; charset=UTF-8']);
      $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->projectFilename($ai_storyboard) . '-subtitles.srt'));
      $response->headers->set('Cache-Control', 'private, no-store');
      return $response;
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Subtitles could not be created: @message', ['@message' => $exception->getMessage()]));
      return new RedirectResponse($ai_storyboard->toUrl()->toString());
    }
  }

  /** Builds the SRT preview using the same timing as MP4 export. */
  public function buildSubtitlePreview(object $ai_storyboard): string {
    $frames = $this->timelineFrames($ai_storyboard);
    return $this->subtitleFileContents(
      $ai_storyboard,
      $frames,
      array_sum(array_column($frames, 'duration')),
    );
  }

  /**
   * Loads keyframes or preferred completed videos in storyboard order.
   */
  private function frames(object $storyboard, bool $prefer_video = FALSE): array {
    $shot_storage = $this->storyboardEntityTypeManager->getStorage('ai_storyboard_shot');
    $shot_ids = $shot_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('storyboard_id', $storyboard->id())
      ->sort('position', 'ASC')
      ->sort('id', 'ASC')
      ->execute();
    $frames = [];
    foreach ($shot_storage->loadMultiple($shot_ids) as $shot) {
      if ($prefer_video) {
        $video_turn = $shot->get('video_turn_id')->entity;
        $video = $video_turn?->get('video')->entity;
        if ($video instanceof FileInterface && $video_turn->get('status')->value === 'completed') {
          $path = $this->fileSystem->realpath($video->getFileUri());
          if ($path !== FALSE && is_file($path)) {
            $frames[] = ['shot' => $shot, 'file' => $video, 'path' => $path, 'video' => TRUE];
            continue;
          }
        }
      }
      $turn = $shot->get('studio_turn_id')->entity;
      $file = $turn?->get('image')->entity;
      if (!$file instanceof FileInterface) {
        continue;
      }
      $path = $this->fileSystem->realpath($file->getFileUri());
      if ($path !== FALSE && is_file($path)) {
        $frames[] = ['shot' => $shot, 'file' => $file, 'path' => $path, 'video' => FALSE];
      }
    }
    return $frames;
  }

  /** Adds clip durations and stream metadata to the ordered storyboard frames. */
  private function timelineFrames(object $storyboard): array {
    $frames = $this->frames($storyboard, TRUE);
    foreach ($frames as &$frame) {
      $frame['duration'] = max(0.1, (float) $frame['shot']->get('duration')->value);
      $frame['audio'] = FALSE;
      if (!$frame['video']) {
        continue;
      }
      if ((new ExecutableFinder())->find('ffprobe') === NULL) {
        throw new \RuntimeException('FFprobe is required to read generated video durations.');
      }
      $probe = new Process([
        'ffprobe', '-v', 'error', '-show_entries',
        'format=duration:stream=codec_type', '-of', 'json', $frame['path'],
      ]);
      $probe->setTimeout(30);
      $probe->run();
      $metadata = json_decode($probe->getOutput(), TRUE) ?: [];
      $duration = (float) ($metadata['format']['duration'] ?? 0);
      if (!$probe->isSuccessful() || $duration <= 0) {
        throw new \RuntimeException(sprintf('The video for "%s" could not be read.', $frame['shot']->label()));
      }
      $frame['duration'] = $duration;
      $frame['audio'] = in_array('audio', array_column($metadata['streams'] ?? [], 'codec_type'), TRUE);
    }
    unset($frame);

    $soundtrack = $this->storyboardAudioPath($storyboard);
    $still_only = count(array_filter($frames, static fn (array $frame): bool => $frame['video'])) === 0;
    if ($frames !== [] && $soundtrack !== NULL && $still_only && (new ExecutableFinder())->find('ffprobe') !== NULL) {
      $probe = new Process([
        'ffprobe', '-v', 'error', '-show_entries', 'format=duration',
        '-of', 'default=noprint_wrappers=1:nokey=1', $soundtrack,
      ]);
      $probe->setTimeout(30);
      $probe->run();
      $audio_duration = $probe->isSuccessful() ? (float) trim($probe->getOutput()) : 0.0;
      if ($audio_duration > 0) {
        $still_duration = max(0.001, $audio_duration / count($frames));
        foreach ($frames as &$frame) {
          $frame['duration'] = $still_duration;
        }
        unset($frame);
      }
    }
    return $frames;
  }

  /** Creates estimated SRT cues from shot dialogue or parsed screenplay dialogue. */
  private function subtitleFileContents(object $storyboard, array $frames, float $timeline_duration): string {
    if ($frames === [] || $timeline_duration <= 0) {
      return '';
    }
    $cues = [];
    $time = 0.0;
    foreach ($frames as $frame) {
      $dialogue = trim((string) $frame['shot']->get('dialogue')->value);
      $duration = (float) $frame['duration'];
      if ($dialogue !== '') {
        $cues = array_merge($cues, $this->weightedSubtitleCues($dialogue, $time, $duration));
      }
      $time += $duration;
    }

    // Use the screenplay dialogue as a fallback when the shot breakdown did
    // not include dialogue. These timings are proportional estimates, not
    // speech recognition or forced alignment.
    if ($cues === []) {
      $paragraphs = ScriptExporter::paragraphs((string) $storyboard->get('script')->value);
      $blocks = [];
      $speaker = '';
      foreach ($paragraphs as $paragraph) {
        if ($paragraph['type'] === 'Character') {
          $speaker = trim($paragraph['text']);
        }
        elseif ($paragraph['type'] === 'Dialogue') {
          $line = trim($paragraph['text']);
          if ($line !== '') {
            $blocks[] = ($speaker !== '' ? $speaker . ': ' : '') . $line;
          }
        }
      }
      if ($blocks !== []) {
        $cues = $this->weightedSubtitleCues(implode("\n", $blocks), 0, $timeline_duration);
      }
    }

    $srt = '';
    foreach ($cues as $index => $cue) {
      $srt .= ($index + 1) . "\n"
        . $this->subtitleTimestamp($cue['start']) . ' --> ' . $this->subtitleTimestamp($cue['end']) . "\n"
        . $cue['text'] . "\n\n";
    }
    return $srt;
  }

  /** Splits readable text into two-line cues and distributes them by length. */
  private function weightedSubtitleCues(string $text, float $start, float $duration): array {
    $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
    if ($text === '' || $duration <= 0) {
      return [];
    }
    $words = preg_split('/\s+/u', $text) ?: [];
    $lines = [];
    $line = '';
    foreach ($words as $word) {
      if (mb_strlen($word) > 42) {
        if ($line !== '') {
          $lines[] = $line;
          $line = '';
        }
        foreach (mb_str_split($word, 42) as $part) {
          $lines[] = $part;
        }
        continue;
      }
      $candidate = $line === '' ? $word : $line . ' ' . $word;
      if ($line !== '' && mb_strlen($candidate) > 42) {
        $lines[] = $line;
        $line = $word;
      }
      else {
        $line = $candidate;
      }
    }
    if ($line !== '') {
      $lines[] = $line;
    }
    $captions = [];
    foreach (array_chunk($lines, 2) as $lineset) {
      $captions[] = implode("\n", $lineset);
    }
    $weights = array_map(static fn (string $caption): int => max(1, mb_strlen(str_replace("\n", ' ', $caption))), $captions);
    $weight_total = array_sum($weights);
    $cues = [];
    $elapsed = 0.0;
    foreach ($captions as $index => $caption) {
      $cue_duration = $index === array_key_last($captions)
        ? $duration - $elapsed
        : $duration * $weights[$index] / $weight_total;
      $cues[] = ['start' => $start + $elapsed, 'end' => $start + $elapsed + $cue_duration, 'text' => $caption];
      $elapsed += $cue_duration;
    }
    return $cues;
  }

  /** Formats a timestamp for the SubRip file format. */
  private function subtitleTimestamp(float $seconds): string {
    $milliseconds = max(0, (int) round($seconds * 1000));
    $hours = intdiv($milliseconds, 3600000);
    $minutes = intdiv($milliseconds % 3600000, 60000);
    $whole_seconds = intdiv($milliseconds % 60000, 1000);
    $fraction = $milliseconds % 1000;
    return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $whole_seconds, $fraction);
  }

  /** Gets the local file path for the project's selected Audio media item. */
  private function storyboardAudioPath(object $storyboard): ?string {
    if (!$storyboard->hasField('audio_media') || !$storyboard->get('audio_media')->entity) {
      return NULL;
    }
    $media = $storyboard->get('audio_media')->entity;
    $source_field = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    if ($source_field === '' || !$media->hasField($source_field)) {
      return NULL;
    }
    $file = $media->get($source_field)->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    $path = $this->fileSystem->realpath($file->getFileUri());
    return $path !== FALSE && is_file($path) ? $path : NULL;
  }

  /**
   * Returns an HD output canvas for a storyboard aspect ratio.
   */
  private function videoDimensions(string $aspect_ratio): array {
    return match ($aspect_ratio) {
      '9:16' => [720, 1280],
      '1:1' => [1080, 1080],
      '4:3' => [1280, 960],
      '2.39:1' => [1280, 536],
      default => [1280, 720],
    };
  }

  /**
   * Creates a self-cleaning attachment response.
   */
  private function downloadResponse(string $path, string $filename): BinaryFileResponse {
    $response = new BinaryFileResponse($path);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $filename,
    );
    $response->deleteFileAfterSend(TRUE);
    return $response;
  }

  /**
   * Converts a label into a portable, non-empty filename.
   */
  private function safeFilename(string $label): string {
    $filename = strtolower($this->transliteration->transliterate($label));
    $filename = trim((string) preg_replace('/[^a-z0-9]+/', '-', $filename), '-');
    return $filename !== '' ? $filename : 'storyboard';
  }

  /**
   * Uses the project identifier for downloads, preserving underscores.
   */
  private function projectFilename(object $storyboard): string {
    $name = (string) $storyboard->get('machine_name')->value;
    return preg_match('/^[a-z0-9_]+$/D', $name)
      ? $name
      : $this->safeFilename((string) $storyboard->label());
  }

}
