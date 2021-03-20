<?php

global $rets_name,$current_root_dir;

////////////////////////////////////////////////////////////////////////////////////////////////
///
///   RETS-table fieldnames for critically important data assignments
///
///

$photo_count_keyfield                   ="PhotoCount";
$unique_id_keyfield                     ="Matrix_Unique_Id";
$photo_modification_datetime_keyfield   ="PhotoModificationTimestamp";
$mls_id_keyfield                        ="MLSNumber";

//f
////////////////////////////////////////////////////////////////////////////////////////////////
                                        // 0       1            2
$image_resolution_setting               =["Photo","LargePhoto","HighRes"];

// image resolution setting[irs] array index to be set here
$irs_index                              =2;     // set image pull to "HighRes"

// image resolution setting[irs] for getting the images or just links to matrix from GetObject(...) call
$irs_images_or_links                    =0;     // "0" returns the full image and "1" returns links to matrix images

// default image dirs - system will attempt to create folder if not present so be aware of permissions and such
$base_image_dir                         =[$current_root_dir . 'photos/', 
                                          $current_root_dir . 'bigpic/',
                                          $current_root_dir . 'hires/' ];

//
//  config vars end
//
/////////////////////////////////////////////////////////////////////////////////////////////////
