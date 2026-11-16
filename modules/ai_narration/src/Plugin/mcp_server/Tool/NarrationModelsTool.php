<?php
declare(strict_types=1);
namespace Drupal\ai_narration\Plugin\mcp_server\Tool;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
#[Tool(id: 'ai_narration_models', label: new TranslatableMarkup('List AI Narration models'), description: new TranslatableMarkup('Lists available text-to-speech models, voices, formats, and input limits.'), module_dependencies: ['ai_narration'], readOnly: TRUE, destructive: FALSE, idempotent: TRUE, openWorld: FALSE)]
final class NarrationModelsTool extends NarrationMcpToolBase { public function execute(array $arguments, ClientGateway $gateway): mixed { $models = []; foreach ($this->generator->getModelOptions() as $id => $label) { $models[] = ['id' => $id, 'label' => $label, 'voices' => $this->generator->getVoiceOptions($id), 'formats' => $this->generator->getFormatOptions($id), 'input_character_limit' => $this->generator->getInputCharacterLimit($id)]; } return ['models' => $models]; } }
