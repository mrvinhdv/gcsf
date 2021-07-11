<?php

namespace Drupal\gcsf\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\file\Entity\File;

/**
 * Class SettingForm.
 */
class SettingForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'gcsf.setting',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'gcsf_setting_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('gcsfs.setting');
    $url = Url::fromUri('https://developers.google.com/api-client-library/php/auth/service-accounts#creatinganaccount');
    $link = Link::fromTextAndUrl($this->t('click here'), $url);
    $options = [
      'attributes' => [
        'target' => '_blank',
      ],
    ];
    $url->setOptions($options);
    $form['gcs_json_credential'] = [
      '#type' => 'managed_file',
      '#title' => t('JSON credential'),
      '#default_value' => $this->config('gcs_json_credential'),
      '#description' => $this->t('The generated JSON credentials that allows access to your Google Cloud Storage account. For more information, @link.', [
        '@link' => $link->toString(),
      ]),
      '#required' => TRUE,
      '#upload_validators' => [
        'file_validate_extensions' => ['json'],
      ],
      '#upload_location' => 'private://',
      '#default_value' => [($config->get('gcs_json_credential')) ? $config->get('gcs_json_credential') : 0],
    ];
    $form['gcs_project_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Project ID'),
      '#description' => $this->t('Your Google Cloud Project ID.'),
      '#default_value' => $config->get('gcs_project_id'),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $form['gcs_bucket'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bucket Name'),
      '#description' => $this->t('Your bucket name. Keep it simple. This is just the bucket name, no protocol information, etc.'),
      '#default_value' => $config->get('gcs_bucket'),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];
    $form['advanced'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Advanced Configuration Options'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];
    $advanced = &$form['advanced'];
    $advanced['gcs_file_public'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled file public'),
      '#default_value' => Settings::get('gcs.use_file_public'),
      '#disabled' => TRUE,
      '#description' => $this->t(
        "Enable this option to store all files which would be uploaded to or created in the web server's local file system
      within your GCS bucket instead. To replace public:// stream wrapper with gcsfs stream, include the following in settings.php:<br>
      <em>\$settings['gcs.use_file_public'] = TRUE;</em>"),
    ];
    $advanced['gcs_cache'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use cache'),
      '#default_value' => $config->get('gcs_cache'),
    ];
    return parent::buildForm($form, $form_state);
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $config = $this->config('gcsf.setting');
    $form_file = $form_state->getValue('gcs_json_credential', 0);
    if (isset($form_file[0]) && !empty($form_file[0])) {
      /** @var \Drupal\file\FileInterface $file */
      $file = File::load($form_file[0]);
      $file->setPermanent();
      $file->save();
      $config->set('gcs_json_credential', $file->id());
    }
    $config->set('gcs_cache', $form_state->getValue('gcs_cache'));
    $config->set('gcs_project_id', $form_state->getValue('gcs_project_id'));
    $config->set('gcs_bucket', $form_state->getValue('gcs_bucket'));
    $config->save();
  }

}
