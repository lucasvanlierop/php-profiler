<?php
namespace Lvl\Profiler;

$vendorDir = realpath(__DIR__ . '/../../../../../');
require $vendorDir .'/jlogsdon/cli/lib/cli/cli.php';
\cli\register_autoload();

/**
 * @todo Move Logging functions to a separate class
 */
class Reporter
{
    const FOREGROUND_COLOR_CODE_YELLOW = '%Y';
    const FOREGROUND_COLOR_CODE_RED = '%r';
    const FOREGROUND_COLOR_CODE_BROWN = '%y';
    const COLOR_END = '%n';

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
     * @param array $metadata
     * @return string
     */
    public function printReport(array $records, array $metadata)
    {
        $topTimes = array();
        $timeStart = $records[0]['timeStart'];
        $endRecord = end($records);
        $totalTime = $endRecord['timeEnd'] - $timeStart;
        $totalExternalTime = 0;
        $memPeak = 0;
        foreach ($records as $record) {
            $taskTime = $record['timeEnd'] - $record['timeStart'];

            if ($record['isExternal']) {
                $totalExternalTime += $taskTime;
            }

            // Use peak memory instead of end memory if peak memory usage has increased during execution
            if ($record['memPeak'] > $memPeak) {
                $memEnd = $record['memPeak'];
            } else {
                $memEnd = $record['memEnd'];
            }
            $record['memDiff'] = $memEnd - $record['memStart'];

            if ($record['name'] != Profiler::RECORD_NAME_END) {
                $topTimes[] = array(
                    'memDiff' => $record['memDiff'],
                    'memPeak' => $record['memPeak'],
                    'number' => $record['number'],
                    'name' => $record['name'],
                    'timeDiffMs' => round($taskTime * 1000),
                    'timeDiffPercentage' => round(($taskTime / $totalTime) * 100),
                    'isExternal' => $record['isExternal']
                );
            }

            $memPeak = $record['memPeak'];
        }

        // Sort by time
        usort($topTimes, function ($a, $b) {
//            return $a['timeDiffMs'] < $b['timeDiffMs'] ? 1 : -1; // sort by processor time desc
//            return $a['memPeak'] < $b['memPeak'] ? 1 : -1; // sort by mem usage desc
            return $a['number'] > $b['number'] ? 1 : -1;
        });


        $totalPeakMem = $endRecord['memPeak'];
        $totalPeakMemFormatted = round($totalPeakMem / (1024 * 1024), 2) . 'MB';
        $totalTimeFormatted = round($totalTime, 2);

        $totalExternalTimeFormatted = round($totalExternalTime, 2);

        $metadataFormatted = '';
        foreach($metadata as $metadataName => $metadataValue) {
            $metadataFormatted .= "+ {$metadataName}: {$metadataValue}" . PHP_EOL;
        }

        $report = <<<TEXT


Profiling finished: {$totalTimeFormatted}s total, {$totalExternalTimeFormatted}s external, {$totalPeakMemFormatted}
{$metadataFormatted}

TEXT;

        echo $report;

        $tableData = array();

        foreach ($topTimes as $record) {
            $numberFormatted = $record['number'];

            $memPeakFormatted = round($record['memPeak'] / (1024 * 1024), 2) . 'MB';

            $memDiffPercentage = round(($record['memDiff'] / $totalPeakMem) * 100);

            $memDiffPercentageFormatted = '(' . $memDiffPercentage . 'p)';
            $memDiffFormatted = round($record['memDiff'] / (1024 * 1024), 2) . 'MB ';

            // @todo make this optional
            // Show memory consuming blocks in different colors
            $memColorCode = $this->mapPercentageToColor($memDiffPercentage);

            if ($memColorCode) {
                $memDiffFormatted = $this->colorString($memDiffFormatted, $memColorCode);
            }

            $timeDiffPercentage = $record['timeDiffPercentage'];
            // @todo make table printer handle '%' char, this causes sprintf errors
            $timeDiffPercentageFormatted = $timeDiffPercentage . 'p';
            $timeFormatted = $record['timeDiffMs'] . "ms";

            // @todo make this optional
            // Show Time consuming blocks in different colors
            $timeColorCode = $this->mapPercentageToColor($timeDiffPercentage);
            if ($timeColorCode) {
                $timeFormatted = $this->colorString($timeFormatted, $timeColorCode);
            }

            $name = $record['name'];
            if ($record['isExternal']) {
                $name = 'EXT: ' . $name;
            }
            $nameFormatted = substr($name, 0, 69);

            $tableData[] = array(
                $numberFormatted,
                $timeFormatted,
                $timeDiffPercentageFormatted,
                $memDiffFormatted,
                $memDiffPercentageFormatted,
                $memPeakFormatted,
                $nameFormatted
            );
        }

        $headers = array(
            'Nr',
            'Proc Time',
            'Proc Time Percentage',
            'Memo diff.',
            'Memo diff. percentage',
            'Peak mem',
            'Title'
        );
        $table = new \cli\Table();
        $table->setHeaders($headers);
        $table->setRows($tableData);

        // @todo find out how to return this
        $table->display();
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

    /**
     * Adds tokens that will be replaced by \cli\Colors::colorize()
     *
     * @param string $string
     * @param string $colorCode
     * @return string
     */
    private function colorString($string, $colorCode)
    {
        return $colorCode . $string . self::COLOR_END;
    }

    /**
     * Writes report to log
     *
     * @param array $records
     * @param array $metadata
     */
    public function logReport(array $records, array $metadata)
    {
        $logCallback = $this->logCallback;
        foreach (explode(PHP_EOL, $this->getReport($records, $metadata)) as $line) {
            $logCallback($line);
        }
    }
}