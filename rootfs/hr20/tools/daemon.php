<?php

// config part
$TIMEZONE="Europe/Warsaw";

$names = array (
  0x01 => 'salon',
  0x02 => 'sypialnia',
  0x03 => 'lazienka',
  0x04 => 'glowica3',
  0x05 => 'glowica4'
);


$api_keys = array (
  0x01 => '92ZCY8EOCPGMS6ZD', // salon
  0x02 => 'ZKRHX0YOMEQ1X335', // sypialnia
  0x03 => 'S3VRUOD5AQ3I6JAI', // lazienka
  0x04 => '',
  0x05 => ''
);

// NOTE: this file is hudge dirty hack, will be rewriteln
echo "OpenHR20 PHP Daemon\n";
date_default_timezone_set($TIMEZONE);
$maxDebugLines = 1000;

function weights($char) {
    $weights_table = array (
        'D' => 10,
        'S' => 4,
        'W' => 4,
        'G' => 2,
        'R' => 2,
	'T' => 2
    );
    if (isset($weights_table[$char]))
        return $weights_table[$char];
    else 
        return 10;
}

$db = new SQLite3("/config/db/openhr20.sqlite");
$db->query("PRAGMA synchronous=OFF");

$fp=fsockopen("127.0.0.1",4444);
//$fp=fopen("php://stdin","r"); 
//$fp=fopen("/dev/ttyUSB0","w+"); 

//while(($line=stream_get_line($fp,256,"\n"))!=FALSE) {

$addr=-1;
$trans = false;

