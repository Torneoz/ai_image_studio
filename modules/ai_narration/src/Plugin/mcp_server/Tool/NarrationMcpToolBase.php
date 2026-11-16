<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Plugin\mcp_server\Tool;

use Drupal\ai_narration\Service\NarrationDurationInspector;
use Drupal\ai_narration\Service\NarrationGenerator;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class NarrationMcpToolBase extends ToolPluginBase {
  public function __construct(array $configuration, string $plugin_id, mixed $plugin_definition, AccountProxyInterface $current_user, protected readonly EntityTypeManagerInterface $entityTypeManager, protected readonly NarrationGenerator $generator, protected readonly FileUrlGeneratorInterface $fileUrlGenerator, protected readonly NarrationDurationInspector $durationInspector) { parent::__construct($configuration, $plugin_id, $plugin_definition, $current_user); }
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static { return new static($configuration, $plugin_id, $plugin_definition, $container->get('current_user'), $container->get('entity_type.manager'), $container->get('ai_narration.generator'), $container->get('file_url_generator'), $container->get('ai_narration.duration_inspector')); }
  protected function defaultConfiguration(): array { return ['enabled' => TRUE]; }
  public function checkAccess(AccountInterface $account): AccessResultInterface { return AccessResult::allowedIfHasPermission($account, 'access ai narration mcp tools'); }
  protected function projectData(object $project): array { return ['id' => (int) $project->id(), 'title' => $project->label(), 'model' => (string) $project->get('model')->value, 'voice' => (string) $project->get('voice')->value, 'format' => (string) $project->get('format')->value, 'language' => (string) $project->get('language')->value, 'speed' => (string) $project->get('speed')->value, 'max_input_characters' => (int) $project->get('max_input_characters')->value]; }
  protected function chapterData(object $chapter): array { return ['id' => (int) $chapter->id(), 'project_id' => (int) $chapter->get('project_id')->target_id, 'title' => $chapter->label(), 'position' => (int) $chapter->get('position')->value, 'text' => (string) $chapter->get('text')->value, 'status' => (string) $chapter->get('status')->value, 'audio_file_ids' => array_map(static fn ($item) => (int) $item->target_id, iterator_to_array($chapter->get('audio_files')))]; }
}
