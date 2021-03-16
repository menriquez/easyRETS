<?php
set_time_limit(0);
date_default_timezone_set("America/Los_Angeles");
error_reporting(E_ERROR + E_WARNING);

require_once("PHRETS/vendor/autoload.php");
require_once ("config/config_all.php");
require_once ("config/config_images.php");

global $configDB;

echo "Connecting to database..";
$conn=mysqli_connect($configDB['DB_HOST'], $configDB['DB_USER'], $configDB['DB_PASS'], $configDB['DB_NAME']) or die(mysqli_error());
if ($conn) echo "db ".$configDB['DB_HOST'].":".$configDB['DB_NAME']." CONNECT OK\n\n";


$send_notification_email=false;

$jpeg_compress = 55;

$current_root_dir = __DIR__ . "/";

if (isset($argv[1]) && is_numeric($argv[1])) $instance_id=$argv[1];
if (isset($argv[2]) && is_numeric($argv[2])) $instance_tot=$argv[2];
if (isset($argv[1]) && is_string($argv[1])) $listing_id = $argv[1];

// default image dir
$base_photo_image_dir = $current_root_dir . 'photos/';
$base_hires_image_dir = $current_root_dir . 'hires/';
$base_thumbs_image_dir = $current_root_dir . 'thumbs/';
$base_thumbs96_image_dir = $current_root_dir . 'thumbs96/';

// this cookie file is abnormally important to the script working consistently so lets baby the hell out of it
//$cookie_fullpathandname = $current_root_dir . 'cookie.txt';
//echo "RETS_UPDATE_IMAGES_MRTU:  cookie file: $cookie_fullpathandname\n\n";

// set this to true to force image download from links (backdoor)
$forceDl = false;

// set this to true to pull HiRes image download from server
$hiRes = false;

// set this to true to force HiRes image download from links (backdoor)  
// NOT USED YET - marke
$hiResForceDl = false;

// counter to let us know how much overall our compression is working
$diskspace_saved=0;

$config_phrets = new \PHRETS\Configuration;

global $rets_config;

foreach ($rets_config as $key => $config) {

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

    // if someone passed an id in, just get that set
    if (isset($listing_id) && $listing_id) {
        get_single_image_set($listing_id, $rets);
        exit;
    }

    if ($send_notification_email) {
        // if we want to send someone who cares an email saying we started the image download
        // mail();
    }


    begin_image_update($rets, $key, $config);


    if ($send_notification_email) {
        // if we want to send someone who cares an email saying we ended the image download
        // mail();
    }
}

exit;

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
/**
 * begin_rets function.
 * Starts the rets update
 * @access public
 * @param mixed $rets_object
 * @param mixed $rets_name
 * @param mixed $rets_config
 * @return void
 */
