<?php
namespace Lvl;

//use Doctrine\DBAL\Logging\SQLLogger;

/**
 * Very basic tool to measure performance
 *
 * @todo make log writer flexible (support both zend log and symfony log)
 * @todo make log level configurable
 */
class Profiler
{
    const FOREGROUND_COLOR_CODE_YELLOW = '1;33';
    const FOREGROUND_COLOR_CODE_RED = '0;31';
    const FOREGROUND_COLOR_CODE_BROWN = '0;33';

    // @todo make these the same?
    const START_RECORD = 'start';
    const END_RECORD_NAME = '::END::';

    private static $bootstrapStartTime;

    /** @var callable */
    private $logCallback;

    /** @var array */
    private $records;

    private $title;

    private $isStarted;

    private $totalQueryCount = 0;

    private static $instance;

    public function __construct()
    {
        $this->records = array();
        if (self::$bootstrapStartTime) {
            $this->addRecord('Bootstrap / Routing', self::$bootstrapStartTime);
        }

        $this->isStarted = false;

        self::$instance = $this;
    }

    /**
     * @param callable $logCallback
     */
    public function setLogCallback($logCallback)
    {
        $this->logCallback = $logCallback;
    }

    /**
     * @return \Lvl\Profiler
     * @throws Exception
     */
    public static function getInstance()
    {
        if (empty(self::$instance)) {
            throw new Exception('Profiler is not yet instantiated');
        }

        return self::$instance;
    }

    public static function markBootstrapStart()
    {
        self::$bootstrapStartTime = microtime(true);
    }

    /**
     * Logs a Doctrine SQL statement.
     *
     * @param string $sql The SQL to be executed.
     * @param array $params The SQL parameters.
     * @param array $types The SQL parameter types.
     * @return void
     */
    public function startQuery($sql, array $params = null, array $types = null)
    {
        $this->totalQueryCount++;
    }

    /**
     * Mark the last started query as stopped. This can be used for timing of queries.
     *
     * @return void
     */
    public function stopQuery()
    {
        // do nothing
    }

    /**
     * @return bool
     */
    public function isStarted()
    {
        return $this->isStarted;
    }

    public function start($title)
    {
        $this->title = $title;

        if ($this->isStarted()) {
            throw new Exception('Profiler has already started');
        }

        $this->addRecord(self::START_RECORD);
        $this->isStarted = true;
    }

    /**
     * @param string $name
     * @throws Exception
     */
    public function startBlock($name)
    {
        if (!$this->isStarted()) {
            $this->start('Auto start');
//            throw new Exception('Profiler has not started yet');
        }

        $this->addRecord($name);
    }

    public function endBlock()
    {
        if (!$this->isStarted()) {
            throw new Exception('Profiler has not started yet');
        }

        if ($this->isPreviousEndRecord()) {
            throw new Exception('Profiler: block cannot be ended: no block was started');
        }


        $this->addRecord(self::END_RECORD_NAME);
    }


    private function isPreviousEndRecord()
    {
        if ($this->records[count($this->records) - 1]['name'] === self::END_RECORD_NAME) {
            return true;
        }
        return false;
    }


    private function addRecord($name, $time = null)
    {
        if (is_null($time)) {
            $time = microtime(true);
        }

        $recordCount = count($this->records);

        $currentMemUsage = memory_get_usage();
        if ($recordCount > 0) {
            $this->records[$recordCount - 1]['peakmem'] = memory_get_peak_usage();
            $previousMemUsage = $this->records[$recordCount - 1]['memusage'];
            $this->records[$recordCount - 1]['memusagediff'] = $currentMemUsage - $previousMemUsage;
        }


        $this->records[] = array(
            'memusagediff' => 0,
            'peakmem' => 0,
            'number' => $recordCount + 1,
            'name' => $name,
            'time' => $time,
            'memusage' => $currentMemUsage,
        );
    }

