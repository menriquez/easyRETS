<?php

global $rets_name;

////////////////////////////////////////////////////////////////////////////////////////////////
//
//   RETS-table fieldnames for critically important data assignments
//

//   todo: this is the unique property id FIELDNAME from the PROPERTIES table that is linked ONE to MANY to the rooms table
$connect_id_keyfield_props  ="";

//   todo: this is the unique property id FIELDNAME from the ROOMS table that is linked MANY to ONE to the PROPERTIES table
$connect_id_keyfield_rooms  ="";

//   todo: this is the property modification datetime FIELDNAME from the PROPERTIES table that allows incremental datapulls to happen
$tracking_datetime_keyfield ="";


//  todo:  enter RETSMD Room table identification data
$rets_config[$rets_name]['data']['room'] = array(
    "resource" => "",
    "class" => "",
    "keyfield" => "",
    "create_table" => true,
    "query" => ""
);

//
//  config vars end
//
/////////////////////////////////////////////////////////////////////////////////////////////////


