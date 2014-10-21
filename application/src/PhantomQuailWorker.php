<?php

/**
 * @file
 * Contains \Triquanta\AccessibilityMonitor\PhantomQuailWorker.
 */

namespace Triquanta\AccessibilityMonitor;

use Solarium\Core\Client\Client;
use Solarium\QueryType\Update\Query\Query;

/**
 * Provides a Phantom Quail worker.
 */
class PhantomQuailWorker extends \Thread {

  /**
   * The Google Pagespeed tester.
   *
   * @var \Triquanta\AccessibilityMonitor\GooglePagespeedInterface
   */
  protected $googlePagespeed;

  /**
   * The Phantom JS manager.
   *
   * @var \Triquanta\AccessibilityMonitor\PhantomJsInterface
   */
  protected $phantomJs;

  /**
   * The Solr client.
   *
   * @var \Solarium\Core\Client\Client
   */
  protected $solrClient;

  /**
   * The URL that is being tested.
   *
   * @var \Triquanta\AccessibilityMonitor\Url
   */
  protected $url;

  /**
   * The website of which a URL is being tested.
   *
   * @var \Triquanta\AccessibilityMonitor\Website
   */
  protected $website;

  /**
   * @todo
   */
  protected $result;

  /**
   * Whether the test includes CMS detection.
   *
   * @var bool
   */
  protected $determineCms = FALSE;

  /**
   * @todo
   */
  protected $cmsResult;

  /**
   * Whether the test includes Google Pagespeed.
   *
   * @var bool
   */
  protected $performGooglePagespeed = FALSE;

  /**
   * @todo
   */
  protected $pageSpeedResult = FALSE;

  // Store the website cms.
  protected $websiteCms;
  // Store the website id, if the website analysis has been done.
  protected $wid;

  /**
   * @todo
   */
  protected $status = Url::STATUS_TESTING;

  // Variable to hold the absolute raw result.
  protected $rawResult;
  // Array to hold all the raw technique results per test.
  protected $rawQuailTechniqueResults = array();
  // Array to hold all the results per test.
  protected $quailResults = array();
  // Array to hold all the cases of all tests together with the result.
  protected $quailCases = array();
  // Array to hold examples of failed cases.
  protected $failedCaseExamples = array();
  // Array to hold the final aggregated results stemming from raw cases per wcag thingy.
  protected $quailAggregatedCaseResults = array();

  // The final results per wcag item stemming from quail internal analyses.
  protected $quailFinalResults = array();

  // Store the queueid of the object.
  protected $queueId;

  /**
   * Constructs a new instance.
   *
   * @param \Triquanta\AccessibilityMonitor\GooglePagespeedInterface $google_pagespeed
   * @param \Solarium\Core\Client\Client $solr_client
   * @param \Triquanta\AccessibilityMonitor\PhantomJsInterface $phantom_js
   * @param \Triquanta\AccessibilityMonitor\Url $url
   * @param \Triquanta\AccessibilityMonitor\Website $website
   * @param $queueId
   * @param $determineCms
   * @param $executeGooglePagespeed
   */
  public function __construct(GooglePagespeedInterface $google_pagespeed, Client $solr_client, PhantomJsInterface $phantom_js, Url $url, Website $website, $queueId, $determineCms, $executeGooglePagespeed) {
    $this->googlePagespeed = $google_pagespeed;
    $this->phantomJs = $phantom_js;
    $this->solrClient = $solr_client;

    $this->url = $url;
    $this->website = $website;
    $this->performGooglePagespeed = $executeGooglePagespeed;
    $this->determineCms = $determineCms;
    // Fill the websiteCms if present.
    if ($url->getCms() != '') {
      $this->websiteCms = $url->getCms();
    }
    // Fill the wid property.
    $this->wid = $this->website->getId();
    $this->queueId = $queueId;
  }

