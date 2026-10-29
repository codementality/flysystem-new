<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Functional\Verification;

use Drupal\flysystem\Verification\BindingVerifier;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Confirms the verification surface is reachable through the container.
 *
 * On a fresh site with no bindings configured, the service exists, answers
 * for an unknown scheme with no findings (core-native is not a problem),
 * and never throws.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class BindingVerifierServiceTest extends BrowserTestBase {

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
   * Verifies the service exists and answers cleanly for core-native schemes.
   */
  public function testVerificationServiceAnswersWithoutThrowing(): void {
    $verifier = $this->container->get('flysystem.binding_verifier');
    self::assertInstanceOf(BindingVerifier::class, $verifier);
    self::assertSame([], $verifier->verifyBinding('public'));
    $report = $verifier->verifyAll(['public', 'private', 'temporary']);
    self::assertSame(['public' => [], 'private' => [], 'temporary' => []], $report);
  }

}
