# PHPimap2array
Authenticates via XOAUTH2 , retrieves mails using IMAP and parses the entire message into an array.  
At this point, authentication is implemented only for Outlook-Accounts.  
Please follow the procedure that Microsoft provides to create client-credentials on Azure.

## INSTALLATION
include the class in your php-code 
```php 
include_once "PHPimap2array.php";
```

## USAGE
```php
<?php
include_once "PHPimap2array.php";

////////////////////////////////////////////////////////////////////////////////////
// create and init the object
////////////////////////////////////////////////////////////////////////////////////
$objPHPimap2array = new PHPimap2array();
if ($objPHPimap2array->init('ssl://outlook.office365.com', 993) === false)
{echo "\ninit() failed: " . $objPHPimap2array->error . "\n";exit;}


////////////////////////////////////////////////////////////////////////////////////
// retrieve the bearer token
// XOAUTH2-access to outlook365-accounts works only for work- or school- accounts,
// personal accounts wont work!
////////////////////////////////////////////////////////////////////////////////////
$bearertoken = $objPHPimap2array->getToken_outlook("client_credentials",
                        "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",     // client-id
                        "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", // client-secret
                        "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",     // tenant-id
                        "user@xxxxxxxxxx.onmicrosoft.com");         // user-email

////////////////////////////////////////////////////////////////////////////////////
// authenticate
////////////////////////////////////////////////////////////////////////////////////
if ($objPHPimap2array->xoauth2_authenticate($bearertoken) === false)
{echo "\nauthenticate() failed: " . $objPHPimap2array->error . "\n";exit;}

////////////////////////////////////////////////////////////////////////////////////
// select the INBOX folder and retrieve the newest uid
////////////////////////////////////////////////////////////////////////////////////
$last_uid = $objPHPimap2array->select_folder("INBOX");
if ($last_uid == false)
{echo "\nselect INBOX failed: " . $objPHPimap2array->error . "\n";exit;}

////////////////////////////////////////////////////////////////////////////////////
// "message_to_array" parses the entire message into an array-structure
////////////////////////////////////////////////////////////////////////////////////
$mail_structured = $objPHPimap2array->message_to_array($last_uid);
print_r($mail_structured);
?>

```
  
###
###
###