  /**
   * Run function. This function is executed when the method start is executed.
   */
  public function run() {
    // We need to include the autoloaders here.
    // This is because this is a thread, and there
    // are different rules.
    // For this perticular case see:
    // https://github.com/krakjoe/pthreads/issues/68
    // Composer autoloader.
    require( __DIR__ . '/../vendor/autoload.php');
    Application::bootstrap();

    // If the website has not yet a cms detected, perform the detection here.
//    if ($this->determineCms) {
//      $this->detectCms();
//    }
//    if ($this->performGooglePagespeed) {
//      $this->executeGooglePagespeed();
//    }
    $this->analyzeQuail();
  }

  /**
   * Detect the cms of the main website.
   */
  protected function detectCms() {
    $testUrl = $this->website->getUrl();
    $this->websiteCms = implode('|', $this->phantomJs->getDetectedApps($testUrl));
  }

  /**
   * Perform a google Pagespeed test.
   */
  protected function executeGooglePagespeed() {
    $url = $this->website->getUrl();
    $pageSpeedResult = $this->googlePagespeed->test($url);
    $this->pageSpeedResult = json_encode($pageSpeedResult);
  }


  /**
   * Get the website id.
   *
   * This property only returns result, if a successful cms detection has been performed.
   *
   * @return mixed
   */
  public function getWid() {
    return $this->wid;
  }

  /**
   * Get the website cms.
   *
   * @return mixed
   */
  public function getWebsiteCms() {
    if ($this->determineCms) {
      return $this->websiteCms;
    }
  }

  /**
   * Perform the quail analysis.
   */
  protected function analyzeQuail() {
    // First delete all solr records for this url.
    $this->deleteCasesFromSolr();

    $url = $this->url->getUrl();
    try {
      $output = $this->phantomJs->getQuailResults($url);
      // @TODO: check if we need the count for solr.
      // Now process the results from quail.
      // We have to generate a unique id later.
      // In order to do this, we count the results, so it can be
      // included in the unique id.
      $count = 0;

      // Create an array for all quail results.
      // We need to use this 'in between array' because in threads object variable
      // arrays don't allow array_push or [].
      $rawQuailResults = array();
      // Create an array for
      foreach (preg_split("/((\r?\n)|(\r\n?))/", $output) as $line) {
        if ($line != '' && preg_match("/^{/", $line)) {
          // do stuff with $line
          $rawResults = (array) json_decode($line);
          // Since there is only one result json, this is also the exact raw result.
          $this->rawResult = $rawResults;
          // Now process the quail result.
          $this->processQuailResult();
        }
      }

      // Now send the case results to solr.
//      $this->logger->debug('Sending results to Solr.');
//      $this->sendCaseResultsToSolr();
//      $this->logger->debug('Results sended to Solr.');

      // Update the result.
      $this->result = $this->url->getId();
      // Set the status to tested.
      $this->status = Url::STATUS_TESTED;
    } catch (\Exception $e) {
      // If there is an exception, probably phantomjs timed out.
      $this->status = Url::STATUS_ERROR;
//      $this->logger->debug($e->getMessage());
    }
  }

  protected function processQuailResult() {
    // Create an array which will be written to the object property later.
    // We need this because of the thread nature of this class.
    $quailFinalResults = array();
    $rawResult = $this->rawResult;
    $wcag20Mapping = $this->getWcag2Mapping();
    foreach ($rawResult as $criterium) {
      $criteriumNumber = $wcag20Mapping[$criterium->testRequirement];
      // Now unset the hasPart, on order to save space.
      unset($criterium->hasPart);
      // Add criterium.
      $criterium->criterium = $criteriumNumber;
      $quailFinalResults[$criteriumNumber] = $criterium;
    }
    // Now fill the property.
    $this->quailFinalResults = $quailFinalResults;

  }