function begin_image_update($rets_object, $rets_name, $rets_config) {

    global $rets_property_tablename;

    // set_time_limit(0);
    echo "Starting $rets_name image download..." . PHP_EOL;

    // ok.. create update time table if needed
    global $conn;
    global $photo_count_keyfield, $unique_id_keyfield, $photo_modification_datetime_keyfield, $mls_id_keyfield ;

    makeTable();

    // pull listing_ids for all properties without update fl`ag being set...
	$sql = "SELECT $photo_count_keyfield, $unique_id_keyfield,$photo_modification_datetime_keyfield,$mls_id_keyfield FROM $rets_property_tablename
                WHERE $photo_modification_datetime_keyfield  > (select end_time_db_ts from photo_dl_info where end_time_mlsid <> '' order by id DESC limit 1)       
                  ORDER BY $photo_modification_datetime_keyfield DESC ";

    $rets_results = mysqli_query($conn, $sql);
    $totRows = mysqli_num_rows($rets_results);
    echo "TOTAL PROPS TO GET IMAGES: $totRows\n";
    $curRow = 0;


    // get images
    if ($totRows > 0) {

        // update photo_dl table with start into ONLY if we have records to process
        $startInsert = mysqli_query($conn, "INSERT INTO photo_dl_info set start_time = now()");

        if (!$startInsert) {
            throw new Exception("easy_rets_images.php - unable to insert start time record...terminating process\n\n");

        } else {
            $timeRecId = mysqli_insert_id($conn);
        }

	    $first = true;
	    $start_seconds = time();

	    //  MAIN IMAGE IMPORT LOOP STARTS HERE -------------------------------------------------------------------------------------------
        while ($row = mysqli_fetch_assoc($rets_results)) {

            if ($first) {
                // we need to store the exact phototime of the newest record of this set bcuz we need it for the next set
                $end_db_ts = $row[$photo_modification_datetime_keyfield];
                $end_mlsid = $row[$mls_id_keyfield];
                $end_sysid = $row[$unique_id_keyfield];

                $first = false;
            }

            get_images($row[$unique_id_keyfield], $row[$photo_count_keyfield], $row[$unique_id_keyfield], $rets_object, $row, $rets_config);

            // stuff to keep track of and display percent done
            $curRow++;
            $perDone = number_format($curRow / $totRows * 100, 1, '.', '');
            echo "[$perDone% Done]" . PHP_EOL;

	        $sec_elapsed =  time() - $start_seconds;

	        // wait 10 seconds to start estimating
	        if ($sec_elapsed > 10) {
		        $estimate_seconds = intval($sec_elapsed * ($totRows/$curRow));
		        $end_time = ($start_seconds + $estimate_seconds) - time();
		        $time_estimate = gmdate("H:i:s", $end_time);
		        echo "est. photo import time remaining: $time_estimate\n";

	        }

            // keep updating the "last" vars bcuz at the end it will represent the newest one
            $last_is_first_import_ts = $row['photo_timestamp'];
            $start_mlsid = $row['listing_id'];
            $start_sysid = $row['sysid'];

        }
        //  END IMAGE IMPORT LOOP --------------------------------------------------------------------------------

        if ($conn->errno <> 0) {
	        mysqli_close ( $conn );
	        require("database.php");
        }

	    echo "Updating photo_dl_info table with MLS$ $end_mlsid [$end_sysid] with db time of $end_db_ts...." . PHP_EOL;

        // update timestamp only if we processed some records
        $sql = "UPDATE photo_dl_info 
                    SET end_time = NOW(),
                    start_time_db_ts = '$last_is_first_import_ts',
                    start_time_mlsid = '$start_mlsid',
                    start_time_sysid = '$start_sysid',
                    end_time_db_ts='$end_db_ts',
                    end_time_sysid='$end_sysid',
                    end_time_mlsid= '$end_mlsid'
                    WHERE id = $timeRecId ";

        $endUpdate = mysqli_query($conn, $sql);
        if (!$endUpdate) {
	        echo "ERROR!\n\n\n";
            throw new Exception("rets_update_images_mrtu.php - unable to update end time record...SQL = $sql ");
        } else {
        	echo "OK!\n\n\n";
            $endTime = date("Y-m-d H:i:s");
            echo "PHOTO DOWNLOADER SUCCESSFUL  at $endTime" . PHP_EOL;
        }
    }

    echo "Ending master_rets_table_update for $rets_name image download [$totRows DLed]" . PHP_EOL;
} //end begin_rets()


/**
 * get_images function.
 * Grabs new images for each mls listing id
 * @access public
 * @param string $listing_id This is the MLS number that builds folder names. (listing_id)
 * @param mixed $rets_key The key used to retrive the Photos. (rets_key)
 * @param mixed $rets_object
 * @param mixed $rets_config
 * @return bool
 */
