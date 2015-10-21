<?php

/**
 * Class NagiosPerfdata handles the processing of performance data and sends this data along to a Graphite server
 * @author mguthrie
 * @see https://github.com/mguthrie88/send_to_graphite
 * @since 4/23/2015
 */
class NagiosPerfdata{

    /**
     * Graphite server hostname or IP
     * @var string
     */
    protected $_graphiteHost = '';

    /**
     * Graphite server port
     * @var int
     */
    protected $_graphitePort = 2003;

    /**
     * @var string
     */
    protected $_perfDataFile;

    /**
     * @var string
     */
    protected $_perfDataType = 'service';

    /**
     * @var string
     */
    protected $_logFile = '/usr/local/nagios/var/perfdata.log';

    /**
     * Set this to be whatever namespaced location you want in your graphite metrics
     * @var string
     */
    protected $_location = 'nagios';

    /**
     * @var resource
     */
    protected $_socket;

    /**
     * @var array
     */
    protected $_buf= array();

    const LOG_DEBUG = '[DEBUG]';

    const LOG_INFO = '[INFO]';

    const LOG_WARNING = '[WARNING]';

    const LOG_ERROR = '[ERROR]';

    /**
     * @param $influxHost
     * @param $perfDataFile
     */
    public function __construct($perfDataFile){

        //Verify file
        if(!file_exists($perfDataFile)){
            $this->_log('Perf Data file does not exist!: '.$perfDataFile,self::LOG_ERROR);
            exit;
        }

        $this->_perfDataFile = $perfDataFile;

        $this->_perfDataType = strpos($perfDataFile,'host')!==false ? 'host' : 'service';

        $this->_socket = socket_create(AF_INET, SOCK_STREAM,SOL_TCP);

        if(socket_connect($this->_socket,$this->_graphiteHost,$this->_graphitePort)){
            $this->_log("Socket connected!\n");
        }else {
            $this->_log("Failed to connect to graphite server!\n",self::LOG_ERROR);
            exit;
        }

    }

    /**
     * @param $host
     * @param int $port
     */
    public function set_graphite_host($host,$port=2003){
        $this->_graphiteHost = $host;
        $this->_graphitePort = $port;
    }


    /**
     * Snapshots perfdata file, processes all lines of the file and sends to graphite
     * @throws Exception
     */
    public function process_perfdata_file(){

        //Watch our processing time...
        $time = microtime();
        $time = explode(' ', $time);
        $time = $time[1] + $time[0];
        $start = $time;

        $this->_log("Begin processing file...");

        $filename = $this->_snapshot_file();

        $processed = 0;

        $file = fopen($filename,'r+');

        $c=0;
        while(!feof($file)){

            $line = fgets($file,2048);

            if($line=="\n"){
                continue;
            }

            $processed += $this->_process_file_line($line);
            $c++;
        }

        //write to socket and close connection
        $this->send_buffer();
        socket_close($this->_socket);
        fclose($file);
        unlink($filename);

        $this->_log("Processed: ".$c." lines");

        //End timer
        $time = microtime();
        $time = explode(' ', $time);
        $time = $time[1] + $time[0];
        $finish = $time;
        $total_time = round(($finish - $start), 4);
        $this->_log('Run time was '.$total_time." seconds.\n");
        //echo "Time: $total_time\n";

    }

    /**
     * Chunk the perfdata file into an array of lines, and truncate the perfdata file
     * @return array
     */
    protected function _snapshot_file(){

        //rename the file
        $filename = $this->_perfDataFile.'.'.time();
        $cmd = '/bin/mv -f '.$this->_perfDataFile.' '.$filename;
        $this->_log("CMD: ".$cmd);
        $ret = system($cmd);

        if($ret > 0){
            $this->_log('Failed to create perdata snapshot file: '.$this->_perfDataFile,self::LOG_ERROR);
            throw new Exception('Unable to move file!');
        }

        return $filename;

    }

