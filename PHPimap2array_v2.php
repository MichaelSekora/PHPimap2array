<?php
class PHPimap2array_v2
{
  public static $bodyparts_array=array();
  public function getToken_outlook($grant_type, $client_id, $client_secret, $tenant_id, $email)
  {
    $scope = "https://outlook.office365.com/.default";
    $token_endpoint = "https://login.microsoftonline.com/$tenant_id/oauth2/v2.0/token";
    $params = array(
        'grant_type' => $grant_type,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'scope' => $scope
    );
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $token_endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($response, true);
    $accessToken = $json['access_token'];
    $authstring = base64_encode("user=".$email.chr(1)."auth=Bearer ".$accessToken.chr(1).chr(1));
    return $authstring;
  }

  private $fp;
  public $error;
  public function init($host, $port)
  {
    if (($this->fp = fsockopen($host, $port, $errno, $errstr, 15))===false)
    {
      $this->error = "Could not connect to host ($errno) $errstr";
      return false;
    }
    if (!stream_set_timeout($this->fp, 15))
    {
      $this->error = "Could not set timeout";
      return false;
    }
      $line = fgets($this->fp);
      return true;
  }

  public function init_v2($host)
  {
    $contextOptions = array(
      'ssl' => array(
          'verify_peer' => false
      )
    );
    $context = stream_context_create($contextOptions);

    if (($this->fp = stream_socket_client($host, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context))===false)
    {
      $this->error = "Could not connect to host ($errno) $errstr";
      return false;
    }
    if (!stream_set_timeout($this->fp, 15))
    {
      $this->error = "Could not set timeout";
      return false;
    }
      $line = fgets($this->fp);
      return true;
  }

  public function close()
  {
    fclose($this->fp);
  }

  private $command_counter = "00000001";
  public $server_response_payload = array();
  public $server_response_status ='';

  private function command($command)
  {
      $this->server_response_payload = array();
      $this->server_response_status = '';
      fwrite($this->fp, "$this->command_counter $command\r\n");
      while ($line = fgets($this->fp))
      {
          $line=str_replace("\r\n", "", $line);
          // server response untagged (for DATA)
          if (preg_match('~\* OK~', $line))
          {
            $this->server_response_status = $line;
          }
          if (preg_match('~(\* BAD)|(\* NO)~', $line))
          {
            echo "\n".$line;
            $this->server_response_status = $line;
            break;
          }
          // server response tagged
          if (preg_match('~'.$this->command_counter.' OK~', $line))
          {
            $this->server_response_status = $line;
            break;
          }
          if (preg_match('~'.$this->command_counter.' BAD~', $line))
          {
            echo "\n".$line;
            $this->server_response_status = $line;
            return false;
          }
          $this->server_response_payload[] = $line;
      }
      $this->command_counter = sprintf('%08d', intval($this->command_counter) + 1);
  }

  public function xoauth2_authenticate($bearertoken)
  {
    if (($this->command("AUTHENTICATE XOAUTH2 ".$bearertoken))===false){return false;}
    if (preg_match('~ OK~', $this->server_response_status)){return true;}
    else
    {
      $this->error = $this->server_response_status;
      $this->close();
      return false;
    }
  }
  
  public function plain_authenticate($username, $password)
  {
    $authstring = base64_encode("\0".$username."\0".$password);
    if (($this->command("AUTHENTICATE PLAIN ".$authstring))===false){return false;}
    if (preg_match('~ OK~', $this->server_response_status)){return true;}
    else
    {
      $this->error = $this->server_response_status;
      $this->close();
      return false;
    }
  }

  public function array_search_keyposition($needle, $haystack, $strict=false)
  {
    for ($counter=0; $counter < sizeof($haystack); $counter++)
    {
      $pos1=strpos($haystack[$counter],$needle);
      if ($pos1 > 0 && !$strict){return $counter;}
      if ($pos1 > 0 && $haystack[$counter]==$needle){return $counter;}
    }
    return false;
  }

  public function select_folder($folder)
  {
    if (($this->command("SELECT $folder"))===false){return false;}
    if (preg_match('~ OK~', $this->server_response_status))
    {
      $tmp1 = $this->array_search_keyposition('EXISTS', $this->server_response_payload, false);
      $tmpstring1 = $this->server_response_payload[$tmp1];
      $tmpstring2 = substr($tmpstring1, 0, strpos($tmpstring1, 'EXISTS'));
      $tmparr = preg_split("/\s/", $tmpstring2, 0, PREG_SPLIT_NO_EMPTY);
      $last_uid = trim($tmparr[sizeof($tmparr)-1], " ()");
      return $last_uid;
    }
    else
    {
      $this->error = $this->server_response_status;
      $this->close();
      return false;
    }
  }