function get_images($listing_id, $photo_count, $rets_key, $rets_object, $r, $rets_config): bool
{

    global $base_photo_image_dir, $base_hires_image_dir, $base_photo_jpeg_dir, $hiRes;
    global $diskspace_saved;
    global $jpeg_compress;

    // Image Directory
    /* $dir = $base_image.$rets_config['image_directory'].$listing_id.'/'; */
    if (!$hiRes)
        $dir = $base_photo_image_dir;
    else
        $dir = $base_hires_image_dir;

    // Check for image and create directory if needed
    if (!file_exists($dir)) {

        echo "Creating DIR $dir " . PHP_EOL;

        if (!mkdir($dir, 0777, true)) {
            throw new Exception("RETS_UPDATE_IMAGES.PHP - Fatel Error::Cannot Create Directory $dir...terminating.");
        } else {
            @exec("chmod 777 $dir");
            echo "Successfully created $dir " . PHP_EOL;
        }
    } //!file_exists($dir)

    // debug stuff...
    echo "Getting Photo Objects for Listing ID $listing_id " . PHP_EOL;


    if (!$hiRes) {
        // gather all possible photo objects for later processing...
        $photos = $rets_object->GetObject('Property', "LargePhoto", (string)$rets_key, "*", 0);
        // ..and get "backdoor" links in case we need to force downloads
        // $photosLinks = $rets_object->GetObject('Property', "LargePhoto", (string) $rets_key,"* /** @lang text */

    }

    if ($hiRes) {

        $photos[0]['Success'] = false;
        $photosLinks = $rets_object->GetObject('Property', "hires", $rets_key, "*", 1);
    }

    $write_error = false;
    $image_count = 0;

    echo "START Getting New Images..." . PHP_EOL;

    // even if we are "forcing" download, double check the photo array
    if ($GLOBALS['forceDl']) {

        // check photo array for the data
        if (!$photos[0]['Success']) {

            // clean up images
            delete_images($rets_key);

            // nope...so hit the backdoor links
            foreach ($photosLinks as $photoLink) {

                // get photo data from link and build filename
                $picData = file_get_contents($photoLink['Location']);
	            $image_res = imagecreatefromstring($picData);
                $width = imagesx($image_res);
                $height = imagesy($image_res);

                 $raw_fn = build_seo_filenames($r,$photoLink['Object-ID']);
                 $pic_fn = $base_photo_image_dir . $raw_fn;

	             if (imagejpeg($image_res,$pic_fn,$jpeg_compress)==false) {
                    // barf...
                    echo "Could not write the file " . $pic_fn . "<br>" . PHP_EOL;
                    $write_error = true;
                    break;
                } else {

                    // throw a party...we successfully saved a image!
                    $image_count++;
                    echo "FL writing image " . $raw_fn . " [ $width x $height ]... Ok" . PHP_EOL;

                    // so take it next level and create thumbs as well
                    //stampThumb($listing_id . '-' . $photoLink['Object-ID'] . '.jpg',400);

                }

            }

            $totPhotos = count($photosLinks);

            if ($image_count == $totPhotos) {

                echo "FORCED LINK image DL count ok [$image_count]...updating database for listing_id $listing_id..." . PHP_EOL;

                // not using this method anymore...using timestamp stuff - me
                // $update_photo_update = mysql_query("UPDATE `master_rets_table` SET `photo_update` = 1 WHERE `listing_id` = '$listing_id' AND `rets_system` = '{$rets_config['name']}' ") or die(mysql_error());

            } else {
                echo "FORCED LINK image DL count FAIL for listing_id $listing_id [$image_count] not equal [$totPhotos]" . PHP_EOL;
            }

        } else {

            // we got the photos in the array..w00t
            foreach ($photos as $photo) {

            	$raw_fn = build_seo_filenames($r,$photo['Object-ID']);
	            $pic_fn = $base_photo_image_dir . $raw_fn;

                $image_res = imagecreatefromstring($photo['Data']);
                $width = imagesx($image_res);
                $height = imagesy($image_res);

				if (imagejpeg($image_res,$pic_fn,$jpeg_compress)==false) {

                // if (file_put_contents($picFname, $photo['Data']) == false) {

                    echo "Could not write the file " . $raw_fn . PHP_EOL;
                    $write_error = true;
                    break;

                } else {

                    $image_count++;
                    echo "FP writing image " . $raw_fn . "  [ $width x $height ]...Ok" . PHP_EOL;
                    //stampThumb($listing_id . '-' . $photoLink['Object-ID'] . '.jpg',400);

                }

            }

            $totPhotos = count($photos);
            if ($image_count == $totPhotos) {
                echo "FORCED PHOTO images DL $listing_id count [$image_count] OK ..." . PHP_EOL;
                // not using this method anymore...using timestamp stuff - me
                // $update_photo_update = mysql_query("UPDATE `master_rets_table` SET `photo_update` = 1 WHERE `listing_id` = '$listing_id' AND `rets_system` = '{$rets_config['name']}' ") or die(mysql_error());
            } else {
                echo "FORCED PHOTO image DL count FAIL for listing_id $listing_id [$image_count] not equal [$totPhotos]" . PHP_EOL;
            }

        }
    } // ok...not forcing download so all we do is check photo array.
    else {

        foreach ($photos as $photo) {

	        $jpeg_res=imagecreatefromstring($photo->getContent());

            $image_count++;

	        $raw_fn = $listing_id."-".$photo->getObjectId().".jpg";
	        $pic_fn = $base_photo_image_dir . $raw_fn;

	        // webp it here
	        if (imagejpeg($jpeg_res,$pic_fn,$jpeg_compress)==false) {
		        echo "Could not write the file [ " . $raw_fn . " ] Skipping...".PHP_EOL;
	        } else {
		        echo "Writing BASE image [ " . $raw_fn . " ] Ok" . PHP_EOL;
	        }
        }
    }

	//updateMasterRets($listing_id);

    return true;

} //end get_image()


