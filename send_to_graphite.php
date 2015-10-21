#!/usr/bin/php
<?php
/**
 * Use this file in conjunction with the following perfdata templates in nagios.cfg
 *
 * host_perfdata_file_template=$TIMET$\t$HOSTNAME$\t$HOSTPERFDATA$
 * service_perfdata_file_template=$TIMET$\t$HOSTNAME$\t$SERVICEDESC$\t$SERVICEPERFDATA$
 *
 * Usage:
 * ./send_to_graphite <filename>
 */


require_once('/monitoring/libs/NagiosPerfdata.php');

define('ENVIRONMENT','development');

ini_set('display_errors',1);


if(empty($argv[1]) && !file_exists($argv[1])){

    echo "Missing perfdata file!\n
Usage: ./send_to_graphite.php <filename> \n"
    ;
}


$file = $argv[1];

$NagPerf = new NagiosPerfdata($file);

// Set your graphite host if you don't have it hardcoded in the class
//$NagPerf->set_graphite_host('<your graphite host>');

$NagPerf->process_perfdata_file();