  public function uid_list()
  {
    if (($this->command("UID SEARCH ALL"))===false){return false;}
    if (preg_match('~ OK~', $this->server_response_status))
    {
      $tmp1 = $this->array_search_keyposition('SEARCH', $this->server_response_payload, false);
      $tmpstring1 = $this->server_response_payload[$tmp1];
      $tmpstring2 = substr($tmpstring1, strpos($tmpstring1, 'SEARCH')+7);
      $tmparr = preg_split("/\s/", $tmpstring2, 0, PREG_SPLIT_NO_EMPTY);
      return $tmparr;
    }
    else
    {
      $this->error = $this->server_response_status;
      $this->close();
      return false;
    }
  }

  public function get_message_from_uid($uid)
  {
    if (($this->command("UID FETCH $uid BODY.PEEK[]"))===false){return false;}
    if (preg_match('~FETCH.*BODY~', $this->server_response_payload[0]))
    {
      array_shift($this->server_response_payload);
      if (strlen($this->server_response_payload[1])==0) {array_shift($this->server_response_payload);}
      $message  = array();
      for($itemcounter=0; $itemcounter < sizeof($this->server_response_payload); $itemcounter++)
      {
        $item = $this->server_response_payload[$itemcounter];
        // merge into one line if line starts with content-type
        if (strtolower(substr($item, 0, 12))=="content-type")
        {
          $itemcounter++;
          while (preg_match("~^\s~",substr($this->server_response_payload[$itemcounter], 0, 1)))
          {
            $item.= substr($this->server_response_payload[$itemcounter],0);
            $itemcounter++;
          }
          $itemcounter--;
        }
        $message[] = $item;
      }
      return $message;
    }
    else
    {
      $this->error = $this->server_response_status;
      $this->close();
      return false;
    }
  }

  public function get_header_from_uid($uid)
  {
    if (($this->command("UID FETCH $uid BODY.PEEK[HEADER]"))===false){return false;}
    if (preg_match('~FETCH.*BODY~', $this->server_response_payload[0]))
    {
      array_shift($this->server_response_payload);
      if (strlen($this->server_response_payload[1])==0) {array_shift($this->server_response_payload);}
      $header  = array();
      foreach ($this->server_response_payload as $item)
      {
        $header[] = $item;
      }
      return $header;
    }
    else
    {
      $this->error = $this->server_response_status;
      $this->close();
      return false;
    }
  }

  public function get_unfolded_header_from_uid($uid)
  {
    $folded_header = $this->get_header_from_uid($uid);
    if ($folded_header===false){return false;}
    $unfolded_header = $this->unfold($folded_header);
    if (sizeof($unfolded_header) > 0){return $unfolded_header;}
    return false;
  }

  function unfold($string_array)
  {
    $string_array_unfolded = array();
    for ($counter=0; $counter < sizeof($string_array); $counter++)
    {
      if (preg_match("~^\S*:~",$string_array[$counter]))
      {
        $tmp1 = $string_array[$counter];
        $counter++;
        while($counter < sizeof($string_array) && !preg_match("~^\S*:~",$string_array[$counter]))
        {
          if (preg_match("~\s~",substr($string_array[$counter],0,1)))
          {$tmp1.=substr($string_array[$counter], 1);}
          else
          {$tmp1.=substr($string_array[$counter], 0);}
          $counter++;
        }
        $counter--;
        $string_array_unfolded[]=$tmp1;
      }
    }
    return $string_array_unfolded;
  }

  function unfold_with_key($string_array, $convert_header_to_lowercase=false)
  {
    $string_array_with_key = array();
    $string_array_unfolded=$this->unfold($string_array);
    for ($counter=0; $counter < sizeof($string_array_unfolded); $counter++)
    {
      if (preg_match("~^\S*:~",$string_array_unfolded[$counter],$matches))
      {
        if ($convert_header_to_lowercase)
        {$string_array_with_key[strtolower(substr($matches[0],0,-1))]=substr($string_array_unfolded[$counter], strlen($matches[0])+1);}
        else
        {$string_array_with_key[substr($matches[0],0,-1)]=substr($string_array_unfolded[$counter], strlen($matches[0])+1);}
      }
    }
    return $string_array_with_key;
  }