//
// this function is a lame attempt at trying to not download the same images that are already in image dir...me
// DEPRICATED:  not using anymore
//
function fixDBPhotoRecs()
{

    global $conn;

    // Get MLS Records"
    $mls_r = mysqli_query($conn, "SELECT `rets_system`, `listing_id`, `photo_count`, photo_update FROM `master_rets_table_update` where photo_update=0 AND photo_count > 0 ORDER BY photo_modification_timestamp  ASC");

    // Record Count Check
    if (mysqli_num_rows($mls_r) == 0) {
        echo 'No records found in master_rets_table_update where photo_update=0.' . PHP_EOL;
        return;
    }

    // Start checking counts
    $ls = $eq = $mr = 0;
    while ($row = mysqli_fetch_assoc($mls_r)) {

        // Get Image count from Directory
        $dir_image_count = check_directory_count($row['listing_id'], $row['rets_system']);

        if ($dir_image_count < $row['photo_count']) {
            echo "Listing: {$row['listing_id']} from {$row['rets_system']} - - current image count [$dir_image_count] < database image count [$row[photo_count]]...???<br>" . PHP_EOL;
            $ls++;
            // Update Photo Update Column so images can be downloaded
            // $update_r = mysql_query("UPDATE `master_rets_table` SET `photo_update` = '0' WHERE `listing_id` = '{$row['listing_id']}' AND `rets_system` = '{$row['rets_system']}'");
        } else if ($dir_image_count == $row['photo_count']) {
            echo "Listing: {$row['listing_id']} from {$row['rets_system']} - current image count ($dir_image_count) = database image count...turning update off for mls# $row[listing_id]<br>" . PHP_EOL;
            $update_r = mysqli_query($conn, "UPDATE `master_rets_table_update` SET `photo_update` = '1' WHERE `listing_id` = '{$row['listing_id']}' AND `rets_system` = '{$row['rets_system']}'");
            $eq++;
        } else if ($dir_image_count > $row['photo_count']) {
            echo "Listing: {$row['listing_id']} from {$row['rets_system']} - - current image count [$dir_image_count] > database image count [$row[photo_count]]...???<br>" . PHP_EOL;
            $mr++;
        }

    }

    echo "Ending Image Check..less count=$ls equal count=$eq more count=$mr" . PHP_EOL;


}

