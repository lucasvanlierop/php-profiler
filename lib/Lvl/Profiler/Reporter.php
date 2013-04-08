<?php
namespace Lvl\Profiler;

class Reporter
{
    const FOREGROUND_COLOR_CODE_YELLOW = '1;33';
    const FOREGROUND_COLOR_CODE_RED = '0;31';
    const FOREGROUND_COLOR_CODE_BROWN = '0;33';

    /** @var callable */
    private $logCallback;

    /**
     * @param callable $logCallback
     */
    public function setLogCallback($logCallback)
    {
        $this->logCallback = $logCallback;
    }

    /**
     * @param array $records
     * @return string
     */
    public function getReport(array $records)
    {
        $endTime = microtime(true);
        $topTimes = array();
        $startTime = $records[0]['time'];
        $totalTime = $endTime - $startTime;
        $totalExternalTime = 0;
        $lastEndTime = $endTime;
        foreach (array_reverse($records) as $record) {
            $taskTime = $lastEndTime - $record['time'];

            if ($record['isExternal']) {
                $totalExternalTime += $taskTime;
            }

            if ($record['name'] != Profiler::RECORD_NAME_END) {
                $topTimes[] = array(
                    'memusage' => $record['memusage'],
                    'memusagediff' => $record['memusagediff'],
                    'peakmem' => $record['peakmem'],
                    'number' => $record['number'],
                    'name' => $record['name'],
                    'milliseconds' => round($taskTime * 1000),
                    'percentage' => round(($taskTime / $totalTime) * 100),
                    'isExternal' => $record['isExternal']
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

        $totalExternalTimeFormatted = round($totalExternalTime, 2);

        $uri = str_pad(substr($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],0, 107), 107, ' ', STR_PAD_RIGHT);

        $report = <<<TEXT


Profiled '{$this->title}' {$totalTimeFormatted}s total, {$totalExternalTimeFormatted}s external, {$totalPeakMemFormatted}
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

            $name = $record['name'];
            if ($record['isExternal']) {
                $name = 'EXT: ' . $name;
            }
            $nameFormatted = str_pad(substr($name, 0, 69), 69, ' ', STR_PAD_RIGHT);
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


    public function logReport(array $records)
    {
        $logCallback = $this->logCallback;
        foreach (explode(PHP_EOL, $this->getReport($records)) as $line) {
            $logCallback($line);
        }
    }
}
