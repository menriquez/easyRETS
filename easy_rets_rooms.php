<?php
//SET TIME LIMIT
@set_time_limit(0);
date_default_timezone_set("America/Los_Angeles");
error_reporting(E_ERROR+E_WARNING);

use PHRETS\Configuration;
use PHRETS\Session;

/** START TIME */
$db_map=array();
$mtime = microtime(); 
$mtime = explode(" ",$mtime); 
$mtime = $mtime[1] + $mtime[0]; 
$starttime = $mtime;
$start_time = date('G:ia');

require_once("PHRETS/vendor/autoload.php");
require_once('config/config_all.php');
require_once('config/config_rooms.php');

global $configDB;
global $rets_config;
global $connect_id_keyfield_props,$connect_id_keyfield_rooms,$tracking_datetime_keyfield;

echo "Connecting to database..";
$conn=mysqli_connect($configDB['DB_HOST'], $configDB['DB_USER'], $configDB['DB_PASS'], $configDB['DB_NAME']) or die(mysqli_error());
if ($conn) echo "db ".$configDB['DB_HOST'].":".$configDB['DB_NAME']." CONNECT OK\n\n";


//
//  take data from RETS system and insert it into local database by building the SQL
//
function write_room_record($table_name, $listing, mysqli $conn, array $db_map) {

    $query = 'INSERT INTO ' . $table_name . ' SET';

    // Loop through fields/data
    foreach ($listing as $field => $value) {
        // Check for Field Map
        // $field_map = $config['field_map'];
        // $field_map = $db_map;

        $field_name = mysqli_real_escape_string($conn, (isset($db_amp[$field])) ? $db_map[$field] : $field);

        $query .= ' `' . stripslashes($field_name) . '` = \'' . mysqli_real_escape_string($conn, $value) . '\',';
    }

    // finish building query
    $query = rtrim($query, ',');
    $query .= ';';

    // Run Query
    mysqli_query($conn, $query) or die(mysqli_error($conn) . $query);

}

