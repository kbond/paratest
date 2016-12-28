<?php
namespace ParaTest\Runners\PHPUnit;

use ParaTest\Logging\LogInterpreter;
use ParaTest\Logging\JUnit\Reader;

/**
 * Class ResultPrinter
 *
 * Used for outputing ParaTest results
 *
 * @package ParaTest\Runners\PHPUnit
 */
class ResultPrinter
{
    /**
     * A collection of ExecutableTest objects
     *
     * @var array
     */
    protected $suites = array();

    /**
     * @var \ParaTest\Logging\LogInterpreter
     */
    protected $results;

    /**
     * The number of tests results currently printed.
     * Used to determine when to tally current results
     * and start a new row
     *
     * @var int
     */
    protected $numTestsWidth;

    /**
     * Used for formatting results to a given width
     *
     * @var int
     */
    protected $maxColumn;

    /**
     * The total number of cases to be run
     *
     * @var int
     */
    protected $totalCases = 0;

    /**
     * The current column being printed to
     *
     * @var int
     */
    protected $column = 0;

    /**
     * @var \PHP_Timer
     */
    protected $timer;

    /**
     * The total number of cases printed so far
     *
     * @var int
     */
    protected $casesProcessed = 0;

    /**
     * Whether to display a red or green bar
     *
     * @var bool
     */
    protected $colors;

    /**
     * Warnings generated by the cases
     *
     * @var array
     */
    protected $warnings = array();

    /**
     * Number of columns
     *
     * @var integer
     */
    protected $numberOfColumns = 80;

    /**
     * Number of skipped or incomplete tests
     *
     * @var integer
     */
    protected $totalSkippedOrIncomplete = 0;

    /**
     * Do we need to try to process skipped/incompleted tests.
     *
     * @var boolean
     */
    protected $processSkipped = false;

    public function __construct(LogInterpreter $results)
    {
        $this->results = $results;
        $this->timer = new \PHP_Timer();
    }

    /**
     * Adds an ExecutableTest to the tracked results
     *
     * @param ExecutableTest $suite
     * @return $this
     */
    public function addTest(ExecutableTest $suite)
    {
        $this->suites[] = $suite;
        $increment = $suite->getTestCount();
        $this->totalCases = $this->totalCases + $increment;

        return $this;
    }

    /**
     * Initializes printing constraints, prints header
     * information and starts the test timer
     *
     * @param Options $options
     */
    public function start(Options $options)
    {
        $this->numTestsWidth = strlen((string) $this->totalCases);
        $this->maxColumn = $this->numberOfColumns
                         + (DIRECTORY_SEPARATOR == "\\" ? -1 : 0) // fix windows blank lines
                         - strlen($this->getProgress());
        printf(
            "\nRunning phpunit in %d process%s with %s%s\n\n",
            $options->processes,
            $options->processes > 1 ? 'es' : '',
            $options->phpunit,
            $options->functional ? '. Functional mode is ON.' : ''
        );
        if (isset($options->filtered['configuration'])) {
            printf("Configuration read from %s\n\n", $options->filtered['configuration']->getPath());
        }
        $this->timer->start();
        $this->colors = $options->colors;
        $this->processSkipped = $this->isSkippedIncompleTestCanBeTracked($options);
    }

    /**
     * @param string $string
     */
    public function println($string = "")
    {
        $this->column = 0;
        print("$string\n");
    }

    /**
     * Prints all results and removes any log files
     * used for aggregating results
     */
    public function flush()
    {
        $this->printResults();
        $this->clearLogs();
    }

    /**
     * Print final results
     */
    public function printResults()
    {
        print $this->getHeader();
        print $this->getErrors();
        print $this->getFailures();
        print $this->getWarnings();
        print $this->getFooter();
    }