  public function get_header_from_uid_with_key($uid, $convert_header_to_lowercase=false)
  {
    $folded_header = $this->get_header_from_uid($uid);
    if ($folded_header===false){return false;}
    $unfolded_header_with_key = $this->unfold_with_key($folded_header, $convert_header_to_lowercase);
    if (sizeof($unfolded_header_with_key) > 0){return $unfolded_header_with_key;}
    return false;
  }

  public function get_header_from_uid_in_json($uid, $convert_header_to_lowercase=false)
  {
    $folded_header = $this->get_header_from_uid($uid);
    if ($folded_header===false){return false;}
    $unfolded_header_with_key = $this->unfold_with_key($folded_header, $convert_header_to_lowercase);
    $header_json = json_encode($unfolded_header_with_key);
    if (strlen($header_json) > 0){return $header_json;}
    return false;
  }

  public function message_to_array($uid)
  {
    $message= $this->get_message_from_uid($uid);
    if ($message===false){return false;}
    $message_header = array();
    $message_body = array();
    $message_headers = array();
    $messagepart=false;
    foreach ($message as $item)
    {
      if (strlen($item)==0){$messagepart=true;continue;}
      if (!$messagepart){$message_header[]=$item;}
      else {$message_body[]=$item;}
    }
    $message_header_unfolded = $this->unfold($message_header);
    foreach ($message_header_unfolded as $item)
    {
      $tmp2 = preg_split("/:/",$item,2,PREG_SPLIT_NO_EMPTY);
      $message_headers[strtolower($tmp2[0])]=trim($tmp2[1]);
    }
    echo "\nbefore build struct\n";
    $first_boundary=PHPimap2array_v2_functions::get_boundary($message_headers["content-type"]);
    if ($first_boundary==""){$first_boundary="XXooXXllXXooXXooXXllXXoo";}
    echo "\n----------------------first_boundary:".$first_boundary;
    $first_content_type = PHPimap2array_v2_functions::get_content_type($message_headers["content-type"]);
    echo "\n----------------------content-type:".$first_content_type;
    $start_building = PHPimap2array_v2_functions::build_struct($message_body, $first_boundary, "", $first_content_type, $message_headers);
    echo "\n----------------------start_buildung:".$start_building."\n\n\n";
    return PHPimap2array_v2::$bodyparts_array;
  }

  public function print_raw_message($uid)
  {
    $tmp='';
    $message= $this->get_message_from_uid($uid);
    if ($message===false){return false;}
    foreach ($message as $item)
    {
      $tmp.=$item."\n";
    }
    return $tmp;
  }

  public function print_what_looks_like_a_boundary($uid)
  {
    $tmp='';
    $message= $this->get_message_from_uid($uid);
    if ($message===false){return false;}
    foreach ($message as $item)
    {
      if (substr($item, 0, 2)=="--")
      $tmp.=$item."\n";
    }
    return $tmp;
  }
  
  public function unfold_bodypart($bodypart, $content_transfer_encoding)
  {
    $bodytext='';
    // for base64
    foreach($bodypart as $item)
    {$bodytext.= $item;}
    if (strtolower(substr(trim($content_transfer_encoding), 0, 16))=='quoted-printable')
    {
      $bodytext='';
      foreach($bodypart as $item)
      {
        if (substr($item, -1)=='='){$bodytext.= substr($item, 0, -1);}
        else{$bodytext.= $item."=0D=0A";}
      }
    }
    if (strtolower(substr(trim($content_transfer_encoding), 0, 6))=='binary' 
    || strtolower(substr(trim($content_transfer_encoding), 0, 4))=='8bit' 
    || strtolower(substr(trim($content_transfer_encoding), 0, 4))=='7bit')
    {
      $bodytext='';
      foreach($bodypart as $item)
      {
        if (substr($item, -1)=='='){$bodytext.= substr($item, 0, -1);}
        else{$bodytext.= $item."\r\n";}
      }
    }
    return $bodytext;
  }

  public function convert_to_UTF8($bodypart, $content_transfer_encoding)
  {
    $bodytext=$bodypart;
    // encoding
    if (substr(trim($content_transfer_encoding), 0, 16)=='quoted-printable')
    {
      $bodytext=quoted_printable_decode($bodypart);
    }
    if (substr(trim($content_transfer_encoding), 0, 6)=='base64')
    {
      $bodytext=base64_decode($bodypart);
    }

    // charset
    $from_charset=null;
    $pos1 = strpos($bodytext, 'charset');
    if (strtolower(substr($bodytext, $pos1+8, 9))=='iso-8859-')
    {
      $from_charset = 'ISO-8859-'.substr($bodytext, $pos1+17, 1);
    }
    if (strtolower(substr($bodytext, $pos1+8, 8))=='windows-')
    {
      $from_charset = 'WINDOWS-'.substr($bodytext, $pos1+16, 4);
    }
    if ($from_charset===null)
    {$result = mb_convert_encoding($bodytext, 'UTF8');}
    else
    {return mb_convert_encoding($bodytext, 'UTF8', $from_charset);}

    return $result;
  }
}