foreach ($rets_config as $key => $config) {

    foreach($config['data'] as $data => $setting) {

        if ($setting['resource']!="PropertySubTable") continue;

        echo "Starting RETS download for " . $config['table_prefix'] . " Data: " . $data . " into database " . $configDB['DB_NAME'] . "..." ."\n";

        /** Initialize PHRETS **/
        $config_phrets = new \PHRETS\Configuration;

        $config_phrets->setLoginUrl($config['login_url'])
                        ->setUsername($config['username'])
                        ->setPassword($config['password'])
                        ->setRetsVersion('1.7.2');

        $rets = new \PHRETS\Session($config_phrets);

        // Connect to RETS
        echo "Connecting to RETS Server..." . "\n";
        if (!$rets->Login()) {
            throw new Exception("RETS_UPDATE.PHP - Unable to log in...probably can't write to cookie.txt");
        }

        /** Class and Property Config **/
        $class = $setting['class'];
        $resource = $setting['resource'];
        $keyfield = $setting['keyfield'];


        // only create tables if required...
        if ($setting['create_table']) {

            // Get Fields
            try {
                $fields = $rets->GetTableMetadata($resource, $class);
            } catch (\PHRETS\Exceptions\CapabilityUnavailable $e) {
                echo "ERROR - unable to get table fields";
                exit;
            }

            // Create Table
            $table_name = str_replace(' ', '_', $config['table_prefix'] . '_' . strtolower($resource) . '_' . strtolower($class));
            create_table($table_name,$fields,$config,$conn);

        }

      /*  $limit = $config['query_limit'];
        $query_options = array(
            'Limit' => $limit, 
            'Count' => 1
        );
       */
        // ok.. create update time table if needed
        make_rooms_dl_table();

        // query the property table
        $sql = "SELECT $connect_id_keyfield_props, $tracking_datetime_keyfield FROM $rets_property_tablename
                WHERE $tracking_datetime_keyfield > (select end_time_db_ts from room_dl_info order by id DESC limit 1)         
                  ORDER BY $tracking_datetime_keyfield DESC  ";

        $rets_results = mysqli_query($conn, $sql);
        $totRows = mysqli_num_rows($rets_results);
        $curRow = 0;

        if( $totRows > 0) {

            // update photo_dl table with start into ONLY if we have records to process
            $startInsert = mysqli_query($conn, "INSERT INTO room_dl_info set start_time = now()");

            if (!$startInsert) {
                throw new Exception("RETS_UPDATE_IMAGES.PHP - unable to insert start time record...terminating process\n\n");
            } else {
                $timeRecId = mysqli_insert_id($conn);
            }

            $first = true;


            // main loop -------------------------------------------------------------------------------------------------------------------
            while($row = mysqli_fetch_array($rets_results))  {

                if ($first) {
                    // we need to store the exact datetime of the newest record of this set bcuz we need it for the next set
                    $end_db_ts = $row[$tracking_datetime_keyfield];
                    $end_sysid = $row[$connect_id_keyfield_props];

                    $first = false;
                }

                $muid = $row[$connect_id_keyfield_props];

                $query = "($connect_id_keyfield_rooms = $muid)";

                echo "Pulling room info from remote RETS system...";

                try {
                    $search_query = $rets->Search($resource, $class, $query);
                } catch (\PHRETS\Exceptions\CapabilityUnavailable $e) {
                    echo "ERROR - Unable to search RETS database\n";
                    exit;
                }
                $totRecs=$search_query->count();

                if ($totRecs > 0) {

                    echo "FOUND! {$totRecs} rooms for property id: {$muid}"."\n";

                    $all_rooms = $search_query->toArray();
                    foreach ($all_rooms as $listing) {

                        $db_ok = write_room_record($table_name, $listing, $conn, $db_map);

                    }
                }
                else {

                    echo "NOPE! for property id: {$muid} "."\n";

                } // End Total Row Check

                $curRow++;
                $perDone = number_format($curRow / $totRows * 100, 1, '.', '');

                if ($curRow % 30 == 0)
                    echo "[$perDone% Done]" . PHP_EOL;

                // keep updating the "first" vars bcuz at the end it will represent the newest one
                $last_is_first_import_ts = $row[$tracking_datetime_keyfield];
                $start_sysid = $row[$connect_id_keyfield_props];

            }
            // end main loop ----------------------------------------------------------------------------------------------------------

            // update timestamp only if we processed some records
            $sql = "UPDATE room_dl_info 
                    SET end_time = NOW(),
                    start_time_db_ts = '$last_is_first_import_ts',
                    start_time_sysid = '$start_sysid',
                    end_time_db_ts='$end_db_ts',
                    end_time_sysid='$end_sysid'
                    WHERE id = $timeRecId ";

            $endUpdate = mysqli_query($conn, $sql);
            if (!$endUpdate) {
                throw new Exception("RETS_UPDATE_ROOMS.PHP - unable to update end time record...SQL = $sql \n\n");
            } else {
                $endTime = date("Y-m-d H:i:s");
                echo "ROOM DOWNLOADER SUCCESSFUL END at $endTime\n\n";
            }

            /** Disconnect from RETS server. Should free up resources **/
            try {
                $rets->Disconnect();
            } catch (\PHRETS\Exceptions\CapabilityUnavailable $e) {
                exit;
            }
            echo "Disconnected from RETS server..."."\n";

        }
    }

}

/** END TIME */
$mtime = microtime(); 
$mtime = explode(" ",$mtime); 
$mtime = $mtime[1] + $mtime[0]; 
$endtime = $mtime;
$end_time = date('G:ia');
$totaltime = ($endtime - $starttime);
$timetocomplete = number_format($totaltime/60,2);
echo "RETS Update took ".$timetocomplete." minutes"."\n"."\n";