    /**
     * Prints the individual "quick" feedback for run
     * tests, that is the ".EF" items
     *
     * @param ExecutableTest $test
     */
    public function printFeedback(ExecutableTest $test)
    {
        try {
            $reader = new Reader($test->getTempFile());
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException(sprintf(
                "%s\n" .
                "The process: %s\n" .
                "This means a PHPUnit process was unable to run \"%s\"\n" ,
                $e->getMessage(),
                $test->getLastCommand(),
                $test->getPath()
            ));
        }
        $this->results->addReader($reader);
        $this->processReaderFeedback($reader, $test->getTestCount());
        $this->printTestWarnings($test);
    }

    /**
     * Returns the header containing resource usage
     *
     * @return string
     */
    public function getHeader()
    {
        return "\n\n" . $this->timer->resourceUsage() . "\n\n";
    }

    /**
     * Add an array of warning strings. These cause the test run to be shown
     * as failed
     */
    public function addWarnings(array $warnings)
    {
        $this->warnings = array_merge($this->warnings, $warnings);
    }

    /**
     * Returns warning messages as a string
     */
    public function getWarnings()
    {
        return $this->getDefects($this->warnings, 'warning');
    }

    /**
     * Whether the test run is successful and has no warnings
     *
     * @return bool
     */
    public function isSuccessful()
    {
        return $this->results->isSuccessful() && count($this->warnings) == 0;
    }

    /**
     * Return the footer information reporting success
     * or failure
     *
     * @return string
     */
    public function getFooter()
    {
        return $this->isSuccessful()
                    ? $this->getSuccessFooter()
                    : $this->getFailedFooter();
    }

    /**
     * Returns the failure messages
     *
     * @return string
     */
    public function getFailures()
    {
        $failures = $this->results->getFailures();

        return $this->getDefects($failures, 'failure');
    }

    /**
     * Returns error messages
     *
     * @return string
     */
    public function getErrors()
    {
        $errors = $this->results->getErrors();

        return $this->getDefects($errors, 'error');
    }

    /**
     * Returns the total cases being printed
     *
     * @return int
     */
    public function getTotalCases()
    {
        return $this->totalCases;
    }

    /**
     * Process reader feedback and print it.
     *
     * @param  Reader $reader
     * @param  int    $expectedTestCount
     */
    protected function processReaderFeedback($reader, $expectedTestCount)
    {
        $feedbackItems = $reader->getFeedback();

        $actualTestCount = count($feedbackItems);

        $this->processTestOverhead($actualTestCount, $expectedTestCount);

        foreach ($feedbackItems as $item) {
            $this->printFeedbackItem($item);
        }

        if ($this->processSkipped) {
            $this->printSkippedAndIncomplete($actualTestCount, $expectedTestCount);
        }
    }

    /**
     * Prints test warnings.
     *
     * @param  ExecutableTest $test
     */
    protected function printTestWarnings($test)
    {
        $warnings = $test->getWarnings();
        if ($warnings) {
            $this->addWarnings($warnings);
            foreach ($warnings as $warning) {
                $this->printFeedbackItem('W');
            }
        }
    }

    /**
     * Is skipped/incomplete amount can be properly processed.
     *
     * @todo Skipped/Incomplete test tracking available only in functional mode for now
     *       or in regular mode but without group/exclude-group filters.
     *
     * @return boolean
     */
    protected function isSkippedIncompleTestCanBeTracked($options)
    {
        return $options->functional
            || (empty($options->groups) && empty($options->excludeGroups));
    }

    /**
     * Process test overhead.
     *
     * In some situations phpunit can return more tests then we expect and in that case
     * this method correct total amount of tests so paratest progress will be auto corrected.
     *
     * @todo May be we need to throw Exception here instead of silent correction.
     *
     * @param  int $actualTestCount
     * @param  int $expectedTestCount
     */
    protected function processTestOverhead($actualTestCount, $expectedTestCount)
    {
        $overhead = $actualTestCount - $expectedTestCount;
        if ($this->processSkipped) {
            if ($overhead > 0) {
                $this->totalCases += $overhead;
            } else {
                $this->totalSkippedOrIncomplete += -$overhead;
            }
        } else {
            $this->totalCases += $overhead;
        }
    }

