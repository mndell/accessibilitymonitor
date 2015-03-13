<?php

/**
 * @file
 * Contains \Triquanta\AccessibilityMonitor\Testing|TesterInterface.
 */

namespace Triquanta\AccessibilityMonitor\Testing;

use Triquanta\AccessibilityMonitor\Url;

/**
 * Defines a tester.
 */
interface TesterInterface
{

    /**
     * Runs the test for a URL.
     *
     * @param \Triquanta\AccessibilityMonitor\Url $url
     */
    public function run(Url $url);

}