    /**
     * Process the performance data string taken from the file buffer
     * @Note Currently graphite can't annotate the data points, so we can't do anything with min/max/warning/critical values in the perfdata
     * @param string $line
     *
     * Examples Lines:
     * 1429110768      localhost       Total Processes procs=44;250;400;0;
     * 1429110805      localhost       Current Load    load1=0.030;5.000;10.000;0; load5=0.030;4.000;6.000;0; load15=0.050;3.000;4.000;0;
     *
     */
    protected function _process_file_line($line){

        $line = substr($line,0,-1);

        if($this->_perfDataType=='service'){
            list($time,$host,$service,$perfdata) = explode("\t",$line);
        }else {
            list($time,$host,$perfdata) = explode("\t",$line);
            $service = false;
        }

        $tableName = $this->_process_name($host,$service);

        //perf data points split on spaces
        //load1=0.000;5.000;10.000;0; load5=0.010;4.000;6.000;0; load15=0.050;3.000;4.000;0;
        $dataPoints = explode(" ",$perfdata);

        //process each point of perfdata
        foreach($dataPoints as $point){

            //'/data'=855923MB;1403687;1579147;0;1754608 '/dev/shm'=0MB;6379;7176;0;7973 '/mnt/splunk_pool'=6310283MB;8669825;9753553;0;10837281 '/boot'=32MB;77;87;0;97 '/vol2'=11198MB;193523;217714;0;241904 '/'=1969MB;9676;10886;0;12095

            //get the metric name
            if(strpos($point,';')!==false){
                $parts = explode(';',$point);
                $metric = $parts[0];

            }else {
                $metric = $point;
            }

            $label = substr($point,0,strpos($metric,"="));

            //If there is no metric to be determined, move on
            if(empty($label)){
                continue;
            }

            $rawvalue = substr($metric, (strpos($metric,"=")+1) );

            //separate the number from the UOM
            $value = preg_replace('/[^0-9.]/', '', $rawvalue);
            $UOM = preg_replace('/[0-9.]/', '', $rawvalue);

            $label = $this->_clean_name($label);

            if(!empty($UOM)){
                $label = $label."_".$UOM;
            }

            $path = $tableName.'.'.$label;

            $this->_buf[] = "$path $value $time";

        }

    }

    /**
     * Convert a host/service into a graphite namespaced metric
     * @param $host
     * @param bool $service
     * @return mixed|string
     */
    protected function _process_name($host,$service=false){

        if($service){
            return $this->_location.'.'.$this->_clean_name($host).'.'.$this->_clean_name($service);
        }else {
            return $this->_location.'.'.$this->_clean_name($host);
        }

    }

    /**
     * Removes characters that graphite does not like
     * @param $name
     * @return mixed
     */
    protected function _clean_name($name){

        $bad = array('.',' ','/','\\',':');
        $replace = '_';

        if($name=='/'){
            $name='ROOT';
        }

        return str_replace($bad,$replace,$name);

    }

    /**
     * Flushes the buffer out to the socket connection
     */
    public function send_buffer(){

        $this->_log("Buf has ".count($this->_buf)." items");

        $string = implode("\n",$this->_buf);

        $this->_log('Sending string length: '.strlen($string));
        $len = socket_write($this->_socket,$string,strlen($string));
        //echo "$len bytes written to socket\n";
        $this->_log("$len bytes written to socket");

    }


    /**
     * Log to a file
     * @param $string
     * @param string $level
     */
    protected function _log($string,$level=self::LOG_INFO){

        //only log warnings and errors in production
        if( (!defined('ENVIRONMENT') || ENVIRONMENT!='development') &&  $level==self::LOG_DEBUG) {
            return;
        }

        //log it!
        if(filesize($this->_logFile) > 100000){
            file_put_contents($this->_logFile, '=== File truncated: ['.date('Y-m-d H:i:s') . '] ==='."\n");
        }

        $string = $level." [".date('Y-m-d H:i:s') . "] " . $string . "\n";

        file_put_contents($this->_logFile, $string, FILE_APPEND);


    }


}
