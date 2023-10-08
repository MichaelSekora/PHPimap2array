<?php
class PHPimap2array
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
    if (!($this->fp = fsockopen($host, $port, $errno, $errstr, 15)))
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

  private function close()
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
            $this->server_response_status = $line;
            break;
          }
          // server response tagged
          if (preg_match('~'.$this->command_counter.' OK~', $line))
          {
            $this->server_response_status = $line;
            break;
          }
          $this->server_response_payload[] = $line;
      }
      $this->command_counter = sprintf('%08d', intval($this->command_counter) + 1);

  }


  public function xoauth2_authenticate($bearertoken)
  {
    $this->command("AUTHENTICATE XOAUTH2 ".$bearertoken);
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
    $this->command("SELECT $folder");
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


  public function get_message_from_uid($uid)
  {
    $this->command("FETCH $uid BODY.PEEK[]");
    if (preg_match('~FETCH.*BODY~', $this->server_response_payload[0]))
    {
      array_shift($this->server_response_payload);
      if (strlen($this->server_response_payload[1])==0) {array_shift($this->server_response_payload);}
      $message  = array();
      foreach ($this->server_response_payload as $item)
      {
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
          $tmp1.=$string_array[$counter];
          $counter++;
        }
        $counter--;
        $string_array_unfolded[]=$tmp1;
      }
    }
    return $string_array_unfolded;
  }

  public function message_to_array($uid)
  {
    $message= $this->get_message_from_uid($uid);
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

    function build_struct($bodypart,$boundary,$hierarchy,$member_of_subtype_in)
    {
      $hierarchy_sub = 0;
      if (strlen($hierarchy)>0){$hierarchy = $hierarchy.".";}
      $bodypart_unfolded = array();

      // create array for bodypart-positions
      $array_parts= array();
      // boundary-positions for multipart mails
      for ($counter=0; $counter < sizeof($bodypart); $counter++)
      {if ($bodypart[$counter]==$boundary){$array_parts[]=array($counter,$bodypart[$counter]);}}
      for ($counter=0; $counter < sizeof($bodypart); $counter++)
      {if ($bodypart[$counter]==$boundary."--"){$array_parts[]=array($counter,$bodypart[$counter]);}}
      // positions for simple-part mails
      if (sizeof($array_parts)==0)
      {$array_parts[]=array(-1,"no boundary");$array_parts[]=array(sizeof($bodypart),"no boundary");}

      for($pcount=0; $pcount<sizeof($array_parts)-1; $pcount++)
      {
        $hierarchy_sub++;
        $partsposition1 = intval($array_parts[$pcount][0]);
        $partsposition2 = intval($array_parts[$pcount+1][0]);
        $bodypart_work= array();

        for($line_work=$partsposition1+1;$line_work < $partsposition2; $line_work++)
        {$bodypart_work[]=$bodypart[$line_work];}

        $message_begins=false;
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
            $tmp2 = ucwords($tmp_arr[0], "- \t\r\n\f\v").":".$tmp_arr[1];
            $bodypart_work_headerpart[ucwords($tmp_arr[0], "- \t\r\n\f\v")]=$tmp_arr[1];
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
          $boundary = get_boundary($bodypart_work_headerpart["Content-Type"]);
          $content_type = get_content_type($bodypart_work_headerpart["Content-Type"]);
          PHPimap2array::$bodyparts_array[]=array(
            array("bodypart_position"=>$hierarchy.$hierarchy_sub, "member_of_subtype"=>$member_of_subtype_in, "summary"=>"summary"),
             "bodypart_header"=>$bodypart_work_headerpart, "bodypart"=>"");
          $resultx = build_struct($bodypart_work, $boundary, $hierarchy.$hierarchy_sub, $content_type);
        }
        else // simple part
        {
          PHPimap2array::$bodyparts_array[]=array(
          array("bodypart_position"=>$hierarchy.$hierarchy_sub, "member_of_subtype"=>$member_of_subtype_in, "summary"=>"summary"),
              "bodypart_header"=>$bodypart_work_headerpart, "bodypart"=>$bodypart_work);
        }

      }
    }

    function get_boundary($content_type_string)
    {
      $boundary=false;
      if (strpos($content_type_string, 'boundary'))
      {
        $tmp_substr1 = substr($content_type_string, strpos($content_type_string, "boundary=")+9);
        $pos2 = preg_match('/\s/', $tmp_substr1, $matches, PREG_OFFSET_CAPTURE);
        if ($pos2){$pos2 = $matches[0][1];}else{$pos2 = strlen($tmp_substr1);}
        $boundary = "--".trim(substr($tmp_substr1, 0, $pos2), "\x22\x27");
      }
      return $boundary;
    }

    function get_content_type($content_type_string)
    {
      $content_type=false;
      $tmp_substr1 = ltrim(str_replace(array(":",";"), " ", substr($content_type_string, 0, 50)));
      $pos2 = preg_match('/\s/', $tmp_substr1, $matches, PREG_OFFSET_CAPTURE);
      if ($pos2){$pos2 = $matches[0][1];}else{$pos2 = strlen($tmp_substr1);}
      $content_type = trim(substr($tmp_substr1, 0, $pos2));
      return $content_type;
    }


    $first_boundary=get_boundary($message_headers["content-type"]);
    $first_content_type = get_content_type($message_headers["content-type"]);
    $start_building = build_struct($message_body, $first_boundary, "", $first_content_type);
    $this->close();
    return PHPimap2array::$bodyparts_array;
  }
}


?>