    /**
     * Prints S for skipped and incomplete tests.
     *
     * If for some reason process return less tests than expected then we threat all remaining
     * as skipped or incomplete and print them as skipped (S letter)
     *
     * @param  int $actualTestCount
     * @param  int $expectedTestCount
     */
    protected function printSkippedAndIncomplete($actualTestCount, $expectedTestCount)
    {
        $overhead = $expectedTestCount - $actualTestCount;
        if ($overhead > 0) {
            for ($i = 0; $i < $overhead; $i++) {
                $this->printFeedbackItem("S");
            }
        }
    }

    /**
     * Prints a single "quick" feedback item and increments
     * the total number of processed cases and the column
     * position
     *
     * @param $item
     */
    protected function printFeedbackItem($item)
    {
        print $item;
        $this->column++;
        $this->casesProcessed++;
        if ($this->column == $this->maxColumn) {
            print $this->getProgress();
            $this->println();
        }
    }

    /**
     * Method that returns a formatted string
     * for a collection of errors or failures
     *
     * @param array $defects
     * @param $type
     * @return string
     */
    protected function getDefects(array $defects, $type)
    {
        $count = sizeof($defects);
        if ($count == 0) {
            return '';
        }
        $output = sprintf(
            "There %s %d %s%s:\n",
            ($count == 1) ? 'was' : 'were',
            $count,
            $type,
            ($count == 1) ? '' : 's'
        );

        for ($i = 1; $i <= sizeof($defects); $i++) {
            $output .= sprintf("\n%d) %s\n", $i, $defects[$i - 1]);
        }

        return $output;
    }

    /**
     * Prints progress for large test collections
     */
    protected function getProgress()
    {
        return sprintf(
            ' %' . $this->numTestsWidth . 'd / %' . $this->numTestsWidth . 'd (%3s%%)',
            $this->casesProcessed,
            $this->totalCases,
            floor(($this->totalCases ? $this->casesProcessed / $this->totalCases : 0) * 100)
        );
    }

    /**
     * Get the footer for a test collection that had tests with
     * failures or errors
     *
     * @return string
     */
    private function getFailedFooter()
    {
        $formatString = "FAILURES!\nTests: %d, Assertions: %d, Failures: %d, Errors: %d.\n";

        return "\n" . $this->red(
            sprintf(
                $formatString,
                $this->results->getTotalTests(),
                $this->results->getTotalAssertions(),
                $this->results->getTotalFailures(),
                $this->results->getTotalErrors()
            )
        );
    }

    /**
     * Get the footer for a test collection containing all successful
     * tests
     *
     * @return string
     */
    private function getSuccessFooter()
    {
        $tests = $this->totalCases;
        $asserts = $this->results->getTotalAssertions();

        if ($this->totalSkippedOrIncomplete > 0) {
            // phpunit 4.5 produce NOT plural version for test(s) and assertion(s) in that case
            // also it shows result in standard color scheme
            return sprintf(
                "OK, but incomplete, skipped, or risky tests!\n"
                . "Tests: %d, Assertions: %d, Incomplete: %d.\n",
                $tests,
                $asserts,
                $this->totalSkippedOrIncomplete
            );
        } else {
            // phpunit 4.5 produce plural version for test(s) and assertion(s) in that case
            // also it shows result as black text on green background
            return $this->green(sprintf(
                "OK (%d test%s, %d assertion%s)\n",
                $tests,
                ($tests == 1) ? '' : 's',
                $asserts,
                ($asserts == 1) ? '' : 's'
            ));
        }
    }

    private function green($text)
    {
        if ($this->colors) {
            return "\x1b[30;42m\x1b[2K"
                . $text
                . "\x1b[0m\x1b[2K";
        }
        return $text;
    }

    private function red($text)
    {
        if ($this->colors) {
            return "\x1b[37;41m\x1b[2K"
                . $text
                . "\x1b[0m\x1b[2K";
        }
        return $text;
    }

    /**
     * Deletes all the temporary log files for ExecutableTest objects
     * being printed
     */
    private function clearLogs()
    {
        foreach ($this->suites as $suite) {
            $suite->deleteFile();
        }
    }
}
