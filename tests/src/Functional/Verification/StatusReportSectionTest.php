<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Functional\Verification;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the status report section reaches the page a human visits.
 *
 * On a fresh install nothing is configured, so the section must appear
 * with its plain-language "no schemes configured" statement: this is the
 * hook wiring end to end — attribute hook class, reporter service,
 * verification surface, core status report renderer.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class StatusReportSectionTest extends BrowserTestBase {

  /**
   * The theme under test.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to install on the fresh site.
   *
   * @var string[]
   */
  protected static $modules = ['flysystem'];

  /**
   * Verifies the section is present on the status report page.
   */
  public function testSectionAppearsOnStatusReport(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $this->drupalGet('/admin/reports/status');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Flysystem file storage');
    $this->assertSession()->pageTextContains('No Flysystem-backed schemes are configured');
  }

}