class PHPimap2array_v2_functions
{
  static function get_content_type($content_type_string)
  {
    $content_type=false;
    $tmp_substr1 = ltrim(str_replace(array(":",";"), " ", substr($content_type_string, 0, 50)));
    $pos2 = preg_match('/\s/', $tmp_substr1, $matches, PREG_OFFSET_CAPTURE);
    if ($pos2){$pos2 = $matches[0][1];}else{$pos2 = strlen($tmp_substr1);}
    $content_type = trim(substr($tmp_substr1, 0, $pos2));
    echo "\ncontent-type:".$content_type.":";
    return $content_type;
  }

  static function get_boundary($content_type_string)
  {
    $pos1=0;
    $pos2=0;
    $boundary_position=0;
    $boundary=false;
    if (strpos($content_type_string, 'boundary='))
    {
      $boundary_position = strpos($content_type_string, "boundary=");
      // check if boundary starts with single or double quote and remember
      if (substr($content_type_string, $boundary_position+9,1)=="'")
      {
        $boundary_delimiter="'";
        $pos1 = $boundary_position+10;
        $tmp_substr1 = substr($content_type_string, $pos1);
        $pos2 = strpos($tmp_substr1, $boundary_delimiter, 3);
        if (!$pos2){$pos2 = strlen($tmp_substr1);}
      }
      else
      {
        if (substr($content_type_string, $boundary_position+9,1)=='"')
        {
          $boundary_delimiter='"';
          $pos1 = $boundary_position+10;
          $tmp_substr1 = substr($content_type_string, $pos1);
          $pos2 = strlen($tmp_substr1);
          $pos2 = strpos($tmp_substr1, $boundary_delimiter, 3);
          if (!$pos2){$pos2 = strlen($tmp_substr1);}
        }
        else
        {
          $boundary_delimiter='none';
          $pos1 = $boundary_position+9;
          $tmp_substr1 = substr($content_type_string, $pos1);
          $pos2 = preg_match('/\s/', $tmp_substr1, $matches, PREG_OFFSET_CAPTURE);
          if ($pos2){$pos2 = $matches[0][1];}else{$pos2 = strlen($tmp_substr1);}
        }
      }
      $boundary = "--".trim(substr($tmp_substr1, 0, $pos2), "\x22\x27");
    }
    return $boundary;
  }