  /**
   * Delete all the cases from solr.
   */
  public function deleteCasesFromSolr() {
    // Get a delete query.
    $update = $this->solrClient->createUpdate();

    // add the delete query and a commit command to the update query
    $escaped_string = $this->escapeUrlForSolr($this->url->getUrl());
    $solrQuery = 'url_id:' . $escaped_string;

    $update->addDeleteQuery($solrQuery);

    // this executes the query and returns the result
    $result = $this->solrClient->update($update);

  }

  /**
   * Send all the cases to solr.
   */
  protected function sendCaseResultsToSolr() {
    // First check if there are results to send.
    if (count($this->quailCases)) {
      // Create an update query.
      $updateQuery = $this->solrClient->createUpdate();

      // Create an array of documents.
      $docs = array();
      // Now start adding the cases.
      foreach ($this->quailCases as $case) {
        $doc = $this->caseToSolrDocument($updateQuery, $case);
        if ($doc) {
          $docs[] = $doc;
        }
      }
      // add the documents and a commit command to the update query
      $updateQuery->addDocuments($docs);

      // this executes the query and returns the result.
      // TODO: catch exceptions.
      $result = $this->solrClient->update($updateQuery);
    }
  }

  /**
   * Create a solr document from a case.
   *
   * @param \Solarium\QueryType\Update\Query\Query $updateQuery
   * @param $case
   *
   * @return \Solarium\QueryType\Update\Query\Document\DocumentInterface $doc
   *   Solr document
   */
  protected function caseToSolrDocument(Query $updateQuery, $case) {
    // Create a solr document.
    if (property_exists($case, 'status')) {
      $doc = $updateQuery->createDocument();
      $doc->id = $this->createCaseSolrId($case);
      $doc->content = json_encode($case);
      $doc->url = $case->url;
      $doc->url_id = $case->url_id;
      $doc->url_main = $case->url_main;
      $doc->url_sub = $case->url_sub;
      if (property_exists($case, 'html')) {
        $doc->element = $case->html;
      }
      if (property_exists($case, 'title')) {
        $doc->name = $case->title->en;
      }
      if (property_exists($case, 'applicationframework')) {
        $doc->applicationframework = $case->applicationframework;
      }
      if (property_exists($case, 'techniques')) {
        $doc->techniques = $case->techniques;
      }
      if (property_exists($case, 'technologies')) {
        $doc->technologies = $case->technologies;
      }
      $doc->tags = $case->tags;
      $doc->testability = $case->testability;
      $doc->test_result = $case->status;
      $doc->testtype = $case->type;
      $doc->severity = $case->testability;

      // Add document type.
      $doc->document_type = 'case';

      return $doc;
    }
    return FALSE;
  }

  /**
   * Create a solr id for a case.
   *
   * @param $case
   *
   * @return string
   *   Case id.
   */
  protected function createCaseSolrId($case) {
    return 'case_' . $case->id . '_' . $case->url_id;
  }

  /**
   * Preprocess quail result for sending to solr.
   *
   * TODO: Solr should have a class, in which we can do all these things.
   *
   * @param $quailResult
   * @param $count
   *
   * @return mixed
   */
  protected function preprocessQuailResultForSolr($quailResult, $count) {
    if (isset($quailResult->url) && $quailResult->url != '' && isset($quailResult->cases) && count($quailResult->cases) > 0) {
      $quailResult->url_main = "";
      $quailResult->url_sub = "";
      $urlarr = parse_url($quailResult->url);
      $fqdArr = explode(".", $urlarr["host"]);
      if (count($fqdArr) > 2) {
        $partcount = count($fqdArr);
        $quailResult->url_main = $fqdArr[$partcount - 2] . "." . $fqdArr[$partcount - 1];
      }
      else {
        $quailResult->url_main = $urlarr["host"];
      }
      $quailResult->url_sub = $urlarr["host"];

      // Add the escaped url in order to be able to delete.
      $escaped_url = $this->escapeUrlForSolr($quailResult->url);
      $quailResult->url_id = $escaped_url;

      // Create a unique id.
      $quailResult->id = time() . $count;
      if (isset($quailResult->guidelines) && !empty($quailResult->guidelines)) {
        $quailResult->applicationframework = array();
        $quailResult->techniques = array();
        if (isset($quailResult->guidelines->wcag)) {
          foreach ($quailResult->guidelines->wcag as $wcagCode => $wcagItem) {
            $quailResult->applicationframework[] = $wcagCode;
            // Add the techniques.
            if (isset($wcagItem->techniques) && count($wcagItem->techniques) > 0 && is_array($wcagItem->techniques)) {
              foreach ($wcagItem->techniques as $technique) {
                $quailResult->techniques[] = $technique;
              }
            }
          }
        }

        $quailResult->techniques = array_unique($quailResult->techniques);
      }
      return $quailResult;
    }
    return FALSE;
  }