    /**
     * @return string
     */
    public function getReport()
    {
        $endTime = microtime(true);
        $topTimes = array();
        $startTime = $this->records[0]['time'];
        $totalTime = $endTime - $startTime;
        $lastEndTime = $endTime;
        foreach (array_reverse($this->records) as $record) {
            $taskTime = $lastEndTime - $record['time'];

            if ($record['name'] != self::END_RECORD_NAME) {
                $topTimes[] = array(
                    'memusage' => $record['memusage'],
                    'memusagediff' => $record['memusagediff'],
                    'peakmem' => $record['peakmem'],
                    'number' => $record['number'],
                    'name' => $record['name'],
                    'milliseconds' => round($taskTime * 1000),
                    'percentage' => round(($taskTime / $totalTime) * 100),
                );
            }

            $lastEndTime = $record['time'];
        }

        // Sort by time
        usort($topTimes, function ($a, $b) {
//            return $a['milliseconds'] < $b['milliseconds'] ? 1 : -1; // sort by processor time desc
//            return $a['peakmem'] < $b['peakmem'] ? 1 : -1; // sort by mem usage desc
            return $a['number'] > $b['number'] ? 1 : -1;
        });


        $totalPeakMem = memory_get_peak_usage();
        $totalPeakMemFormatted = str_pad(round($totalPeakMem / (1024 * 1024), 2) . 'MB', 8, ' ', STR_PAD_LEFT);
        $totalTimeFormatted = round($totalTime, 2);

        $uri = str_pad(substr($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],0, 107), 107, ' ', STR_PAD_RIGHT);

        $report = <<<TEXT


Profiled '{$this->title}' {$totalTimeFormatted}s total, {$totalPeakMemFormatted}
+----------------------------------------------------------------------------------------------------------------------+
+ REQUEST: {$uri} |
+-----+--------------+--------------+----------+-----------------------------------------------------------------------+
| Nr  | Proc Time    | Memo diff.   | Peak mem | Title                                                                 |
+-----+--------------+--------------+----------+-----------------------------------------------------------------------+

TEXT;
        foreach ($topTimes as $record) {
            $numberFormatted = str_pad($record['number'], 3, ' ', STR_PAD_LEFT);

            $peakMemFormatted = str_pad(round($record['peakmem'] / (1024 * 1024), 2) . 'MB', 8, ' ', STR_PAD_LEFT);

            $memusagediffPercentage = round(($record['memusagediff'] / $totalPeakMem) * 100);

            $memusagediffPercentageFormatted = str_pad('(' . $memusagediffPercentage . '%)', 5, ' ', STR_PAD_LEFT);
            $memDiffFormatted = str_pad(round($record['memusagediff'] / (1024 * 1024), 2) . 'MB '. $memusagediffPercentageFormatted, 12, ' ', STR_PAD_LEFT);

            // @todo make this optional
            // Show memory consuming blocks in different colors
            $memColorCode = $this->mapPercentageToColor($memusagediffPercentage);

            if ($memColorCode) {
                $memDiffFormatted = $this->colorString($memDiffFormatted, $memColorCode);
            }

            $percentage = $record['percentage'];
            $percentageFormatted = str_pad('(' . $percentage . '%)', 5, ' ', STR_PAD_LEFT);
            $timeFormatted = str_pad($record['milliseconds'] . "ms " . $percentageFormatted, 12, ' ', STR_PAD_LEFT);

            // @todo make this optional
            // Show Time consuming blocks in different colors
            $timeColorCode = $this->mapPercentageToColor($percentage);
            if ($timeColorCode) {
                $timeFormatted = $this->colorString($timeFormatted, $timeColorCode);
            }

            $nameFormatted = str_pad(substr($record['name'], 0, 69), 69, ' ', STR_PAD_RIGHT);
            $report .= $row = "| {$numberFormatted} | {$timeFormatted} | {$memDiffFormatted} | {$peakMemFormatted} | {$nameFormatted} |" . PHP_EOL;
        }

$report .= <<<TEXT
+-----+--------------+--------------+----------+-----------------------------------------------------------------------+
TEXT;

        return $report;
    }

    /**
     * @param $percentage
     * @return string
     */
    private function mapPercentageToColor($percentage)
    {
        if ($percentage > 40) {
            return self::FOREGROUND_COLOR_CODE_RED;
        } elseif ($percentage > 20) {
            return self::FOREGROUND_COLOR_CODE_BROWN;
        } elseif ($percentage > 10) {
            return self::FOREGROUND_COLOR_CODE_YELLOW;
        }

        return null;
    }

    private function colorString($string, $colorCode)
    {
        return "\033[" . $colorCode . "m" . $string . "\033[0m";
    }


    public function logReport()
    {
        $logCallback = $this->logCallback;
        foreach (explode(PHP_EOL, $this->getReport()) as $line) {
            $logCallback($line);
        }
    }
}