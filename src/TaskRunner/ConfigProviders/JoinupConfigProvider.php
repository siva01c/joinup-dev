<?php

declare(strict_types = 1);

namespace Joinup\TaskRunner\ConfigProviders;

use DrupalFinder\DrupalFinder;
use OpenEuropa\TaskRunner\Contract\ConfigProviderInterface;
use OpenEuropa\TaskRunner\Traits\ConfigFromFilesTrait;
use Robo\Config\Config;
use Robo\Exception\AbortTasksException;

/**
 * Runs configs from the ./runner dir between runner.yml.dist and runner.yml.
 */
class JoinupConfigProvider implements ConfigProviderInterface {

  use ConfigFromFilesTrait;

  /**
   * {@inheritdoc}
   */
  public static function provide(Config $config): void {
    // Get the Joinup project full-qualified directory.
    $drupalFinder = new DrupalFinder();
    if (!$drupalFinder->locateRoot(getcwd())) {
      throw new AbortTasksException('Cannot locate Drupal path.');
    }
    $config->set('joinup.dir', $drupalFinder->getComposerRoot());

    // Create all missed env.* configs from environment variables. The task
    // runner config only resolves the environment variables used as tokens in
    // other configs.
    foreach ($_ENV as $name => $value) {
      $config->set("env.{$name}", $value);
    }

    // Import configurations from ./resources/runner/.
    static::importFromFiles($config, glob('resources/runner/*.yml'));
  }

}