/**
* Helper Function to create MYSQL Table
*/
function create_table($table_name, $fields, $rets_config, $conn) {
    
    global $db_map,$conn;
    //global $conn;

    // Clean duplicate field names.
    $clean_fields = array();
    foreach ($fields as $field)
    {

        // map Fields to external field name file...

        {        
            // mapping logic... 

            $db_name =  mysqli_real_escape_string($conn,$field['SystemName']);
            //$db_name =  mysqli_real_escape_string($conn,padBlanks($field['LongName']));
            if ($field['MaximumLength']==0)  {
                $clean_fields[$db_name][0] = $field['DataType'];
                $clean_fields[$db_name][1] = -1;
            }
            else { 
                $clean_fields[$db_name][0] = $field['DataType'];
                $clean_fields[$db_name][1] = $field['MaximumLength'];               
            }

        } 

        $db_map[$field['SystemName']] = $db_name;

    }
    $fields = $clean_fields;
   
    // drop the old table
    //$drop_sql = "DROP TABLE IF EXISTS $table_name;";
    //mysqli_query($conn,$drop_sql) or die(mysqli_error($conn) . "ERROR QUERY = '$drop_sql' ");

    $query = '';
    $query .= 'CREATE TABLE IF NOT EXISTS ' . $table_name . ' (';
    foreach ($fields as $field_name => $field_type) {

        $len = $field_type[1]+25;
        //$len =255;
        if (strpos($field_name, 'latitude') || strpos($field_name, 'longitude') || strpos($field_name, 'Latitude') || strpos($field_name, 'Longitude') || $field_name == 'latitude' || $field_name == 'longitude' || $field_name == 'Latitude' || $field_name == 'Longitude')
        {
            //fix data type for the longitude/latitude column
            $mysqli_type = 'DECIMAL(10,6)';
        }
        else
        {
            switch (strtolower($field_type[0]))
            {
                case 'character':
                    //$mysqli_type = 'TEXT';
                    if ($len==1025) {    
                        $mysqli_type = 'TEXT';       
                    }
                    else {
                        $mysqli_type = 'VARCHAR('.$len.')';                       
                    }
                    break;
                case 'int':
                    $mysqli_type = 'INTEGER(11)';
                    break;
                case 'decimal':
                    $mysqli_type = 'DECIMAL(14,2)';
                    break;
                case 'date':
                    $mysqli_type = 'DATE';
                    break;
                case 'datetime':
                    $mysqli_type = 'DATETIME';
                    break;
                case 'long':
                    $mysqli_type = 'BIGINT';
                    break;
                default:
                    $mysqli_type = 'TEXT';
                    break;
            }
        }
        $query .= ' `' . mysqli_real_escape_string($conn,$field_name) . '` ' . $mysqli_type . ',';
    }
    $query = rtrim($query, ',');
    $query .= ') ENGINE = MyISAM;';
    mysqli_query($conn,$query) or die(mysqli_error($conn) . "ERROR QUERY = $query");
}

/**
* Decent check to see if server supports offsets.
*/
function offsetCheck($rets,$resource, $class, $query, $record_key_field)
{

    $total_count = 0;
    $key_count = array();

    $search1 = $rets->SearchQuery(
        $resource,
        $class,
        $query,
        array(
            'Limit' => 50,
            'Offset' => 1,
            'Select' => $record_key_field)
    );

    $rows1 = $rets->TotalRecordsFound($search1);				

    $search2 = $rets->SearchQuery(
        $resource,
        $class,
        $query,
        array(
            'Limit' => 50,
            'Offset' => 51,
            'Select' => $record_key_field
        )
    );

    $rows2 = $rets->TotalRecordsFound($search2); 

    if($rows1==$rows2) return true; else return false;

}

function make_rooms_dl_table() {

    global $conn;

    $sql = "CREATE TABLE IF NOT EXISTS `room_dl_info` (
              `id` bigint NOT NULL AUTO_INCREMENT,
              `start_time` datetime NULL DEFAULT NULL,
              `start_time_db_ts` datetime NULL DEFAULT NULL,
              `start_time_sysid` bigint DEFAULT NULL,
              `end_time_db_ts` datetime NULL DEFAULT NULL,
              `end_time_sysid` bigint DEFAULT NULL,
              `end_time` datetime NULL DEFAULT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=MyISAM AUTO_INCREMENT=11 DEFAULT CHARSET=utf8";

    $chkTbl = mysqli_query($conn, $sql);

    if (!$chkTbl) die ("unable to create table...terminating execution until someone fixes");

    // if successful init table with one "old" record
    $rowCnt = mysqli_query($conn, "select * from room_dl_info");
    if (mysqli_num_rows($rowCnt) == 0) {
        $initTbl = mysqli_query($conn, "INSERT INTO room_dl_info set start_time = '1970-01-01', end_time_db_ts = '1970-01-01';");
    }

}
