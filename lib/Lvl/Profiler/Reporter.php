<?php
namespace Lvl\Profiler;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @todo Move Logging functions to a separate class
 */
class Reporter
{
    const FOREGROUND_COLOR_CODE_YELLOW = 'fg=yellow';
    const FOREGROUND_COLOR_CODE_RED = 'fg=red';
    const FOREGROUND_COLOR_CODE_BROWN = 'fg=brown;options=bold';

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
    public function createReport(array $records, array $metadata)
    {
        $topTimes = array();
        $timeStart = $records[0]['timeStart'];
        $endRecord = end($records);
        $totalTime = $endRecord['timeEnd'] - $timeStart;
        $totalTimeCurrent = 0;
        $totalExternalTime = 0;
        $memPeak = 0;
        foreach ($records as $record) {
            $taskTime = $record['timeEnd'] - $record['timeStart'];

            $totalTimeCurrent += $taskTime;
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
                    'timeTotalMs' => $totalTimeCurrent,
                    'isExternal' => $record['isExternal'],
                    'includedFiles' => $record['includedFiles']
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

        $tableData = array();

        foreach ($topTimes as $record) {
            $numberFormatted = $record['number'];

            $memPeakFormatted = round($record['memPeak'] / (1024 * 1024), 2) . 'MB';

            $memDiffPercentage = round(($record['memDiff'] / $totalPeakMem) * 100);

            $memDiffPercentageFormatted = $memDiffPercentage . '%';
            $memDiffFormatted = round($record['memDiff'] / (1024 * 1024), 2) . 'MB ';

            // @todo make this optional
            // Show memory consuming blocks in different colors
            $memColorCode = $this->mapPercentageToColor($memDiffPercentage);

            $nameColorCode = null;
            if ($memColorCode) {
                $memDiffFormatted = $this->colorString($memDiffFormatted, $memColorCode);
                $memDiffPercentageFormatted = $this->colorString($memDiffPercentageFormatted, $memColorCode);
                $memPeakFormatted = $this->colorString($memPeakFormatted, $memColorCode);
                $nameColorCode = $memColorCode;
            }

            $timeDiffPercentage = $record['timeDiffPercentage'];
            $timeDiffPercentageFormatted = $timeDiffPercentage . '%';
            $timeFormatted = $record['timeDiffMs'] . "ms";
            $timeTotalFormatted = (round($record['timeTotalMs'],2) * 1000) . 'ms';

            // @todo make this optional
            // Show Time consuming blocks in different colors
            $timeColorCode = $this->mapPercentageToColor($timeDiffPercentage);
            if ($timeColorCode) {
                $timeFormatted = $this->colorString($timeFormatted, $timeColorCode);
                $timeDiffPercentageFormatted = $this->colorString($timeDiffPercentageFormatted, $timeColorCode);
                $timeTotalFormatted = $this->colorString($timeTotalFormatted, $timeColorCode);

                if ($timeDiffPercentage > $memDiffPercentage) {
                    $nameColorCode = $timeColorCode;
                }
            }

            $name = $record['name'];
            if ($record['isExternal']) {
                $name = 'EXT: ' . $name;
            }
            $nameFormatted = substr($name, 0, 69);

            $includedFilesFormatted = implode(PHP_EOL, $record['includedFiles']);

            $includedFilesFormatted = str_replace('/opt/www-on-host/OpenConext-engineblock/', '', $includedFilesFormatted);

            // Colorize name with color of time or memory
            if ($nameColorCode) {
                $nameFormatted = $this->colorString($nameFormatted, $nameColorCode);
                $includedFilesFormatted = $this->colorString($includedFilesFormatted, $nameColorCode);
            }

            $includedFilesFormatted = '';

            $tableData[] = array(
                $numberFormatted,
                $timeFormatted,
                $timeDiffPercentageFormatted,
                $timeTotalFormatted,
                $memDiffFormatted,
                $memDiffPercentageFormatted,
                $memPeakFormatted,
                $nameFormatted,
                $includedFilesFormatted
            );
        }

        $headers = array(
            'Nr',
            'Proc Time',
            'Proc Time Percentage',
            'Proc Time Total',
            'Memo diff.',
            'Memo diff. percentage',
            'Peak mem',
            'Title',
            'Included Files'
        );
        $output = new BufferedOutput();
        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($tableData);

        // @todo find out how to return this
        $table->render();

        return $report . $output->fetch();
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
     * Adds tokens that will be replaced by Symfon Console
     *
     * @param string $string
     * @param string $colorCode
     * @return string
     * @todo colors are removed by symfony tabe
     */
    private function colorString($string, $colorCode)
    {
        return "<{$colorCode}>{$string}</{$colorCode}>";
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
        foreach (explode(PHP_EOL, $this->createReport($records, $metadata)) as $line) {
            $logCallback($line . PHP_EOL);
        }
    }
}