  /********************************
   * GETTER AND SETTERS.
   ********************************/

  /**
   * @param mixed $queueId
   */
  public function setQueueId($queueId) {
    $this->queueId = $queueId;
  }

  /**
   * @return mixed
   */
  public function getQueueId() {
    return $this->queueId;
  }

  /**
   * Return result data, so it can be processed further.
   *
   * @return mixed
   */
  public function getResult() {
    return $this->result;
  }

  /**
   * Get the quail aggregated case results for all wcag criteria (including level AAA).
   *
   * @return array
   */
  public function getQuailAggregatedCaseResults() {
    return $this->quailAggregatedCaseResults;
  }

  /**
   * Get the quail final result.
   *
   * @return array
   */
  public function getQuailFinalResults() {
    return $this->quailFinalResults;
  }


  /**
   * Get status.
   *
   * @return int
   */
  public function getStatus() {
    return $this->status;
  }

  /**
   * Get the website id of the url.
   *
   * @return mixed
   */
  public function getWebsiteId() {
    return $this->url->getWebsiteId();
  }

  /**
   * Gets the URL this worker tests.
   *
   * @return \Triquanta\AccessibilityMonitor\Url
   */
  public function getUrl() {
    return $this->url;
  }

  /**
   * Gets the website of which this worker tests a URL.
   *
   * @return \Triquanta\AccessibilityMonitor\Website
   */
  public function getWebsite() {
    return $this->website;
  }

  /**
   * Get the cms result.
   *
   * @return mixed
   */
  public function getCmsResult() {
    if ($this->determineCms) {
      return $this->cmsResult;
    }
    return FALSE;
  }

  /**
   * Get the google pagespeed result.
   *
   * @return mixed
   */
  public function getPageSpeedResult() {
    if ($this->performGooglePagespeed) {
      return $this->pageSpeedResult;
    }
    return FALSE;
  }

  /**
   * Escapes a URL for Solr.
   *
   * @param string $url
   *
   * @return string
   */
  protected function escapeUrlForSolr($url) {
    $escaped_url = str_replace(':', '_', $url);
    $escaped_url = str_replace('/', '_', $escaped_url);
    $escaped_url = str_replace('.', '_', $escaped_url);

    return $escaped_url;
  }

