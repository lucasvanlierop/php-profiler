<?php
namespace Lvl\Profiler;

use Exception;

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

    /**
     * @var array
     */
    private static $bootstrapRecord;

    /** @var array */
    private $metadata;

    /** @var array */
    private $records;

    private $isStarted;

    private $totalQueryCount = 0;

    private static $instance;

    public function __construct()
    {
        $this->records = array();

        if (self::$bootstrapRecord) {
            // Create a  bootstrap start record using information set earlier
            $this->addRecord(self::RECORD_NAME_BOOTSTRAP);
            $currentRecord = &$this->getCurrentRecord();
            $currentRecord = array_merge($currentRecord, self::$bootstrapRecord);
        }

        $this->isStarted = false;

        self::$instance = $this;
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

    /**
     * Returns a reference to the current profile record if any exists
     *
     * @return null|array
     */
    private function &getCurrentRecord() {
        $currentyIndex = count($this->records) - 1;

        if (isset($this->records[$currentyIndex])) {
            $currentRecord = &$this->records[$currentyIndex];
            return $currentRecord;
        }
    }

    /**
     * @param string $key
     * @param string $value
     */
    public function setMetadataValue($key, $value)
    {
        $this->metadata[$key] = $value;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        // @todo check if record hasn't already been ended long before end of execution
        $this->startBlock('end');
        $this->endCurrentRecord();

        return array(
            'metadata' => $this->metadata,
            'records' => $this->records
        );
    }

    /**
     * Register time and memory usage at time of bootstrapping
     */
    public static function markBootstrapStart()
    {
        self::$bootstrapRecord['startTime'] = microtime(true);
        self::$bootstrapRecord['startMem'] = memory_get_usage();
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

    public function start()
    {
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

        $this->addRecord($name, true);
    }

    public function endBlock()
    {
        if (!$this->isStarted()) {
            throw new Exception('Profiler has not started yet');
        }

        if ($this->isPreviousEndRecord()) {
            throw new Exception('Profiler: block cannot be ended: no block was started');
        }

        $this->endCurrentRecord();
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
     * @param bool $isExternal
     */
    private function addRecord($name, $isExternal = false)
    {
        $this->endCurrentRecord();

        $this->records[] = array(
            'memPeak' => 0,
            'number' => count($this->records),
            'name' => $name,
            'startTime' => microtime(true),
            'endTime' => 0,
            'startMem' => memory_get_usage(),
            'endMem' => 0,
            'isExternal' => $isExternal,
            'isEnded' => false
        );
    }

    /**
     */
    private function endCurrentRecord()
    {
        $currentRecord = &$this->getCurrentRecord();

        if (!is_array($currentRecord)) {
            return;
        }

        if ($currentRecord['isEnded']) {
            return;
        }

        // Set peak memory usage for previous block
        $currentRecord['endTime'] = microtime(true);
        $currentRecord['endMem'] = memory_get_usage();
        $currentRecord['memPeak'] = memory_get_peak_usage();
        $currentRecord['isEnded']  = true;
    }
}