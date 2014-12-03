<?php

/**
 * @file
 * Contains \Triquanta\AccessibilityMonitor\Console\Command\Queue.
 */

namespace Triquanta\AccessibilityMonitor\Console\Command;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Triquanta\AccessibilityMonitor\ActionsInterface;
use Triquanta\AccessibilityMonitor\ContainerFactoryInterface;
use Triquanta\AccessibilityMonitor\StorageInterface;
use Triquanta\AccessibilityMonitor\Url;
use Triquanta\AccessibilityMonitor\Website;

/**
 * Provides a command to queue scheduled websites and URLs.
 */
class Queue extends Command implements ContainerFactoryInterface {

  /**
   * The actions manager.
   *
   * @var \Triquanta\AccessibilityMonitor\ActionsInterface
   */
  protected $actions;

  /**
   * The number of items in the queue that should trigger an alert log item.
   *
   * @var int
   */
  protected $alertThreshold;

  /**
   * The number of items in the queue that should trigger an error log item.
   *
   * @var int
   */
  protected $errorThreshold;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The number of items in the queue that should trigger a notice log item.
   *
   * @var int
   */
  protected $noticeThreshold;

  /**
   * The storage manager.
   *
   * @var \Triquanta\AccessibilityMonitor\StorageInterface
   */
  protected $storage;

  /**
   * Constructs a new instance.
   *
   * @param \Triquanta\AccessibilityMonitor\ActionsInterface $actions
   * @param \Triquanta\AccessibilityMonitor\StorageInterface $storage
   * @param \Psr\Log\LoggerInterface
   * @param int $notice_threshold
   *   The number of items in the queue that should trigger a notice log item.
   * @param int $error_threshold
   *   The number of items in the queue that should trigger an error log item.
   * @param int $alert_threshold
   *   The number of items in the queue that should trigger an alert log item.
   */
  public function __construct(ActionsInterface $actions, StorageInterface $storage, LoggerInterface $logger, $notice_threshold, $error_threshold, $alert_threshold) {
    parent::__construct();
    $this->actions = $actions;
    $this->alertThreshold = $alert_threshold;
    $this->errorThreshold = $error_threshold;
    $this->logger = $logger;
    $this->noticeThreshold = $notice_threshold;
    $this->storage = $storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('actions'), $container->get('storage'), $container->get('logger'), $container->getParameter('queue.threshold.notice'), $container->getParameter('queue.threshold.error'), $container->getParameter('queue.threshold.alert'));
  }

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this->setName('queue');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $count_before = $this->storage->countUrlsByStatus(Url::STATUS_SCHEDULED);

    // Queue new items.
    foreach ($this->storage->getPendingActions() as $action) {
      if ($action->getAction() == $action::ACTION_ADD_URL) {
        // If no website exists for this URL, skip it.
        $website_id = $this->storage->getWebsiteById($action->getWebsiteId());
        if (!$website_id) {
          $this->logger->info(sprintf('Skipped adding URL %s, because the website for it was not added yet.', $action->getUrl()));
          continue;
        }
        else {
          $url = new Url();
          $url->setUrl($action->getUrl())
            ->setWebsiteId($website_id);
          $this->actions->addUrl($url);
        }
      }
      elseif ($action->getAction() == $action::ACTION_EXCLUDE_URL) {
        $url = $this->storage->getUrlByUrl($action->getUrl());
        if (!$url) {
          $this->logger->info(sprintf('Skipped excluding URL %s, because it could not be found in storage.', $action->getUrl()));
          continue;
        }
        $this->actions->excludeUrl($url);
      }
      elseif ($action->getAction() == $action::ACTION_ADD_WEBSITE) {
        $website = new Website();
        $website->setUrl($action->getUrl());
        $this->actions->addWebsite($website);
      }
      elseif ($action->getAction() == $action::ACTION_EXCLUDE_WEBSITE) {
        $website = $this->storage->getWebsiteByUrl($action->getUrl());
        if (!$website) {
          $this->logger->info(sprintf('Skipped excluding website %s, because it could not be found in storage.', $action->getUrl()));
          continue;
        }
        $this->actions->excludeWebsite($website);
      }
      elseif ($action->getAction() == $action::ACTION_RESCAN_WEBSITE) {
        $website = $this->storage->getWebsiteByUrl($action->getUrl());
        if (!$website) {
          $this->logger->info(sprintf('Skipped re-scanning website %s, because it could not be found in storage. Adding a new website instead.', $action->getUrl()));
          $website = new Website();
          $website->setUrl($action->getUrl());
          $this->actions->addWebsite($website);
        }
        $this->actions->rescanWebsite($website);
      }
      $action->setTimestamp(time());
      $this->storage->saveAction($action);
    }

    // Check the queue health.
    $count_after = $this->storage->countUrlsByStatus(Url::STATUS_SCHEDULED);
    $level = $count_after > $this->alertThreshold ? LogLevel::ALERT : ($count_after > $this->errorThreshold ? LogLevel::ERROR : ($count_after > $this->noticeThreshold ? LogLevel::NOTICE : LogLevel::INFO));
    $this->logger->log($level, sprintf('%d URLs, of which %d newly added, are currently scheduled for testing.', $count_after, $count_after - $count_before));
  }

}
