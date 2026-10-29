<?php

declare(strict_types=1);

namespace Drupal\flysystem_test_upload\Form;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Test-only form exercising the managed upload mechanics end to end.
 *
 * The form stages an incoming upload on the temporary scheme, places it
 * on the public scheme, and saves the managed file entity — the three
 * mechanics (staging write, cross-scheme move, entity save with its
 * size stat) that a bound site must reproduce exactly as core-native.
 */
final class FileUploadForm extends FormBase {

  /**
   * Constructs the form with the file system service.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service for the placement move.
   */
  public function __construct(protected readonly FileSystemInterface $fileSystem) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('file_system'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'flysystem_test_upload';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['file'] = [
      '#type' => 'file',
      '#title' => $this->t('File'),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uploaded = file_save_upload('file', [], 'temporary://');
    if (empty($uploaded) || $uploaded[0] === FALSE) {
      $this->messenger()->addError('STAGE_FAILED');
      return;
    }
    $file = $uploaded[0];

    $fs = $this->fileSystem;
    $upload_dir = 'public://upload';
    $fs->prepareDirectory($upload_dir, FileSystemInterface::CREATE_DIRECTORY);
    $moved = $fs->move($file->getFileUri(), 'public://upload/' . $file->getFilename());
    if ($moved === FALSE) {
      $this->messenger()->addError('MOVE_FAILED');
      return;
    }
    $file->setFileUri($moved);
    $file->save();

    $this->messenger()->addStatus('PLACED:' . $file->getFileUri() . ':' . $file->getSize() . ':' . $file->id());
  }

}