/**
 * Loops through the MLS Listing's image directory for a count
 */
function check_directory_count($listing_id, $rets_system)
{

    GLOBAL $base_image_dir;
    // Base Images Directory
    // $init_image = $base_image.$listing_id.".jpg";
    $image_count = 0;
    $images = array();


    // fix the fact the glob sometimes doens't return an array type...sigh
    $fnArray1 = glob($base_image_dir . $listing_id . "-??.jpg");
    if ($fnArray1 == false) {
        $fnArray1 = array();
    }

    $fnArray2 = glob($base_image_dir . $listing_id . "-?.jpg");
    if ($fnArray2 == false) {
        $fnArray2 = array();
    }

    // Check that the directory even exisits
    $images = array_merge($fnArray1, $fnArray2);
    $image_count = count($images);

    if ($image_count > 0) {
        // debug:: echo "image count $image_count" ;
    }

    //sort($images, SORT_NUMERIC);

    return $image_count;

}

function makeTable()
{

    global $conn;

    $chkTbl = mysqli_query($conn, "CREATE TABLE IF NOT EXISTS photo_dl_info (
    id int(11) NOT NULL AUTO_INCREMENT,
    start_time timestamp NULL DEFAULT NULL ,
    start_time_db_ts timestamp NULL DEFAULT NULL,
    start_time_mlsid bigint DEFAULT NULL,
    start_time_sysid bigint NULL DEFAULT NULL,
    end_time_db_ts timestamp NULL DEFAULT NULL,
    end_time_mlsid bigint NULL DEFAULT NULL,
    end_time_sysid bigint NULL DEFAULT NULL,
    end_time timestamp NULL DEFAULT NULL,
    PRIMARY KEY (id))
  ENGINE = MYISAM;");

    // if sucessful init table with one "old" record
    $rowCnt = mysqli_query($conn, "select * from photo_dl_info");
    if (mysqli_num_rows($rowCnt) == 0) {
        $initTbl = mysqli_query($conn, "INSERT INTO photo_dl_info set end_time_mlsid = '9999999', end_time_db_ts = '1974-01-01';");
    }

}

function delete_images($listing_id)
{

    GLOBAL $base_hires_image_dir;
    GLOBAL $rets_config;

    // Base Images Directory
    // $init_image = $base_image.$listing_id.".jpg";
    $image_count = 0;
    $images = array();
    $dir = $base_hires_image_dir . $rets_config['image_directory'];

    // fix the fact the glob sometimes doens't return an array type...sigh
    $fnArray1 = glob($dir . $listing_id . "-??.jpg");
    if (!$fnArray1) $fnArray1 = array();
    $fnArray2 = glob($dir . $listing_id . "-?.jpg");
    if (!$fnArray2) $fnArray2 = array();

    // create one array from the two...
    $images = array_merge($fnArray1, $fnArray2);

    // handy way to delete all files in an array...
    echo "Deleting old image set for MLS Id# $listing_id...";
    array_map("unlink", $images);
    echo "...ok" . "\n";

}

function get_single_image_set($listing_id, $rets_object)
{

    global $conn;
    global $jpeg_compress;

    $jpeg_compress=65;

    $rets_results = mysqli_query($conn, "select * from `master_rets_table` WHERE  listing_id = '$listing_id';");
    $totRows = mysqli_num_rows($rets_results);
    $curRow = 0;

    $is_first = true;

    // get images
    if ($totRows > 0) {

        while ($row = mysqli_fetch_assoc($rets_results)) {


            get_images($row['sysid'], $row['photo_count'], $row['sysid'], $rets_object, $row, null);

        }

        echo "INDIVIDUAL PHOTO DOWNLOADER SUCCESSFUL END for MLS ID: $listing_id." . "\n";

    }
}

