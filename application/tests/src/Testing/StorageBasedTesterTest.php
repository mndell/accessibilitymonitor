<?php

/**
 * @file
 * Contains \Triquanta\Tests\AccessibilityMonitor\Testing|StorageBasedTesterTest.
 */

namespace Triquanta\AccessibilityMonitor\Testing;

use Triquanta\AccessibilityMonitor\StorageException;
use Triquanta\AccessibilityMonitor\Url;

/**
 * @coversDefaultClass \Triquanta\AccessibilityMonitor\Testing\StorageBasedTester
 */
class StorageBasedTesterTest extends \PHPUnit_Framework_TestCase
{

    /**
     * The logger.
     *
     * @var \Psr\Log\LoggerInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $logger;

    /**
     * The StatsD logger.
     *
     * @var \Triquanta\AccessibilityMonitor\StatsD
     */
    protected $statsD;

    /**
     * The maximum number of failed test runs per URL.
     *
     * @var int
     */
    protected $maxFailedTestRuns;

    /**
     * The result storage.
     *
     * @var \Triquanta\AccessibilityMonitor\StorageInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $resultStorage;

    /**
     * The class under test.
     *
     * @var \Triquanta\AccessibilityMonitor\Testing\StorageBasedTester
     */
    protected $sut;

    /**
     * The tester.
     *
     * @var \Triquanta\AccessibilityMonitor\Testing\TesterInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $tester;

    public function setUp()
    {
        $this->maxFailedTestRuns = mt_rand(2, 5);

        $this->logger = $this->getMock('\Psr\Log\LoggerInterface');

        $this->statsD = $this->getMock('\Triquanta\AccessibilityMonitor\StatsDInterface');

        $this->resultStorage = $this->getMock('\Triquanta\AccessibilityMonitor\StorageInterface');

        $this->tester = $this->getMock('\Triquanta\AccessibilityMonitor\Testing\TesterInterface');

        $this->sut = new StorageBasedTester($this->logger, $this->statsD, $this->tester, $this->resultStorage, $this->maxFailedTestRuns);
    }

    /**
     * @covers ::__construct
     */
    public function testConstruct()
    {
        $this->sut = new StorageBasedTester($this->logger, $this->statsD, $this->tester, $this->resultStorage, $this->maxFailedTestRuns);
    }

    /**
     * @covers ::run
     *
     * @dataProvider providerRunWithNegativeOutcomeOrException
     */
    public function testRun($expectedOutcome, \PHPUnit_Framework_MockObject_Stub $testRunStub, $expectedTestingStatus)
    {
        $url = new Url();
        $url->setFailedTestCount($expectedTestingStatus === TestingStatusInterface::STATUS_SCHEDULED_FOR_RETEST ? $this->maxFailedTestRuns - 2: $this->maxFailedTestRuns);

        $this->tester->expects($this->once())
            ->method('run')
            ->with($url)
            ->will($testRunStub);

        $this->assertSame($expectedOutcome, $this->sut->run($url));
        $this->assertSame($expectedTestingStatus, $url->getTestingStatus());
    }

    /**
     * Provides data to self::testRunWithNegativeOutcomeOrException().
     */
    public function providerRunWithNegativeOutcomeOrException() {
        return [
          [true, $this->returnValue(true), TestingStatusInterface::STATUS_TESTED],
          [false, $this->returnValue(false), TestingStatusInterface::STATUS_ERROR],
          [false, $this->returnValue(false), TestingStatusInterface::STATUS_SCHEDULED_FOR_RETEST],
          [false, $this->throwException(new \Exception()), TestingStatusInterface::STATUS_ERROR],
          [false, $this->throwException(new \Exception()), TestingStatusInterface::STATUS_SCHEDULED_FOR_RETEST],
        ];
    }

}