while(($line=fgets($fp,256))!==FALSE) {
    $line=trim($line);
    if ($line == "") continue; // ignore empty lines
    $debug=true;
    echo " < ".$line."\n";
	$force=false;
    $ts=microtime(true);
    if ($line{0}=='(' && $line{3}==')') {
	   $addr = hexdec(substr($line,1,2));
	   $data = substr($line,4);
       if ($line{4}=='{') {
    	   if (!$trans) $db->query("BEGIN TRANSACTION");
    	   $trans=true;
       }

    } else if ($line{0}=='*') {
	   $db->query("DELETE FROM command_queue WHERE id=(SELECT id FROM command_queue WHERE addr=$addr AND send>0 ORDER BY send LIMIT 1)");
	   $force=true;
       $data = substr($line,1);
    } else if ($line{0}=='-') {
	   $data = substr($line,1);
    } else if ($line=='}') { 
        if ($trans) $db->query("COMMIT TRANSACTION");
        $trans=false;
 	    $data = substr($line,1);
        $addr=0;
    } else {
        $addr=0;
    }
    
    if ($line=="RTC?") {
    	list($usec, $sec) = explode(" ", microtime());
    	$items = getdate($sec);
    	$time = sprintf("H%02x%02x%02x%02x\n",
    	    $items['hours'], $items['minutes'], $items['seconds'], round($usec*100));
    	$date = sprintf("Y%02x%02x%02x\n",
    	    $items['year']-2000, $items['mon'], $items['mday']);
    	echo $time ." ". $date;
    	fwrite($fp,$date); fwrite($fp,$time);
    	$debug=false;
    } else if (($line=="OK") || (($line{0}=='d') && ($line{2}==' '))) {
        $debug=false;
    } else if (($line=="N0?") || ($line=="N1?")) {
        $result = $db->query("SELECT addr,count(*) AS c FROM command_queue GROUP BY addr ORDER BY c");
        // $result = $db->query("SELECT addr,count(*) AS c FROM command_queue WHERE send=0 GROUP BY addr ORDER BY c");
    	$req = array(0,0,0,0);
    	$v = "O0000\n";
	$pr = 0;
        while ($row = $result->fetchArray()) {
            $addr = $row['addr'];
            if (($addr>0) && ($addr<30)) {
                unset($v);
                if (($line=="N1?")&&($row['c']>20)) {
                    $v=sprintf("O%02x%02x\n",$addr,$pr);
		    $pr=$addr;
                    continue;
                }
                $req[(int)$addr/8] |= (int)pow(2,($addr%8));
            }
        }
        if (!isset($v)) $v = sprintf("P%02x%02x%02x%02x\n",$req[0],$req[1],$req[2],$req[3]);
        echo $v; fwrite($fp,$v);
        //fwrite($fp,"P14000000\n");
        $debug=false;
    } else {
    	if ($addr>0) {
    	  if ($data{0}=='?') {
    	    $debug=false;
    	    // echo "data req addr $addr\n";
    	    $db->query("BEGIN TRANSACTION");
    	    $result = $db->query("SELECT id,data FROM command_queue WHERE addr=".($addr&0x7f)." ORDER BY time LIMIT 25");
    	    $weight=0;
    	    $bank=0;
    	    $send=0;
    	    $q='';
    	    while ($row = $result->fetchArray()) {
    	       $cw = weights($row['data']{0});
    	       $weight += $cw;
               weights($row['data']{0});
    	       if ($weight>10) {
                    if (++$bank>=7) break;
                    $weight=$cw;
               }
    	       $r = sprintf("(%02x-%x)%s\n",$addr,$bank,$row['data']);
    	       $q.=$r;
               echo $r;
               $send++;
               $db->query("UPDATE command_queue SET send=$send WHERE id=".$row['id']);
            }
            fwrite($fp,$q);
    	    $db->query("COMMIT");
    
    	    //$debug=false;
    	  } else if ($data{1}=='[' && $data{4}==']' && $data{5}=='=') {
    	    $idx=hexdec(substr($data,2,2));
    	    $value=hexdec(substr($data,6));
    	    switch ($data{0}) {
    	    case 'G':
    	    case 'S':
    		$table='eeprom';
    		break;
    	    case 'R':
    	    case 'W':
    		$table='timers';
    		break;
    	    case 'T':
    		$table='trace';
    		break;
    	    default:
    		$table=null;
    	    }
    	    echo " table $table\n";
    	    if ($table!==null) {
    	      $db->query("UPDATE $table SET time=".time().",value=$value WHERE addr=$addr AND idx=$idx");
    	      $changes=$db->changes();
    	      if ($changes==0)
    	        $db->query("INSERT INTO $table (time,addr,idx,value) VALUES (".time().",$addr,$idx,$value)");
    	    }
    	  } else if ($data{0}=='V') {
    	      $db->query("UPDATE versions SET time=".time().",data='$data' WHERE addr=$addr");
    	      $changes=$db->changes();
    	      if ($changes==0)
    	        $db->query("INSERT INTO versions (addr,time,data) VALUES ($addr,".time().",'$data')");
	  } else if (($data{0}=='D'||$data{0}=='A') && $data{1}==' ') {
    	    $items = explode(' ',$data);
    	    unset($items[0]);
    	    $t=0;
    	    $st=array();
            $st['window'] = 0;
    	    foreach ($items as $item) {
                switch ($item{0}) {
                    case 'm':
                        $t+=60*(int)(substr($item,1));
                        break;
                    case 's':
                        $t+=(int)(substr($item,1));
                        break;
                    case 'A':
                        $st['mode']='AUTO';
                        break;
                    case '-':
                        $st['mode']='-';
                        break;
                    case 'M':
                        $st['mode']='MANU';
                        break;
                    case 'V':
                        $st['valve']=(int)(substr($item,1));
                        break;
                    case 'I':
                        $st['real']=(int)(substr($item,1));
                        break;
                    case 'S':
                        $st['wanted']=(int)(substr($item,1));
                        break;
                    case 'B':
                        $st['battery']=(int)(substr($item,1));
                        break;
                    case 'E':
                        $st['error']=hexdec(substr($item,1));
                        break;
                    case 'W':
                        $st['window']=1;
                        break;
                    case 'X':
                        $st['force']=1;
                        break;
                }
                if ($force) $st['force']=1;
    	    }
            $vars=""; $val="";
            foreach ($st as $k=>$v) {
                $vars.=",".$k;
                if (is_int($v)) $val.=",".$v;
                else $val.=",'".$v."'";
            }
            $time = time();
            if (($time % 3600)<$t) $time-=3600;
            $time = (int)($time/3600)*3600+$t;
        	$db->query("INSERT INTO log (time,addr$vars) VALUES ($time,$addr$val)\n");

	        $cmnd = "mosquitto_pub -h 192.168.1.2 -p 1883 -r -t \"dom/".$names[(int)$addr]."/hr20\" -m '{\"t_real\": ".((int)$st['real']/100).", \"t_wanted\": ".((int)$st['wanted']/100).", \"valve\": ".$st['valve'].", \"battery\": ".(int)(((((int)$st['battery']/1000) - 1.85)/1.03 * 100)).", \"mode\": \"".$st['mode']."\", \"error\": ".$st['error']."}'";
		echo $cmnd."\n";
		system($cmnd); 

    	  }
    	}
    }
    
    
    if ($debug) { //debug log
    	echo $line."\n"; 
    	$db->query("INSERT INTO debug_log (time,addr,data) VALUES (".time().",$addr,\"$line\")");
	$deleteThld = $db->lastInsertRowid()-$maxDebugLines;
	$db->query("DELETE FROM debug_log WHERE id<$deleteThld");	
    }
	// echo "         duration ".(microtime(true)-$ts)."\n";
} 
