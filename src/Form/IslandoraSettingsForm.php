<?php

namespace Drupal\islandora\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use GuzzleHttp\Exception\ConnectException;
use Islandora\Crayfish\Commons\Client\GeminiClient;
use Stomp\Client;
use Stomp\Exception\StompException;
use Stomp\StatefulStomp;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Config form for Islandora settings.
 */
class IslandoraSettingsForm extends ConfigFormBase {

  const CONFIG_NAME = 'islandora.settings';
  const BROKER_URL = 'broker_url';
  const BROKER_USER = 'broker_user';
  const BROKER_PASSWORD = 'broker_password';
  const JWT_EXPIRY = 'jwt_expiry';
  const GEMINI_URL = 'gemini_url';
  const GEMINI_PSEUDO = 'gemini_pseudo_bundles';
  const FEDORA_URL = 'fedora_url';
  const TIME_INTERVALS = [
    'sec',
    'second',
    'min',
    'minute',
    'hour',
    'week',
    'month',
    'year',
  ];

  /**
   * To list the available bundle types.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  private $entityTypeBundleInfo;

  /**
   * The saved password (if set).
   *
   * @var string
   */
  private $brokerPassword;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The EntityTypeBundleInfo service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeBundleInfoInterface $entity_type_bundle_info) {
    $this->setConfigFactory($config_factory);
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->brokerPassword = $this->config(self::CONFIG_NAME)->get(self::BROKER_PASSWORD);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
          $container->get('config.factory'),
          $container->get('entity_type.bundle.info')
      );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'islandora_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      self::CONFIG_NAME,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(self::CONFIG_NAME);

    $form['broker_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Broker'),
      '#open' => TRUE,
    ];
    $form['broker_info'][self::BROKER_URL] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL'),
      '#default_value' => $config->get(self::BROKER_URL),
    ];
    $broker_user = $config->get(self::BROKER_USER);
    $form['broker_info']['provide_user_creds'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Provide user identification'),
      '#default_value' => $broker_user ? TRUE : FALSE,
    ];
    $state_selector = 'input[name="provide_user_creds"]';
    $form['broker_info'][self::BROKER_USER] = [
      '#type' => 'textfield',
      '#title' => $this->t('User'),
      '#default_value' => $broker_user,
      '#states' => [
        'visible' => [
          $state_selector => ['checked' => TRUE],
        ],
        'required' => [
          $state_selector => ['checked' => TRUE],
        ],
      ],
    ];
    $form['broker_info'][self::BROKER_PASSWORD] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('If this field is left blank and the user is filled out, the current password will not be changed.'),
      '#states' => [
        'visible' => [
          $state_selector => ['checked' => TRUE],
        ],
      ],
    ];
    $form[self::JWT_EXPIRY] = [
      '#type' => 'textfield',
      '#title' => $this->t('JWT Expiry'),
      '#default_value' => $config->get(self::JWT_EXPIRY),
      '#description' => $this->t('A positive time interval expression. Eg: "60 secs", "2 days", "10 hours", "7 weeks". Be sure you provide the time units (@unit), plurals are accepted.',
        ['@unit' => implode(self::TIME_INTERVALS, ", ")]
      ),
    ];

    $form[self::GEMINI_URL] = [
      '#type' => 'textfield',
      '#title' => $this->t('Gemini URL'),
      '#default_value' => $config->get(self::GEMINI_URL),
    ];

    $flysystem_config = Settings::get('flysystem');
    $fedora_url = $flysystem_config['fedora']['config']['root'];

    $form[self::FEDORA_URL] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fedora URL'),
      '#attributes' => ['readonly' => 'readonly'],
      '#default_value' => $fedora_url,
    ];

    $selected_bundles = $config->get(self::GEMINI_PSEUDO);

    $options = [];
    foreach (['node', 'media', 'taxonomy_term'] as $content_entity) {
      $bundles = $this->entityTypeBundleInfo->getBundleInfo($content_entity);
      foreach ($bundles as $bundle => $bundle_properties) {
        $options["{$bundle}:{$content_entity}"] =
                $this->t('@label (@type)', [
                  '@label' => $bundle_properties['label'],
                  '@type' => $content_entity,
                ]);
      }
    }

    $form['bundle_container'] = [
      '#type' => 'details',
      '#title' => $this->t('Fedora URL Display'),
      '#description' => $this->t('Selected bundles can display the Fedora URL of repository content.'),
      '#open' => TRUE,
      self::GEMINI_PSEUDO => [
        '#type' => 'checkboxes',
        '#options' => $options,
        '#default_value' => $selected_bundles,
      ],
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate broker url by actually connecting with a stomp client.
    $brokerUrl = $form_state->getValue(self::BROKER_URL);
    // Attempt to subscribe to a dummy queue.
    try {
      $client = new Client($brokerUrl);
      if ($form_state->getValue('provide_user_creds')) {
        $broker_password = $form_state->getValue(self::BROKER_PASSWORD);
        // When stored password type fields aren't rendered again.
        if (!$broker_password) {
          // Use the stored password if it exists.
          if (!$this->brokerPassword) {
            $form_state->setErrorByName(self::BROKER_PASSWORD, $this->t('A password must be supplied'));
          }
          else {
            $broker_password = $this->brokerPassword;
          }
        }
        $client->setLogin($form_state->getValue(self::BROKER_USER), $broker_password);
      }
      $stomp = new StatefulStomp($client);
      $stomp->subscribe('dummy-queue-for-validation');
      $stomp->unsubscribe();
    }
    // Invalidate the form if there's an issue.
    catch (StompException $e) {
      $form_state->setErrorByName(
        self::BROKER_URL,
        $this->t(
          'Cannot connect to message broker at @broker_url',
          ['@broker_url' => $brokerUrl]
        )
      );
    }

    // Validate jwt expiry as a valid time string.
    $expiry = trim($form_state->getValue(self::JWT_EXPIRY));
    $expiry = strtolower($expiry);
    if (strtotime($expiry) === FALSE) {
      $form_state->setErrorByName(
        self::JWT_EXPIRY,
        $this->t(
          '"@expiry" is not a valid time or interval expression.',
          ['@expiry' => $expiry]
        )
      );
    }
    elseif (substr($expiry, 0, 1) == "-") {
      $form_state->setErrorByName(
        self::JWT_EXPIRY,
        $this->t('Time or interval expression cannot be negative')
          );
    }
    elseif (intval($expiry) === 0) {
      $form_state->setErrorByName(
        self::JWT_EXPIRY,
        $this->t('No numeric interval specified, for example "1 day"')
          );
    }
    else {
      if (!preg_match("/\b(" . implode(self::TIME_INTERVALS, "|") . ")s?\b/", $expiry)) {
        $form_state->setErrorByName(
          self::JWT_EXPIRY,
          $this->t("No time interval found, please include one of (@int). Plurals are also accepted.",
            ['@int' => implode(self::TIME_INTERVALS, ", ")]
          )
        );
      }
    }

    // Needed for the elseif below.
    $pseudo_types = array_filter($form_state->getValue(self::GEMINI_PSEUDO));

    // Validate Gemini URL by validating the URL.
    $geminiUrlValue = trim($form_state->getValue(self::GEMINI_URL));
    if (!empty($geminiUrlValue)) {
      try {
        $geminiUrl = Url::fromUri($geminiUrlValue);
        $client = GeminiClient::create($geminiUrlValue, $this->logger('islandora'));
        $client->findByUri('http://example.org');
      }
      // Uri is invalid.
      catch (\InvalidArgumentException $e) {
        $form_state->setErrorByName(
          self::GEMINI_URL,
          $this->t(
            'Cannot parse URL @url',
            ['@url' => $geminiUrlValue]
          )
        );
      }
      // Uri is not available.
      catch (ConnectException $e) {
        $form_state->setErrorByName(
              self::GEMINI_URL,
              $this->t(
                  'Cannot connect to URL @url',
                  ['@url' => $geminiUrlValue]
              )
          );
      }
    }
    elseif (count($pseudo_types) > 0) {
      $form_state->setErrorByName(
        self::GEMINI_URL,
        $this->t('Must enter Gemini URL before selecting bundles to display a pseudo field on.')
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable(self::CONFIG_NAME);

    $pseudo_types = array_filter($form_state->getValue(self::GEMINI_PSEUDO));

    $broker_password = $form_state->getValue(self::BROKER_PASSWORD);

    // If there's no user set delete what may have been here before as password
    // fields will also be blank.
    if (!$form_state->getValue('provide_user_creds')) {
      $config->clear(self::BROKER_USER);
      $config->clear(self::BROKER_PASSWORD);
    }
    else {
      $config->set(self::BROKER_USER, $form_state->getValue(self::BROKER_USER));
      // If the password has changed update it as well.
      if ($broker_password && $broker_password != $this->brokerPassword) {
        $config->set(self::BROKER_PASSWORD, $broker_password);
      }
    }

    $config
      ->set(self::BROKER_URL, $form_state->getValue(self::BROKER_URL))
      ->set(self::JWT_EXPIRY, $form_state->getValue(self::JWT_EXPIRY))
      ->set(self::GEMINI_URL, $form_state->getValue(self::GEMINI_URL))
      ->set(self::GEMINI_PSEUDO, $pseudo_types)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
