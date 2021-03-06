<?php
function print_pre($var)
{
	echo "<pre>";
		print_r($var);
	echo "</pre>";
} 

function display_date($timestamp=0)
{
    if (!$timestamp)
    {
        $timestamp = time();
    }

    return date("j F Y, g:i a", $timestamp);
}

	
$BENCHMARK_TIME = array();
function bench($idx = 0)
{
	global $BENCHMARK_TIME;
	if (!isset($BENCHMARK_TIME[$idx])) { $BENCHMARK_TIME[$idx] = 0; }
	
	if ($BENCHMARK_TIME[$idx] == 0) 
	{
		$BENCHMARK_TIME[$idx] = microtime(true);
	}
	else
	{
		$diff = sprintf ("%.3f", (float) (microtime(true) - $BENCHMARK_TIME[$idx]));
		$BENCHMARK_TIME[$idx] = 0;
		return $diff;
	}
}

function first_line($string)
{
	return strtok($string, "\r\n");
}


function curl_post($url, $post, $header=false)
{
    if(!$header){
        $header = array("Content-type:text/xml; charset=utf-8");
    }

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLINFO_HEADER_OUT, 1);

    $data = curl_exec($ch);
	
	
    /*if (curl_errno($ch)) {
       print "curl_error:" . curl_error($ch).'<br/>';
    } else {
       curl_close($ch);
       print "curl exited okay\n";
       echo "Data returned...\n";
       echo "------------------------------------\n";
       echo $data;
       echo "------------------------------------\n";
    } */
    return $data;
}


function curl_file_get_contents($URL, $header=null)
{
    $c = curl_init();
    if(!$header){
        $header = array("Content-type:text/xml; charset=utf-8");
    }
    curl_setopt($c, CURLOPT_HTTPHEADER, $header);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c, CURLOPT_URL, $URL);
    $contents = curl_exec($c);
    curl_close($c);

    if ($contents) return $contents;
        else return FALSE;
}


function formatResponse($response, $format='xml'){
	header('Cache-Control: no-cache, must-revalidate');
	if($format=='xml'){
		header ("content-type: text/xml");
		$xml = new SimpleXMLELement('<root/>');
		$response = array_flip($response);
		array_walk_recursive($response, array ($xml, 'addChild'));
		print $xml->asXML();
	}elseif($format=='json'){
		header('Content-type: application/json');
		$response = json_encode($response);
		echo $response;
	}elseif($format=='raw'){
		print $response['message'];
	}elseif($format=='raw-xml'){
		header ("content-type: text/xml");
		print($response['message']);
	}
}

function  timeAgo($timestamp, $granularity=2, $format='Y-m-d H:i:s'){
        $difference = time() - $timestamp;
        if($difference < 0) return '0 seconds ago';
        elseif($difference < 864000){
                $periods = array('week' => 604800,'day' => 86400,'hr' => 3600,'min' => 60,'sec' => 1);
                $output = '';
                foreach($periods as $key => $value){
                        if($difference >= $value){
                                $time = round($difference / $value);
                                $difference %= $value;
                                $output .= ($output ? ' ' : '').$time.' ';
                                $output .= (($time > 1 && $key == 'day') ? $key.'s' : $key);
                                $granularity--;
                        }
                        if($granularity == 0) break;
                }
                return ($output ? $output : '0 seconds').' ago';
                 // return ($output ? $output : '0 seconds').'';
        }
        else return date($format, $timestamp); 
}

function ellipsis ($string, $length = 64, $noentities = false)
{
    if (strlen($string) <= $length)
    {
        return $string;
    }
    else
    {
        return substr($string,0, $length-3) . ($noentities ? "..." : "&hellip;");
    }
}

function pluralise($word, $count)
{
    if ($count == 1) return $word;
    else return $word . "s";
}

