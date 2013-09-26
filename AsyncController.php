<?php
/**
 * AsyncController enables parallel/pseudo-multithreaded asyncronous
 * processing.
 *
 * Async works using techniques outlined here:
 * http://phplens.com/phpeverywhere/?q=node/view/254
 * It functions through calling a script locally and running it; this runs
 * these scripts in parallel.
 */
class AsyncController
{
	/**
	 * @var _vars Array of internal variables.
	 */
	protected $_vars = array();

	/**
	 * @var $waiting_queue Queue of jobs waiting to run.
	 */
	protected $_waiting_queue = array();

	/**
	 * @var $running_queue Queue of jobs running or finished.
	 */
	protected $_running_queue = array();

	/**
	 * @var $finished_count Number of finished jobs.
	 */
	protected $_finished_count = 0;

	/**
	 * @var $_job_count Number of jobs to do.
	 */
	protected $_job_count = 0;

	/**
	 * @var $log String of log entries.
	 */
	protected $_log = "";

	/**
	 * @var $slots int Number of available slots to run jobs in.
	 */
	protected $_slots = 5;

	/**
	 * Sets default values for configuration purposes.
	 */
	function __construct($max_number_of_jobs = 5, $min_load = 20, $timeout = 0, $log = 'echo') {
		$this->_vars = array(
			'max_number_of_jobs' => $max_number_of_jobs,
			'min_load' => $min_load,
			'timeout'=>$timeout,
			'log'=>$log);
		$this->_slots = $max_number_of_jobs;
	}

	/**
	 * Adds a job to the queue.
	 *
	 * @param $url URL of script to run
	 * @param $args optional array of arguments to pass in GET query
	 * @param $conn_timeout optional Timeout setting
	 * @param $rw_timeout optional read/write timeout settings
	 * @return int Index in queue
	 */
	public function AddJobToQueue($url, $args = false, $conn_timeout=30, $rw_timeout=86400) {
		$index = count($this->_waiting_queue);
		$this->_waiting_queue[$index] = array('url'=>$url, 'args'=>$args, 'conn_timeout'=>$conn_timeout, 'rw_timeout' =>$rw_timeout);
		$this->_job_count++;
		return $index;
	}

	/**
	 * Starts queue.
	 *
	 * Starts the queue and runs a loop internally until queue is finished or timeout is reached.
	 *
	 */
	public function StartQueue() {
		$timestart = microtime(true);
		$current_job_pointer = 0;
		echo "Job count is " .$this->_job_count."<br/>";
		if ($this->_slots > $this->_job_count) $this->_slots = $this->_job_count;
		for ($slotcount=0; $slotcount < $this->_slots + 1; $slotcount++) {
			echo "Finished count is " . $this->_finished_count . "<br/>";
			echo "Slots are " .$this->_slots . " <br/>";
			$this->AddJobs();
		}

		$loop = 0;
		while($this->_finished_count < $this->_job_count) {
			echo "<br/>Loop is $loop<br/>";
			sleep(1);
			$this->PollJobs();
			echo "Finished count is " . $this->_finished_count . "<br/>";
			echo "Slots are " .$this->_slots . "<br/>";
			while($this->_slots > 0) {
				$this->LogEntry($this->_slots . " slots left and ".count($this->_waiting_queue)." jobs waiting. <br/>");
				$this->AddJobs();
			}

			if ($this->_vars['timeout'] !== 0) {
				if (round(microtime(true) - $timestart) > $this->_vars['timeout']) {
					$this->LogEntry("Timeout reached.\n");
					break;
				}
			}
			$loop++;

		}

		$execution = round(microtime(true) - $timestart, 2);
		$this->LogEntry("Finished after $execution seconds. ". $this->_finished_count ." jobs completed.\n");
		return $this->_log;
	}

