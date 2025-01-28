<?php
include_once "PHPimap2array_v2.php";

// create and init the object
////////////////////////////////////////////////////////////////////////////////////
$obj1 = new PHPimap2array_v2();
if ($obj1->init('ssl://outlook.office365.com', 993) === false)
{echo "\ninit() failed: " . $obj1->error . "\n";exit;}

////////////////////////////////////////////////////////////////////////////////////
// FOR OUTLOOK, skip this part if you authenticate with PLAIN Authentication
////////////////////////////////////////////////////////////////////////////////////
// retrieve the bearer token
// XOAUTH2-access to outlook365-accounts works only for work- or school- accounts,
// personal accounts wont work!
////////////////////////////////////////////////////////////////////////////////////
$bearertoken = $obj1->getToken_outlook("client_credentials",
                        "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",     // client-id
                        "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", // client-secret
                        "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",     // tenant-id
                        "user@xxxxxxxxxx.onmicrosoft.com");         // user-email
if ($obj1->xoauth2_authenticate($bearertoken) === false)
{echo "\nauthenticate() failed: " . $obj1->error . "\n";exit;}


////////////////////////////////////////////////////////////////////////////////////
// PLAIN authentication, skip this part if you authenticate with OAUTH2
if ($obj1->plain_authenticate('user@mailhost', 'password') === false)
{echo "\nauthenticate() failed: " . $obj1->error . "\n";exit;}


////////////////////////////////////////////////////////////////////////////////////
// select the INBOX folder and retrieve the newest uid
$last_uid = $obj1->select_folder("INBOX");
if ($last_uid == false)
{echo "\nselect INBOX failed: " . $obj1->error . "\n";exit;}

////////////////////////////////////////////////////////////////////////////////////
// retrieve a list of UID (array)
$uid_list = $obj1->uid_list();

////////////////////////////////////////////////////////////////////////////////////
// retrieve the (unfolded) header (string)
$unfolded_header_arr = $obj1->get_unfolded_header_from_uid($uid);

////////////////////////////////////////////////////////////////////////////////////
// retrieve the header in json-format (string)
$convert_header_to_lowercase=true;
$header_json = $obj1->get_header_from_uid_in_json($uid, $convert_header_to_lowercase);

////////////////////////////////////////////////////////////////////////////////////
// retrieve the (unfolded) header with keys (array)
$header_with_key = $obj1->get_header_from_uid_with_key($uid,true);




////////////////////////////////////////////////////////////////////////////////////
// "message_to_array" parses the entire message into an array-structure
$mail_structured = $obj1->message_to_array($last_uid);


echo "\n".$header_json."\n\n";
print_r($mail_structured);
?>

