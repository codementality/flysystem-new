<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests for M1-01 (Part 1): module scaffold outcome on a fresh site.
 *
 * Outcome per the plan row: the module installs/enables/disables cleanly on
 * a fresh site, and the cycle leaves the site byte-equivalent to core-native
 * behavior. Operationally this asserts: the extension toggles without error,
 * the registered stream wrapper set and the stream_wrapper_manager scheme
 * registry are IDENTICAL across the disable/re-enable cycle (the module must
 * register nothing — M1 scope guard), and the site still serves pages.
 *
 * @group flysystem
 */
#[RunTestsInSeparateProcesses]
final class FlysystemScaffoldTest extends BrowserTestBase {

  /**
   * The theme under test.
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to install on the fresh site.
   *
   * @var string[]
   */
  protected static $modules = ['flysystem'];

  /**
   * Verifies install -> disable -> re-enable leaves core-native behavior.
   */
  public function testInstallEnableDisableLeavesSiteCoreNative(): void {
    // setUp() installed a fresh site with the module enabled.
    $module_handler = $this->container->get('module_handler');
    $extension_list = $this->container->get('extension.list.module');
    $this->assertTrue($extension_list->exists('flysystem'), 'The flysystem module is discoverable.');
    $this->assertTrue($module_handler->isVisible('flysystem'), 'The module is enabled by the fresh install.');
    $this->assertArrayHasKey('flysystem', $this->config('core.extension')->get('module') ?? [], 'The module is recorded in core.extension.');

    $wrappers = stream_get_wrappers();
    sort($wrappers);
    $schemes = array_keys($this->container->get('stream_wrapper_manager')->getInfo());
    sort($schemes);

    // Disable.
    $this->container->get('module_installer')->uninstall(['flysystem']);
    $this->assertFalse($module_handler->isVisible('flysystem'), 'The module disables cleanly.');
    $this->assertArrayNotHasKey('flysystem', $this->config('core.extension')->get('module') ?? [], 'Disabling removes the module from core.extension.');

    $this->assertNativeBehaviorPreserved($wrappers, $schemes, 'after uninstall');

    // Re-enable.
    $this->container->get('module_installer')->install(['flysystem']);
    $this->assertTrue($module_handler->isVisible('flysystem'), 'The module re-enables cleanly on the same site.');

    $this->assertNativeBehaviorPreserved($wrappers, $schemes, 'after re-install');

    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Asserts wrapper/scheme registries are byte-identical to the baseline.
   *
   * @param string[] $expected_wrappers
   *   Sorted stream_get_wrappers() baseline captured with the module enabled.
   * @param string[] $expected_schemes
   *   Sorted stream_wrapper_manager scheme baseline captured alongside.
   * @param string $moment
   *   Human-readable point in the cycle, for failure messages.
   */
  protected function assertNativeBehaviorPreserved(array $expected_wrappers, array $expected_schemes, string $moment): void {
    $actual_wrappers = stream_get_wrappers();
    sort($actual_wrappers);
    $this->assertSame($expected_wrappers, $actual_wrappers, "The module cycle changed registered stream wrappers ($moment) — M1 must register none.");

    $actual_schemes = array_keys($this->container->get('stream_wrapper_manager')->getInfo());
    sort($actual_schemes);
    $this->assertSame($expected_schemes, $actual_schemes, "The module cycle changed the stream_wrapper_manager scheme registry ($moment) — M1 must contribute zero stream_wrapper services.");
  }

}