	/**
	 * Logs entry to destination of choice: echo, silent, or errors.
	 * @param $entry string Entry.
	 */
	protected function LogEntry($entry) {
		if ($this->_vars['log'] == 'echo') {
			flush();
			echo $entry;
		} elseif($this->_vars['log'] == 'errors') {
			trigger_error($entry);
		}
		$this->_log .= $entry;
	}

	/**
	 * Adds jobs to running queue
	 * @return int Number of jobs added
	 */
	protected function AddJobs() {
		$return = 0;
		$load = sys_getloadavg();
		if ($load[0] < $this->_vars['min_load']) {
			$job = array_shift($this->_waiting_queue);
			if (!is_null($job)) {
				$current_job_number = $this->_job_count - count($this->_waiting_queue);
				$this->LogEntry("Starting job $current_job_number.\n");
				$new_job = $this->StartJobAsync($job['url'], $job['args'], $job['conn_timeout'], $job['rw_timeout']);
				$this->_running_queue[] = $new_job;
				unset($new_job);
				$this->_slots--;
				$return++;
			}
		} else {
			$this->LogEntry("Load is $load[0]. Not starting a job yet.<br/>");
		}
		return $return;
	}

	/**
	 * Checks status of jobs
	 */
	protected function PollJobs() {

		foreach($this->_running_queue as $i=>$rjob) {
			echo ("Index is $i<br/>");
			//job finished?
			$status = $this->JobPollAsync($rjob);
			if ($status === false) {
				$this->LogEntry("Status is false for job $i and slots are " . $this->_slots ."<br/>");
				unset($this->_running_queue[$i]);
				$this->_finished_count++;
				if (count($this->_waiting_queue)) {
					$this->LogEntry("Adding a job because finish count is " . $this->_finished_count . " and job count is " . $this->_job_count . " so slot count becomes " . $this->_slots +1 . "<br/>");
					$this->_slots++;
				} else {
					$this->LogEntry("Finish count is ! < job count and slots are " . $this->_slots. "<br/>");
				}
			} else {
				$this->LogEntry("Status of job $i is $status\n");
			}
		}
	}

	/**
	 * Starts an asynchronous job.
	 *
	 * @param $url URL of script to run
	 * @param $args optional array of arguments to pass in GET query
	 * @param $conn_timeout optional Timeout setting
	 * @param $rw_timeout optional read/write timeout settings
	 * @return File pointer to stream
	 */
	public function StartJobAsync($url, $args = false, $conn_timeout=30, $rw_timeout=86400) {
		$server = (defined('SERVER_HOST')) ? SERVER_HOST : 'localhost';
		$url .= ($args) ? '?' .http_build_query($args) : '';
		$this->LogEntry("Starting job with URL: $url\n");
		$port = (defined('SERVER_PORT')) ? SERVER_PORT : 80;
		$errno = '';
		$errstr = '';
		$fp = fsockopen($server, $port, $errno, $errstr, $conn_timeout);
		if (!$fp) {
			throw new Exception("Starting socket failed: $errstr ($errno).");
		}

		$out = "GET $url HTTP/1.1\r\n";
		$out .= "Host: $server\r\n";
		$out .= "Connection: Close\r\n\r\n";

		stream_set_blocking($fp, false);
		stream_set_timeout($fp, $rw_timeout);
		fwrite($fp, $out);

		if (is_null($fp)) throw new Exception('Null pointer');

		return $fp;

	}

	/**
	 * Checks status of job run using the file pointer reference
	 * @param $fp File pointer to stream
	 * @return False if HTTP disconnect (EOF) or a string (could be empty) if still connected.
	 */
	public function JobPollAsync(&$fp)
	{
		if ($fp) {
			$meta = stream_get_meta_data($fp);
			if ($meta['eof'] === true) {
				fclose($fp);
				$fp = false;
				return false;
			} else {
				return stream_get_contents($fp);
			}
		} else {
			trigger_error("FP returned false." . microtime());
			return false;
		}

	}


}