function stampThumb($image_fn, $image_id, $big_width = 400, $little_width = 96)
{

    //execInBackground("php create_thumbs_mrtu.php $image_fn");
    // return;

    require_once __DIR__ . '/simpleImage.php';

    GLOBAL $base_hires_image_dir;
    GLOBAL $base_thumbs_image_dir;
    GLOBAL $base_thumbs96_image_dir;
    GLOBAL $rets_config;

    if (!file_exists($base_thumbs_image_dir)) {

        if (mkdir($base_thumbs_image_dir, 0755)) {
            echo 'Created Thumbnail Dir';
        } else {
            throw new Exception("stampThumb.php - cannot create new directory for thumbs...");
        }

    }

    $image = new SimpleImage();
    $image->load($base_hires_image_dir . $image_fn);
    $img2 = clone $image;

    if ($image_id == "1") {

        if ($image->getWidth() > $big_width)
            $image->resizeToWidth($big_width);

        $image->save($base_thumbs_image_dir . $image_fn);
    }

    if ($img2->getWidth() > $little_width)
        $img2->resizeToWidth($little_width);

    $img2->save($base_thumbs96_image_dir . $image_fn);

    echo "StampThumb:: writing... " . $image_fn . "...Ok" . PHP_EOL;

}

function execInBackground($cmd)
{

    if (substr(php_uname(), 0, 7) == "Windows") {
        pclose(popen("start /B " . $cmd, "r"));
    } else {
        exec($cmd . " > /dev/null &");
        //exec( $cmd );
    }
}

function updateMasterRets($listing_id)
{
	global $conn;

    $sql = "UPDATE master_rets_table_update set index_photo = 1 where listing_id = '$listing_id'";
    mysqli_query($conn, $sql) or die(mysqli_error($conn) . $sql);

}



function build_seo_filenames_jpeg($r,$i_set_id) {

	$add = str_replace(" ","-", getStreetAddress($r));
	$city = str_replace(" ","-", getCityStZip($r));
	$uri = $add . "-" . $city . "-" . getMLSPhoto($r);

	$rv = $uri . "-$i_set_id.jpg";
	return $rv;
}
function build_seo_filenames($r,$i_set_id) {

	$add = str_replace(" ","-", getStreetAddress($r));
	$city = str_replace(" ","-", getCityStZip($r));
	$uri = $add . "-" . $city . "-" . getMLSPhoto($r);

	$rv = $uri . "-$i_set_id.jpg";
	return $rv;
}


function getStreetAddress($row) {

	$sfx="";
	switch ($row['street_suffix']) {
		case "Avenue":
			$sfx = "Ave";
			break;
		case "Boulevard":
			$sfx = "Blvd";
			break;
		case "Circle":
			$sfx = "Cir";
			break;
		case "Court":
			$sfx = "Ct";
			break;
		case "Drive":
			$sfx = "Dr";
			break;
		case "Lane":
			$sfx = "Ln";
			break;
		case "Highway":
			$sfx = "Hwy";
			break;
		case "Parkway":
			$sfx = "Pkwy";
			break;
		case "Place":
			$sfx = "Pl";
			break;
		case "Square":
			$sfx = "Sq";
			break;
		case "Street":
			$sfx="St";
			break;
		case "Terrace":
			$sfx = "Terr";
			break;
		case "Road":
			$sfx="Rd";
			break;
		case "Trail":
			$sfx = "Tr";
			break;
		case "Way":
			$sfx = "Way";
			break;
		case "Valley":
			$sfx = "Vly";
		default:
			$sfx=$row['street_suffix'];

	}

	$str = $row['street_number']." ".($row['street_dir']<>""?$row['street_dir']." ":"").ucwords(strtolower($row['street_name']))." ".$sfx;

	if ($str=="   ")
		$str="No Address Found";
	return $str;
}

function getCityStZip($row) {

	return $row['city']." NV ".$row['postal_code'];
}

function getMLSPhoto($row) {
	return "mls-".$row['listing_id'];
}

function update_image_rec($mls_id) {

	$sql = "UPDATE master_rets_table_update SET photo_update = 1 WHERE listing_id = $mls_id";


}