  /**
   * Get the wcag2 mapping array.
   *
   * This would be better as static variable, but that doesn't work with threads.
   *
   * @return array
   */
  protected function getWcag2Mapping() {
    $wcag20Mapping = [
      "wcag20:text-equiv-all" => "1.1.1",
      "wcag20:media-equiv-av-only-alt" => "1.2.1",
      "wcag20:media-equiv-captions" => "1.2.2",
      "wcag20:media-equiv-audio-desc" => "1.2.3",
      "wcag20:media-equiv-real-time-captions" => "1.2.4",
      "wcag20:media-equiv-audio-desc-only" => "1.2.5",
      "wcag20:media-equiv-sign" => "1.2.6",
      "wcag20:media-equiv-extended-ad" => "1.2.7",
      "wcag20:media-equiv-text-doc" => "1.2.8",
      "wcag20:media-equiv-live-audio-only" => "1.2.9",
      "wcag20:content-structure-separation-programmatic" => "1.3.1",
      "wcag20:content-structure-separation-sequence" => "1.3.2",
      "wcag20:content-structure-separation-understanding" => "1.3.3",
      "wcag20:visual-audio-contrast-without-color" => "1.4.1",
      "wcag20:visual-audio-contrast-dis-audio" => "1.4.2",
      "wcag20:visual-audio-contrast-contrast" => "1.4.3",
      "wcag20:visual-audio-contrast-scale" => "1.4.4",
      "wcag20:visual-audio-contrast-text-presentation" => "1.4.5",
      "wcag20:visual-audio-contrast7" => "1.4.6",
      "wcag20:visual-audio-contrast-noaudio" => "1.4.7",
      "wcag20:visual-audio-contrast-visual-presentation" => "1.4.8",
      "wcag20:visual-audio-contrast-text-images" => "1.4.9",
      "wcag20:keyboard-operation-keyboard-operable" => "2.1.1",
      "wcag20:keyboard-operation-trapping" => "2.1.2",
      "wcag20:keyboard-operation-all-funcs" => "2.1.3",
      "wcag20:time-limits-required-behaviors" => "2.2.1",
      "wcag20:time-limits-pause" => "2.2.2",
      "wcag20:time-limits-no-exceptions" => "2.2.3",
      "wcag20:time-limits-postponed" => "2.2.4",
      "wcag20:time-limits-server-timeout" => "2.2.5",
      "wcag20:seizure-does-not-violate" => "2.3.1",
      "wcag20:seizure-three-times" => "2.3.2",
      "wcag20:navigation-mechanisms-skip" => "2.4.1",
      "wcag20:navigation-mechanisms-title" => "2.4.2",
      "wcag20:navigation-mechanisms-focus-order" => "2.4.3",
      "wcag20:navigation-mechanisms-refs" => "2.4.4",
      "wcag20:navigation-mechanisms-mult-loc" => "2.4.5",
      "wcag20:navigation-mechanisms-descriptive" => "2.4.6",
      "wcag20:navigation-mechanisms-focus-visible" => "2.4.7",
      "wcag20:navigation-mechanisms-location" => "2.4.8",
      "wcag20:navigation-mechanisms-link" => "2.4.9",
      "wcag20:navigation-mechanisms-headings" => "2.4.10",
      "wcag20:meaning-doc-lang-id" => "3.1.1",
      "wcag20:meaning-other-lang-id" => "3.1.2",
      "wcag20:meaning-idioms" => "3.1.3",
      "wcag20:meaning-located" => "3.1.4",
      "wcag20:meaning-supplements" => "3.1.5",
      "wcag20:meaning-pronunciation" => "3.1.6",
      "wcag20:consistent-behavior-receive-focus" => "3.2.1",
      "wcag20:consistent-behavior-unpredictable-change" => "3.2.2",
      "wcag20:consistent-behavior-consistent-locations" => "3.2.3",
      "wcag20:consistent-behavior-consistent-functionality" => "3.2.4",
      "wcag20:consistent-behavior-no-extreme-changes-context" => "3.2.5",
      "wcag20:minimize-error-identified" => "3.3.1",
      "wcag20:minimize-error-cues" => "3.3.2",
      "wcag20:minimize-error-suggestions" => "3.3.3",
      "wcag20:minimize-error-reversible" => "3.3.4",
      "wcag20:minimize-error-context-help" => "3.3.5",
      "wcag20:minimize-error-reversible-all" => "3.3.6",
      "wcag20:ensure-compat-parses" => "4.1.1",
      "wcag20:ensure-compat-rsv" => "4.1.2"
    ];

    return $wcag20Mapping;
  }


}
