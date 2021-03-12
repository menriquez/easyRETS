<?php
set_time_limit(0);
date_default_timezone_set("America/Los_Angeles");
error_reporting(E_ERROR + E_WARNING);

use PHRETS\Configuration;
use PHRETS\Session;

require_once("PHRETS/vendor/autoload.php");
require_once ("config/config_all.php");
require_once ("config/config_props.php");

global $rets_config;
global $configDB;
global $conn;
GLOBAL $rets_property_tablename;

echo "Connecting to database..";
$conn = mysqli_connect($configDB['DB_HOST'], $configDB['DB_USER'], $configDB['DB_PASS'], $configDB['DB_NAME']) or die(mysqli_error());
if ($conn) echo "db " . $configDB['DB_HOST'] . ":" . $configDB['DB_NAME'] . " CONNECT OK\n\n";

//  init program run timer
$mtime = microtime();
$mtime = explode(" ", $mtime);
$mtime = $mtime[1] + $mtime[0];
$starttime = $mtime;
$start_time = date('G:ia');

/** Start RETS Data Download **/
foreach ($rets_config as $key => $config) {

    foreach ($config['data'] as $data => $setting) {

        echo "Starting RETS download for {$config['table_prefix']} Data: {$data} into database $configDB[DB_NAME]..." . "\n";

        /** initialize PHRETS **/
        $config_phrets = new Configuration;

        $config_phrets->setLoginUrl($config['login_url'])
            ->setUsername($config['username'])
            ->setPassword($config['password'])
            ->setRetsVersion('1.7.2');

        $rets = new Session($config_phrets);

        // Connect to RETS
        echo "Connecting to RETS Server..." . "\n";
        if (!$rets->Login()) {
            throw new Exception("RETS_UPDATE.PHP - Unable to log in.");
        }

        // only create tables if required...
        if ($setting['create_table']) {

            // get field metadata
            $fields = $rets->GetTableMetadata($setting['resource'], $setting['class']);

            create_table($rets_property_tablename, $fields->all(), $config);

        }

        // query RETS server
        // this loop controls the number of weeks/months to import
        for ($i = 0; $i <= $config['num_time_periods']; $i++) {

            $start_date = date('Y-m-d', strtotime("-$i {$config['time_period']}"));
            $end = $i + 1;
            $time_string = "-$end {$config['time_period']} +1 day";
            $end_date = date('Y-m-d', strtotime($time_string));

            $query = "($config[property_added_datetime_field]=" . $start_date . "T00:00:00-),($config[property_added_datetime_field]=" . $end_date . "T00:00:00+),$setting[query]";

            echo "Running Query [ {$config['time_period']} $end ]: $query on Resource: {$setting['resource']} and Class: {$setting['class']}" . "\n";

            $search_query = $rets->Search($setting['resource'], $setting['class'], $query);
            $tot_recs = $search_query->count();

            // Check for Rows
            if ($tot_recs < 10000) {

                echo "Total records found: $tot_recs " . "\n";

                // Check Server Query Limit
                if ($tot_recs <= 0) {
                    echo "No Rows Found..." . "\n";

                } else { //  main transpose loop starts here ------------------------------------------------------------------>>>

                    $results = $search_query->toArray();
                    foreach ($results as $listing) {

                        // Build Query
                        $query = 'INSERT INTO ' . $rets_property_tablename . ' SET';

                        // Loop through fields/data
                        foreach ($listing as $field => $value) {
                            // check for field map
                            // $field_map = $config['field_map'];
                            // $field_map = $db_map;

                            $field_name = mysqli_real_escape_string($conn, (isset($db_map[$field])) ? $db_map[$field] : $field);

                            $query .= ' `' . stripslashes($field_name) . '` = \'' . mysqli_real_escape_string($conn, $value) . '\',';
                        }

                        // Finsih Building Query
                        $query = rtrim($query, ',');
                        $query .= ';';

                        // DEBUG
                        //  echo $query;

                        // Run Query
                        mysqli_query($conn, $query) or die(mysqli_error($conn) . $query);

                    } // End Fetch Rows

                } // End Row Check

            } else {

                echo "Total records found: {$rets->TotalRecordsFound()} exceed the server limit of: {$config['server_query_limit']}. Exiting..." . "\n";

            } // end row processing

        }

        echo "Disconnecting from RETS server..." . PHP_EOL;
        $rets->Disconnect();

    }
}

