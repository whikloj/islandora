<?php

namespace Drupal\Tests\islandora\Functional;

/**
 * Class IslandoraSettingsFormTest.
 *
 * @package Drupal\Tests\islandora\Functional
 * @group islandora
 * @coversDefaultClass \Drupal\islandora\Form\IslandoraSettingsForm
 */
class IslandoraSettingsFormTest extends IslandoraFunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    // Create a test user.
    $account = $this->drupalCreateUser([
      'bypass node access',
      'administer site configuration',
      'view media',
      'create media',
      'update media',
    ]);
    $this->drupalLogin($account);
  }

  /**
   * Test Gemini URL validation.
   */
  public function testGeminiUri() {
    $this->drupalGet('/admin/config/islandora/core');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains("Gemini URL");
    $this->assertSession()->fieldValueEquals('edit-gemini-url', '');

    $this->drupalPostForm('admin/config/islandora/core', ['edit-gemini-url' => 'not_a_url'], t('Save configuration'));
    $this->assertSession()->pageTextContainsOnce("Cannot parse URL not_a_url");

    $this->drupalPostForm('admin/config/islandora/core', ['edit-gemini-url' => 'http://whaturl.bob'], t('Save configuration'));
    $this->assertSession()->pageTextContainsOnce("Cannot connect to URL http://whaturl.bob");
  }

  /**
   * Test block on choosing Pseudo field bundles without a Gemini URL.
   */
  public function testPseudoFieldBundles() {
    $this->drupalGet('/admin/config/islandora/core');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalPostForm('admin/config/islandora/core', [
      'gemini_pseudo_bundles[test_type:node]' => TRUE,
    ], t('Save configuration'));
    $this->assertSession()->pageTextContainsOnce("Must enter Gemini URL before selecting bundles to display a pseudo field on.");

  }

  /**
   * Test form validation for JWT expiry.
   */
  public function testJwtExpiry() {
    $this->drupalGet('/admin/config/islandora/core');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains("JWT Expiry");
    $this->assertSession()->fieldValueEquals('edit-jwt-expiry', '+2 hour');
    // Blank is not allowed.
    $this->drupalPostForm('/admin/config/islandora/core', ['edit-jwt-expiry' => ""], t('Save configuration'));
    $this->assertSession()->pageTextContainsOnce('"" is not a valid time or interval expression.');
    // Negative is not allowed.
    $this->drupalPostForm('/admin/config/islandora/core', ['edit-jwt-expiry' => "-2 hours"], t('Save configuration'));
    $this->assertSession()->pageTextContainsOnce('Time or interval expression cannot be negative');
    // Must include an integer value.
    $this->drupalPostForm('/admin/config/islandora/core', ['edit-jwt-expiry' => "last hour"], t('Save configuration'));
    $this->assertSession()->pageTextContainsOnce('No numeric interval specified, for example "1 day"');
    // Must have an accepted interval.
    $this->drupalPostForm('/admin/config/islandora/core', ['edit-jwt-expiry' => "1 fortnight"], t('Save configuration'));
    $this->assertSession()->pageTextContainsOnce('No time interval found, please include one of');
    // Test a valid setting.
    $this->drupalPostForm('/admin/config/islandora/core', ['edit-jwt-expiry' => "2 weeks"], t('Save configuration'));
    $this->assertSession()->pageTextContainsOnce('The configuration options have been saved.');

  }

}
