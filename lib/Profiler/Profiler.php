<?php
namespace Lvl\Profiler;

//use Doctrine\DBAL\Logging\SQLLogger;

/**
 * Very basic tool to measure performance
 *
 * @todo Support query profiling/logging
 */
class Profiler
{
    // @todo make these the same?
    const RECORD_NAME_START = 'start';
    const RECORD_NAME_END = '::END::';
    const RECORD_NAME_BOOTSTRAP = 'Bootstrap / Routing';

    private static $bootstrapStartTime;

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
            $this->addRecord(self::RECORD_NAME_BOOTSTRAP, self::$bootstrapStartTime);
        }

        $this->isStarted = false;

        self::$instance = $this;
    }

    /**
     * @return array
     */
    public function getRecords()
    {
        return $this->records;
    }

    /**
     * @return \Lvl\Profiler\Profiler
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

        $this->addRecord(self::RECORD_NAME_START);
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

    /**
     * Use this for profiling blocks that represent time spent on waiting for external system, resource etc.
     *
     * @param string $name
     * @throws Exception
     */
    public function startExternalBlock($name)
    {
        if (!$this->isStarted()) {
            $this->start('Auto start');
        }

        $this->addRecord($name, null, true);
    }

    public function endBlock()
    {
        if (!$this->isStarted()) {
            throw new Exception('Profiler has not started yet');
        }

        if ($this->isPreviousEndRecord()) {
            throw new Exception('Profiler: block cannot be ended: no block was started');
        }


        $this->addRecord(self::RECORD_NAME_END);
    }


    private function isPreviousEndRecord()
    {
        if ($this->records[count($this->records) - 1]['name'] === self::RECORD_NAME_END) {
            return true;
        }
        return false;
    }

    /**
     * @param $name
     * @param null $time
     * @param bool $isExternal
     */
    private function addRecord($name, $time = null, $isExternal = false)
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
            'isExternal' => $isExternal
        );
    }
}