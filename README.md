# send_to_graphite
Nagios performance data processing command to feed performance data metrics to a Graphite server

Usage:
  - Update your graphite host, logfile path, and location in the NagiosPerfdata class.
  - Graphite namespace looks like this: <location>.<host>.<service>.<metric>
  - Set the following configs in your nagios.cfg file:

```
host_perfdata_file=/usr/local/nagios/var/host-perfdata
service_perfdata_file=/usr/local/nagios/var/service-perfdata

host_perfdata_file_template=$TIMET$\t$HOSTNAME$\t$HOSTPERFDATA$
service_perfdata_file_template=$TIMET$\t$HOSTNAME$\t$SERVICEDESC$\t$SERVICEPERFDATA$ 

service_perfdata_file_processing_command=send_service_perfdata_to_graphite
host_perfdata_file_processing_command=send_host_perfdata_to_graphite

host_perfdata_file_mode=a
service_perfdata_file_mode=a

```

  - Create command definitions for the directives above
   
```
define command{
         command_name    send_service_perfdata_to_graphite
         command_line    /scripts/send_to_graphite.php /usr/local/nagios/var/service-perfdata
 
 }
 
 define command{
         command_name    send_host_perfdata_to_graphite
         command_line    /scripts/send_to_graphite.php /usr/local/nagios/var/host-perfdata
 
 }
```

  - Set your processing intervals at values that make sense for your environment size. I tried to keep the buffer flushes to 1000 data points or less. So I used the following settings.

```
host_perfdata_file_processing_interval=20
service_perfdata_file_processing_interval=10
```


I've modified this code to make it suitable for public use, but I haven't tested the changes yet. Please feel free to contribute any additional docs or fixes. 