/** END TIME */
$mtime = microtime();
$mtime = explode(" ", $mtime);
$mtime = $mtime[1] + $mtime[0];
$endtime = $mtime;
$end_time = date('G:ia');
$totaltime = ($endtime - $starttime);
$timetocomplete = number_format($totaltime / 60, 2);
echo "RETS Update took " . $timetocomplete . " minutes" . "\n" . "\n";

//
//   end easy_rets_props.php
//
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

/**
 *   function reads RETS table metadata and creates local mysql table to receive and store
 */
function create_table( $table_name, $fields ) {

    global $db_map;
    global $conn;

    // Clean duplicate field names.
    $clean_fields = [];
    foreach ($fields as $field) {

        // map fields to external field name file...
        {
            //$db_name =  mysqli_real_escape_string(padBlanks($field['LongName']."_".$field['SystemName']));
            $db_name = mysqli_real_escape_string($conn, padBlanks($field['SystemName']));
            if ($field['MaximumLength'] == 0) {
                $clean_fields[$db_name][0] = $field['DataType'];
                $clean_fields[$db_name][1] = -1;
            } else {
                $clean_fields[$db_name][0] = $field['DataType'];
                $clean_fields[$db_name][1] = $field['MaximumLength'];
            }
        }

        $db_map[$field['SystemName']] = $db_name;

    }
    $fields = $clean_fields;

    $query = 'CREATE TABLE ' . $table_name . ' (';
    foreach ($fields as $field_name => $field_type) {

        $len = $field_type[1] + 25;
        //$len =255;
        if (strpos($field_name, 'latitude') || strpos($field_name, 'longitude') || strpos($field_name, 'Latitude') || strpos($field_name, 'Longitude') || $field_name == 'latitude' || $field_name == 'longitude' || $field_name == 'Latitude' || $field_name == 'Longitude') {
            //fix data type for the longitude/latitude column
            $mysqli_type = 'DECIMAL(10,6)';
        } else {
            switch ($field_type[0]) {
                case 'Character':
                    //$mysqli_type = 'TEXT';
                    if ($len == 1025) {
                        $mysqli_type = 'TEXT';
                    } else {
                        $mysqli_type = 'VARCHAR(' . $len . ')';
                    }
                    break;
                case 'Int':
                    $mysqli_type = 'INTEGER';
                    break;
                case 'Decimal':
                    $mysqli_type = 'DECIMAL(14,2)';
                    break;
                case 'Date':
                    $mysqli_type = 'DATE';
                    break;
                case 'DateTime':
                    $mysqli_type = 'DATETIME';
                    break;
                case 'Boolean':
                    $mysqli_type = 'BOOLEAN';
                    break;
                case 'Long':
                    $mysqli_type = 'BIGINT';
                    break;
                default:
                    $mysqli_type = 'TEXT';
                    break;
            }
        }
        $query .= ' `' . mysqli_real_escape_string($conn, $field_name) . '` ' . $mysqli_type . ',';
    }
    $query = rtrim($query, ',');
    $query .= ') ENGINE = MyISAM;';
    mysqli_query($conn, "DROP TABLE IF EXISTS $table_name;") or die(mysqli_error($conn) . $query);
    mysqli_query($conn, $query) or die(mysqli_error($conn) . $query);
}

/**
 * Decent check to see if server supports offsets.
 * @param $rets
 * @param $resource
 * @param $class
 * @param $query
 * @param $record_key_field
 * @return bool
 */
function offset_check($rets, $resource, $class, $query, $record_key_field)
{
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

    if ($rows1 == $rows2) return true; else return false;

}
