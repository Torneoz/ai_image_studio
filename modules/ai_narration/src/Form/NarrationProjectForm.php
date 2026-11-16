<?php
declare(strict_types=1);
namespace Drupal\ai_narration\Form;
use Drupal\ai_narration\Service\NarrationGenerator;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
final class NarrationProjectForm extends ContentEntityForm {
  protected NarrationGenerator $generator;
  public static function create(ContainerInterface $container): static { $form = parent::create($container); $form->generator = $container->get('ai_narration.generator'); return $form; }
  public function form(array $form, FormStateInterface $state): array {
    $form = parent::form($form, $state); $options = $this->generator->getModelOptions();
    $model = (string) ($state->getValue(['model', 0, 'value']) ?? $this->entity->get('model')->value);
    $form['model']['widget'][0]['value']['#type'] = 'select'; $form['model']['widget'][0]['value']['#options'] = $options; $form['model']['widget'][0]['value']['#empty_option'] = $this->t('- Select a model -'); $form['model']['widget'][0]['value']['#multiple'] = FALSE; $form['model']['widget'][0]['value']['#size'] = 1; $form['model']['widget'][0]['value']['#ajax'] = ['callback' => '::refreshVoice', 'wrapper' => 'ai-narration-project-voice'];
    $form['voice']['#attributes']['id'] = 'ai-narration-project-voice'; $form['voice']['widget'][0]['value']['#type'] = 'select'; $form['voice']['widget'][0]['value']['#options'] = $this->generator->getVoiceOptions($model); $form['voice']['widget'][0]['value']['#empty_option'] = $this->t('- Provider default -'); $form['voice']['widget'][0]['value']['#multiple'] = FALSE; $form['voice']['widget'][0]['value']['#size'] = 1;
    $form['format']['widget'][0]['value']['#type'] = 'select'; $form['format']['widget'][0]['value']['#options'] = $this->generator->getFormatOptions($model); $form['format']['widget'][0]['value']['#empty_option'] = $this->t('- Provider default -');
    $provider = explode('__', $model, 2)[0] ?? '';
    $form['speed']['#access'] = in_array($provider, ['openai', 'grok'], TRUE);
    $form['text_normalization']['#access'] = $provider === 'grok';
    $form['language']['#access'] = in_array($provider, ['gemini', 'grok'], TRUE);
    return $form;
  }
  public function refreshVoice(array &$form, FormStateInterface $state): array { return $form['voice']; }
}
