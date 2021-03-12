<?php

//
//  todo: config_all.php hold all the configuration vars that are common for all 3 modules
//


global $rets_name;


////////////////////////////////////////////////////////////////////////////////////////////////
///
///   RETS-table fieldnames for critically important data assignments
///
$rets_config[$rets_name]['time_period'] = 'week';               // "month" or "week"
$rets_config[$rets_name]['num_time_periods'] = 4;               // a month of properties
$rets_config[$rets_name]['property_added_datetime_field'] = ""; // todo - enter the fieldname that hold the datatime
                                                                                //        that the property data has been modified

// not sure of these are even needed - me
$rets_config[$rets_name]['query_limit'] = '20000';
$rets_config[$rets_name]['server_query_limit'] = '5000';

if ($rets_config[$rets_name]['property_added_datetime_field'] == "") die("CONFIG_PROPS INIT ERROR! - You MUST define a RETS property info last modified datetime fieldname in config_props.php ( rets_config[rets_name][property_added_datetime_field] ] for this program to run!");;

//  end of PROPERTIES configuration vars
//
/////////////////////////////////////////////////////////////////////////////////////////////////

