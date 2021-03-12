<?php

//
//    abbreviated name of RETS system to be processed
//
$rets_name = "";   // todo:  REQUIRED

if ($rets_name=="") die("CONFIG_ALL INIT ERROR! - You MUST define a RETS name in config_all.php for this program to run!");

//
////////////////////////////////////////////////////////////////////////////////////////////////
//
//   database connection info  todo: REQUIRED
//

$configDB = array(

    'DB_HOST' => 'localhost',
    'DB_NAME' => '',
    'DB_USER' => '',
    'DB_PASS' => '',
    'DB_DRIVER' => 'mysql'

);

if ($configDB['DB_USER']=="" ||
    $configDB['DB_NAME']=="" )  die("CONFIG_ALL INIT ERROR! - You MUST define a database USER [DB_USER] and NAME [DB_NAME] in config_all.php for this program to run!");

//
////////////////////////////////////////////////////////////////////////////////////////////////
///
//  RETS connection info  todo: REQUIRED
//

$rets_config[$rets_name]['login_url'] = '';
$rets_config[$rets_name]['username'] = '';
$rets_config[$rets_name]['password'] = '';
$rets_config[$rets_name]['user_agent'] = 'EasyRETS v0.9';

$rets_config[$rets_name]['table_prefix'] = $rets_name;

if ($rets_config[$rets_name]['login_url']=="" ||
    $rets_config[$rets_name]['username']=="" )  die("CONFIG_ALL INIT ERROR! - You MUST define a RETS login url, username, and password in config_all.php for this program to run!");

//
////////////////////////////////////////////////////////////////////////////////////////////////
///
/// //
////  todo:  you will have to add the "resource" and "class" tags from retsmd.com
////         also, the "query" will have to be the query for pulling the properties from your RETS system
////
////         in this example, the fieldname for the "active" properties was "Status" and the value for "active" was "A"
////
////  todo:  THIS DATA WILL ALMOST ALWAYS BE FOUND ON RETSMD.COM SO PLESE CHECK THERE FIRST!
//
$rets_config[$rets_name]['data']['property'] = array(

    "resource" => "",
    "class" => "",
    "create_table" => true,
    "keyfield" => "",
    "query" => ""

);

if ( $rets_config[$rets_name]['data']['property']['resource']=="" ||
     $rets_config[$rets_name]['data']['property']['class']==""  )  die("CONFIG_ALL INIT ERROR! - You MUST enter a RETS RESOURCE and CLASS [ usually found at RETSMD.COM ]cccin config_all.php for this program to run!");


$rets_property_tablename = str_replace(' ', '_', $rets_config[$rets_name]['table_prefix'] . '_' . strtolower($rets_config[$rets_name]['data']['property']['resource']) . '_' . strtolower($rets_config[$rets_name]['data']['property']['class']));
//
////////////////////////////////////////////////////////////////////////////////////////////////
//
// gloabl utility function should go here
//
function hasNumber ($str) {

    if (preg_match('#[0-9]#',$str))
        return true;
    else
        return false;
}


function padBlanks($inStg){
    return str_replace(" ","_",$inStg);
}