  static function build_struct($bodypart,$boundary,$hierarchy,$member_of_subtype_in, $message_headers=null)
  {
    echo "\n-----build_struct-----------------------------------------------------------------";
    echo "\n----$hierarchy-------$member_of_subtype_in---------------------------------------";
    echo "\n-----build_struct-----------------------------------------------------------------";
 
    $bodypart_work= array();
    $bodypart_work_headerpart = array();
    
    $hierarchy_sub = 0;
    if (strlen($hierarchy)>0){$hierarchy = $hierarchy.".";}

    // create array for bodypart-positions
    $array_parts= array();
    // boundary-positions for multipart mails
    echo "\n---- build struct/sizeof bodypart:".sizeof($bodypart)."\n";
    for ($counter=0; $counter < sizeof($bodypart); $counter++)
    {
      if ($bodypart[$counter]==$boundary)
      {
        $array_parts[]=array($counter,$bodypart[$counter]);
      }
    }
    for ($counter=0; $counter < sizeof($bodypart); $counter++)
    {
      if ($bodypart[$counter]==$boundary."--")
      {
        echo "\n---- build struct/found boundary:".$bodypart[$counter];
        $array_parts[]=array($counter,$bodypart[$counter]);
      }
    }
    echo "\n---- build struct array_parts";
    echo "\n---- build struct array_parts";
      
    // positions for simple-part mails
    if (sizeof($array_parts)==0)
    {
      echo "\nposition for simple-part mail";
      $array_parts[]=array(-1,"no boundary");
      $array_parts[]=array(sizeof($bodypart),"no boundary");
    }

    // walk through array_parts
    $number_of_lines_in_bodypart_work_header=0;
    for($pcount=0; $pcount<sizeof($array_parts)-1; $pcount++)
    {
      $hierarchy_sub++;
      $partsposition1 = intval($array_parts[$pcount][0]);
      $partsposition2 = intval($array_parts[$pcount+1][0]);
      $bodypart_work= array();

      for($line_work=$partsposition1+1;$line_work < $partsposition2; $line_work++)
      {$bodypart_work[]=$bodypart[$line_work];}

      $message_begins=false;
      // build bodypart_work_headerpart
      $bodypart_work_headerpart = array();
      $number_of_lines_in_bodypart_work_header=0;
      for ($counter=0; $counter < sizeof($bodypart_work); $counter++)
      {
        if (preg_match("~^--.*~",$bodypart_work[$counter])) {$message_begins=true;}
        if (!$message_begins && preg_match("~^content-.*:~",strtolower($bodypart_work[$counter])))
        {
          $number_of_lines_in_bodypart_work_header++;
          $tmp1 = $bodypart_work[$counter];
          $counter++;
          while($counter < sizeof($bodypart_work) && preg_match("~^\s.*~",$bodypart_work[$counter]))
          {
            $number_of_lines_in_bodypart_work_header++;
            $tmp1.=$bodypart_work[$counter];
            $counter++;
          }
          $counter--;
          $tmp_arr = preg_split("/:/",$tmp1,2,PREG_SPLIT_NO_EMPTY);
          $bodypart_work_headerpart[ucwords($tmp_arr[0], "- \t\r\n\f\v")]=trim($tmp_arr[1]);
        }
        else
        {
          if ($counter > 5){$messagebegins=true;}
        }
      }
      // remove bodypart_header from bodypart_work
      $bodypart_work = array_slice($bodypart_work, $number_of_lines_in_bodypart_work_header);
      
      // extract Content-Type, get boundary if exists
      if ( (isset($bodypart_work_headerpart["Content-Type"])) && ($pos1 = strpos($bodypart_work_headerpart["Content-Type"], "boundary="))   ) // multipart
      {
        // multipart
        $boundary = PHPimap2array_v2_functions::get_boundary($bodypart_work_headerpart["Content-Type"]);
        $content_type = PHPimap2array_v2_functions::get_content_type($bodypart_work_headerpart["Content-Type"]);
        echo "\nmultipart found:".$content_type."__".$boundary."\n\n";
        PHPimap2array_v2::$bodyparts_array[]=array(
          array("bodypart_position"=>$hierarchy.$hierarchy_sub, "member_of_subtype"=>$member_of_subtype_in, "summary"=>"summary"),
           "bodypart_header"=>$bodypart_work_headerpart, "bodypart"=>"");
        $resultx = PHPimap2array_v2_functions::build_struct($bodypart_work, $boundary, $hierarchy.$hierarchy_sub, $content_type);
      }
      else // simple part
      {
        // simple part
        echo "\nsimple-part found:"."\n\n";
        if ($hierarchy=='' && strtolower(substr($member_of_subtype_in, 0, 4))=='text')
        {
          // simple email (only one part)
          echo "\nsimplepart simple email (only one part) found, hierarchy:".$hierarchy.": memberofsubtype:",$member_of_subtype_in.":\n\n";
          PHPimap2array_v2::$bodyparts_array=array();
          // extract content-type, content-disposition, content-description, content-transfer-encoding, content-language, content-id from header
          $bodypart_work_headerpart["Content-Type"]=$message_headers["content-type"];
          $bodypart_work_headerpart["Content-Disposition"]=$message_headers["content-disposition"];
          $bodypart_work_headerpart["Content-Description"]=$message_headers["content-description"];
          $bodypart_work_headerpart["Content-Transfer-Encoding"]=$message_headers["content-transfer-encoding"];
          $bodypart_work_headerpart["Content-Language"]=$message_headers["content-language"];
          $bodypart_work_headerpart["Content-Id"]=$message_headers["content-id"];
          
          PHPimap2array_v2::$bodyparts_array[]=array(
            array("bodypart_position"=>'', "member_of_subtype"=>'', "summary"=>"summary"),
                "bodypart_header"=>$bodypart_work_headerpart, "bodypart"=>$bodypart_work);
        }
        else
        {
          // multipart lasthierarchy
          echo "\nsimplepart multipart lasthierarchy found, hierarchy:".$hierarchy.": memberofsubtype:",$member_of_subtype_in.":\n\n";
          PHPimap2array_v2::$bodyparts_array[]=array(
          array("bodypart_position"=>$hierarchy.$hierarchy_sub, "member_of_subtype"=>$member_of_subtype_in, "summary"=>"summary"),
              "bodypart_header"=>$bodypart_work_headerpart, "bodypart"=>$bodypart_work);
        }
      }
    }
  }
}

?>