function readable($text, $singular = false){
	$text = trim(strtolower($text));
	switch($text){
        case "all": return 'All'; break;
		case "draft": return ($singular ? 'Draft' : 'Drafts');break;
		case "submitted_for_assessment": return 'Submitted for Assessment';break;
		case "assessment_in_progress": return 'Assessment In Progress';break;
		case "approved": return ($singular ? 'Approved' : 'Approved Records');break;
		case "published": return  ($singular ? 'Published' : 'Published Records');break;
		case "more_work_required": return 'More Work Required';break;
		case "collection": return 'Collections';break;
		case "party": return 'Parties';break;
		case "service": return 'Services';break;
		case "activity": return 'Activities';break;
        case "role_user": return 'User';break;
        case "role_organisational": return 'Organisation';break;
        case "role_functional": return 'Functional';break;
        case "role_doi_appid": return 'DOI Application Identifier';break;
        case "t": return "<i class='icon icon-ok'></i>";break;
        case "f": return "<i class='icon icon-remove'></i>";break;
        case "1": return "<i class='icon icon-ok'></i>";break;
        case "0": return "<i class='icon icon-remove'></i>";break;
        case "authentication_built_in": return "Built-in";break;
        case "authentication_ldap": return "LDAP";break;
        case "authentication_shibboleth": return "Shibboleth";break;
	}
}

function array_to_TABCSV($data)
{
    $outstream = fopen("php://temp", 'r+');
    foreach($data AS $row)
    {
    	fputcsv($outstream, $row, "\t", '"');
    }
    rewind($outstream);
    $csv = '';
    while (($buffer = fgets($outstream, 4096)) !== false) {
    	$csv .= $buffer;
    }
    fclose($outstream);
    return $csv;
}


function printLoginForm($authenticators, $authenticator , $class, $redirect="")
{
    
    if($authenticator == gCOSI_AUTH_METHOD_SHIBBOLETH)
    {
        print "<div class='".$class."' id='".$authenticator."_LoginForm'>";
        print "<small>Log into the ANDS Online Services Dashboard using your AAF credentials:</small>";
        print " <img src='".asset_url('img/aaf_logo.gif', 'base')."' style='display:block;margin:10px auto;'/>";
        print " <a href='".secure_host_url().gSHIBBOLETH_SESSION_INITIATOR."?target=".secure_base_url()."auth/setUser' class='btn btn-primary btn-block'>Login using ".$authenticators[$authenticator]."</a>";
        print "</div>";
    }
    else
    {
        print "<div class='".$class."' id='".$authenticator."_LoginForm'>";
        print " <form class='form' action='".base_url("auth/login")."' method='post'>";
        print " <input type='hidden' name='redirect' value=".$redirect."/>";
        print "   <div class='control-group'>";
        print "     <div class='controls'>";
        print "         <label>Username</label>";
        print "         <input type='text' id='inputUsername' name='inputUsername' placeholder='Username'>";
        print "     </div>";
        print "   </div>";
        print "   <div class='control-group'>";
        print "     <div class='controls'>";
        print "         <label>Password</label>";
        print "         <input type='password' id='inputPassword' name='inputPassword' placeholder='Password'>";
        print "     </div>";
        print "   </div>";
        print "   <div class='control-group'>";
        print "     <div class='controls'>";
        print "         <button type='submit' class='btn btn-primary btn-block'>Login using ".$authenticators[$authenticator]."</button>";
        print "     </div>";
        print "   </div>";
        print " </form>";
        print "</div>";
    }

}

function printAlternativeLoginControl($authenticators)
{
    //print "<div class='btn-group'>"; // prevent double-padding in <div widget-title>
    print "<a class='btn btn-small dropdown-toggle ' data-toggle='dropdown' href='#'>Alternative Login <b class='caret'></b></a>";
    print "<ul class='dropdown-menu'>";
        foreach($authenticators as $key => $value){
            print "<li class=''><a href='javascript:;' class='loginSelector' id='".$key."'>".$value."</a></li>";
        }
    print "</ul>";
    //print "</div>";
}

function printAlternativeLoginForms($authenticators, $default_authenticator, $redirect)
{
    foreach($authenticators as $key => $value){
        if($key != $default_authenticator)
            printLoginForm($authenticators, $key, 'loginForm hide', $redirect);
    }
}

/*
 * escapeSolrValue
 * escaping sensitive items in a solr query
 */
function escapeSolrValue($string){
    //$string = urldecode($string);
    $match = array('\\','&', '|', '!', '(', ')', '{', '}', '[', ']', '^', '~', '*', '?', ':', ';', '/');
    $replace = array('\\\\','&', '\\|', '\\!', '\\(', '\\)', '\\{', '\\}', '\\[', '\\]', '\\^', '\\~', '\\*', '\\?', '\\:', '\\;', '\\/');
    $string = str_replace($match, $replace, $string);

    if(substr_count($string, '"') % 2 != 0){
        $string = str_replace('"', '\\"', $string);
    }

    return $string;
}

function removeBadValue($string){
    $match = array('%','&',);
    $string = str_replace($match, '', $string);
    return $string;
}