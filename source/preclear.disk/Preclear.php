<?
set_error_handler("log_error");

#########################################################
#############           VARIABLES          ##############
#########################################################

$plugin       = "preclear.disk";
$state_file   = "/var/state/{$plugin}/state.ini";
$log_file     = "/var/log/{$plugin}.log";
$script_files = array( 
                       "gfjardim" => "/usr/local/emhttp/plugins/${plugin}/script/preclear_disk.sh",
                        "joel"     => "/boot/config/plugins/${plugin}/preclear_disk.sh",
                      );

$authors      = array( "gfjardim" => "gfjardim", "joel" => "Joe L.");



$script_files = array_filter($script_files, function ($file) { return $file && is_file( $file ); });


require_once( "webGui/include/Helpers.php" );
require_once( "plugins/${plugin}/assets/lib.php" );

# Search for scripts and remove those absent
foreach ($script_files as $key => $script)
{
  if (is_file($script))
  {
    $script_file = $script;
    $author      = $key;
    @chmod($script_file, 0755);
  }

  else
  {
    unset($script_files[$key]);
  }
}

$script_version = (is_file($script_file)) ? trim(shell_exec("$script_file -v 2>/dev/null|cut -d: -f2")) : NULL;
$fast_postread  = $script_version ? (strpos(file_get_contents($script_file), "fast_postread") ? TRUE : FALSE ) : FALSE;
$notifications  = $script_version ? (strpos(file_get_contents($script_file), "notify_channels") ? TRUE : FALSE ) : FALSE;
$noprompt       = $script_version ? (strpos(file_get_contents($script_file), "noprompt") ? TRUE : FALSE ) : FALSE;
$Preclear       = new Preclear;
$VERBOSE        = TRUE;
$notifications = FALSE;
$fast_postread = FALSE;
if (isset($_POST['display']))
{
  $display = $_POST['display'];
}

if (! is_dir(dirname($state_file)) )
{
  @mkdir(dirname($state_file),0777,TRUE);
}

#########################################################
#############        MISC FUNCTIONS        ##############
#########################################################

function log_error($errno, $errstr, $errfile, $errline) {
  switch($errno){
    case E_ERROR:               $error = "Error";                          break;
    case E_WARNING:             $error = "Warning";                        break;
    case E_PARSE:               $error = "Parse Error";                    break;
    case E_NOTICE:              $error = "Notice";                 return; break;
    case E_CORE_ERROR:          $error = "Core Error";                     break;
    case E_CORE_WARNING:        $error = "Core Warning";                   break;
    case E_COMPILE_ERROR:       $error = "Compile Error";                  break;
    case E_COMPILE_WARNING:     $error = "Compile Warning";                break;
    case E_USER_ERROR:          $error = "User Error";                     break;
    case E_USER_WARNING:        $error = "User Warning";                   break;
    case E_USER_NOTICE:         $error = "User Notice";                    break;
    case E_STRICT:              $error = "Strict Notice";                  break;
    case E_RECOVERABLE_ERROR:   $error = "Recoverable Error";              break;
    default:                    $error = "Unknown error ($errno)"; return; break;
  }
  debug("PHP {$error}: $errstr in {$errfile} on line {$errline}");
}


function debug($msg, $type = "NOTICE")
{
  if ( $type == "DEBUG" && ! $GLOBALS["VERBOSE"] )
  {
    return NULL;
  }
  $msg = "\n".date("D M j G:i:s T Y").": ".print_r($msg,true);
  file_put_contents($GLOBALS["log_file"], $msg, FILE_APPEND);
}


function _echo($m)
{
  echo "<pre>".print_r($m,TRUE)."</pre>";
};


function sendLog() {
  global $var, $paths;
  $url = "http://gfjardim.maxfiles.org";
  $max_size = 2097152; # in bytes
  $notify = "/usr/local/emhttp/webGui/scripts/notify";
  $data = array('data'     => shell_exec("cat '{$GLOBALS['log_file']}' 2>&1 | tail -c $max_size -"),
                'language' => 'text',
                'title'    => '[Preclear Disk log]',
                'private'  => true,
                'expire'   => '2592000');
  $tmpfile = "/tmp/tmp-".mt_rand().".json";
  file_put_contents($tmpfile, json_encode($data));
  $out = shell_exec("curl -s -k -L -X POST -H 'Content-Type: application/json' --data-binary  @$tmpfile ${url}/api/json/create");
  unlink($tmpfile);
  $server = strtoupper($var['NAME']);
  $out = json_decode($out, TRUE);
  if (isset($out['result']['error'])){
    echo shell_exec("$notify -e 'Preclear Disk log upload failed' -s 'Alert [$server] - $title upload failed.' -d 'Upload of Unassigned Devices Log has failed: ".$out['result']['error']."' -i 'alert 1'");
    echo '{"result":"failed"}';
  } else {
    $resp = "${url}/".$out['result']['id']."/".$out['result']['hash'];
    exec("$notify -e 'Preclear Disk log uploaded - [".$out['result']['id']."]' -s 'Notice [$server] - $title uploaded.' -d 'A new copy of Unassigned Devices Log has been uploaded: $resp' -i 'normal 1'");
    echo '{"result":"'.$resp.'"}';
  }
}

function is_tmux_executable()
{
  return is_file("/usr/bin/tmux") ? (is_executable("/usr/bin/tmux") ? TRUE : FALSE) : FALSE;
}


function tmux_is_session($name)
{
  exec('/usr/bin/tmux ls 2>/dev/null|cut -d: -f1', $screens);
  return in_array($name, $screens);
}


function tmux_new_session($name)
{
  if (! tmux_is_session($name))
  {
    exec("/usr/bin/tmux new-session -d -x 140 -y 200 -s '${name}' 2>/dev/null");
  }
}


function tmux_get_session($name)
{
  return (tmux_is_session($name)) ? shell_exec("/usr/bin/tmux capture-pane -t '${name}' 2>/dev/null;/usr/bin/tmux show-buffer 2>&1") : NULL;
}


function tmux_send_command($name, $cmd)
{
  exec("/usr/bin/tmux send -t '$name' '$cmd' ENTER 2>/dev/null");
}


function tmux_kill_window($name)
{
  if (tmux_is_session($name))
  {
    exec("/usr/bin/tmux kill-session -t '${name}' >/dev/null 2>&1");
  }
}


function reload_partition($name)
{
  exec("hdparm -z /dev/{$name} >/dev/null 2>&1 &");
}


function listDir($root)
{
  $iter = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($root, 
          RecursiveDirectoryIterator::SKIP_DOTS),
          RecursiveIteratorIterator::SELF_FIRST,
          RecursiveIteratorIterator::CATCH_GET_CHILD);
  $paths = array();

  foreach ($iter as $path => $fileinfo)
  {
    if (! $fileinfo->isDir()) $paths[] = $path;
  }

  return $paths;
}


function save_ini_file($file, $array)
{
  $res = array();

  foreach($array as $key => $val)
  {
    if(is_array($val))
    {
      $res[] = PHP_EOL."[$key]";

      foreach($val as $skey => $sval)
      {
        $res[] = "$skey = ".(is_numeric($sval) ? $sval : '"'.$sval.'"');
      }
    }

    else
    {
      $res[] = "$key = ".(is_numeric($val) ? $val : '"'.$val.'"');
    }
  }
  file_put_contents($file, implode(PHP_EOL, $res));
}


function get_unasigned_disks()
{
  $paths          = listDir("/dev/disk/by-id");
  $disks_id       = preg_grep("#wwn-|-part#", $paths, PREG_GREP_INVERT);
  $disks_real     = array_map(function($p){return realpath($p);}, $disks_id);
  exec("/usr/bin/strings /boot/config/super.dat 2>/dev/null|grep -Po '.{10,}'", $disks_serial);
  exec("udevadm info --query=property --name /dev/disk/by-label/UNRAID 2>/dev/null|grep -Po 'ID_SERIAL=\K.*'", $flash_serial);
  $disks_cfg      = is_file("/boot/config/disk.cfg") ? parse_ini_file("/boot/config/disk.cfg") : array();
  $cache_serial   = array_flip(preg_grep("#cacheId#i", array_flip($disks_cfg)));
  $unraid_serials = array_merge($disks_serial,$cache_serial,$flash_serial);
  $unraid_disks   = array();

  foreach( $unraid_serials as $serial )
  {
    $unraid_disks = array_merge($unraid_disks, preg_grep("#-".preg_quote($serial, "#")."#", $disks_id));
  }

  $unraid_real = array_map(function($p)
    {
      return realpath($p);
    }, $unraid_disks);

  $unassigned  = array_flip(array_diff(array_combine($disks_id, $disks_real), $unraid_real));
  natsort($unassigned);

  foreach ( $unassigned as $k => $disk )
  {
    unset($unassigned[$k]);
    $parts  = array_values(preg_grep("#{$disk}-part\d+#", $paths));
    $device = realpath($disk);

    if (! is_bool(strpos($device, "/dev/sd")) || ! is_bool(strpos($device, "/dev/hd")))
    {
      $unassigned[$disk] = array("device"=>$device,"partitions"=>$parts);
    }
  }
  debug("\nDisks:\n+ ".implode("\n+ ", array_map(function($k,$v){return "$k => $v";}, $disks_real, $disks_id)), "DEBUG");
  debug("\nunRAID Serials:\n+ ".implode("\n+ ", $unraid_serials), "DEBUG");
  debug("\nunRAID Disks:\n+ ".implode("\n+ ", $unraid_disks), "DEBUG");
  return $unassigned;
}


function is_mounted($dev)
{
  return (shell_exec("mount 2>&1|grep -c '${dev} '") == 0) ? FALSE : TRUE;
}


function get_all_disks_info($bus="all")
{
  $disks = benchmark( "get_unasigned_disks" );

  foreach ($disks as $key => $disk)
  {
    if ($disk['type'] != $bus && $bus != "all")
    {
      continue;
    }
    $disk = array_merge($disk, get_disk_info($key));
    $disks[$key] = $disk;
  }

  usort($disks, create_function('$a, $b','$key="device";if ($a[$key] == $b[$key]) return 0; return ($a[$key] < $b[$key]) ? -1 : 1;'));
  return $disks;
}


function get_info($device) {
  global $state_file;

  $parse_smart = function($smart, $property) 
  {
    $value = trim(split(":", array_values(preg_grep("#$property#", $smart))[0])[1]);
    return ($value) ? $value : "n/a";
  };

  $whitelist = array("ID_MODEL","ID_SCSI_SERIAL","ID_SERIAL_SHORT");
  $state = is_file($state_file) ? @parse_ini_file($state_file, true) : array();

  if (array_key_exists($device, $state) && ! $reload)
  {
    return $state[$device];
  }

  else
  {
    $disk =& $state[$device];
    $udev = parse_ini_string(shell_exec("udevadm info --query=property --path $(udevadm info -q path -n $device 2>/dev/null) 2>/dev/null"));
    $disk = array_intersect_key($udev, array_flip($whitelist));
    exec("smartctl -i -d sat,auto $device 2>/dev/null", $smartInfo);
    $disk['FAMILY']   = $parse_smart($smartInfo, "Model Family");
    $disk['MODEL']    = $parse_smart($smartInfo, "Device Model");

    if ($disk['FAMILY'] == "n/a" && $disk['MODEL'] == "n/a" )
    {
      $vendor         = $parse_smart($smartInfo, "Vendor");
      $product        = $parse_smart($smartInfo, "Product");
      $revision       = $parse_smart($smartInfo, "Revision");
      $disk['FAMILY'] = "{$vendor} {$product}";
      $disk['MODEL']  = "{$vendor} {$product} - Rev. {$revision}";
    }

    $disk['FIRMWARE'] = $parse_smart($smartInfo, "Firmware Version");
    $disk['SIZE']     = intval(trim(shell_exec("blockdev --getsize64 ${device} 2>/dev/null")));
    save_ini_file($state_file, $state);
    return $state[$device];
  }
}


function get_disk_info($device, $reload=FALSE)
{
  $disk = array();
  $attrs = benchmark("get_info", $device);
  $disk['serial_short'] = isset($attrs["ID_SCSI_SERIAL"]) ? $attrs["ID_SCSI_SERIAL"] : $attrs['ID_SERIAL_SHORT'];
  $disk['serial']       = "{$attrs[ID_MODEL]}_{$disk[serial_short]}";
  $disk['device']       = realpath($device);
  $disk['family']       = $attrs['FAMILY'];
  $disk['model']        = $attrs['MODEL'];
  $disk['firmware']     = $attrs['FIRMWARE'];
  $disk['size']         = sprintf("%s %s", my_scale($attrs['SIZE'] , $unit), $unit);
  $disk['temperature']  = benchmark("get_temp", $device);
  return $disk;
}


function is_disk_running($dev)
{
  global $plugin;
  $file      = "/var/state/{$plugin}/hdd_state.json";
  $stats     = is_file($file) ? json_decode(file_get_contents($file),TRUE) : array();
  $timestamp = isset($stats[$dev]['timestamp']) ? $stats[$dev]['timestamp'] : time();
  $running   = isset($stats[$dev]['running']) ? $stats[$dev]['running'] : NULL;

  if ( $running === NULL || (time() - $timestamp) > 300 )
  {
    $timestamp = time();
    $running   = trim(shell_exec("hdparm -C $dev 2>/dev/null| grep -c standby"));
  }

  $stats[$dev] = array('timestamp' => $timestamp,
                       'running'   => $running);

  file_put_contents($file, json_encode($stats));

  return ($running == 0) ? TRUE : FALSE;
}


function get_temp($dev)
{
  global $plugin;
  $tc        = "/var/state/{$plugin}/hdd_temp.json";
  $stats     = is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();
  $all_types = [ "-d scsi", "-d ata", "-d auto", "-d sat,auto", "-d sat,12", "-d usbjmicron", "-d usbjmicron,0", "-d usbjmicron,1" ]; 
  $all_types = array_merge($all_types, [ "-x -d usbjmicron,x,0", "-x -d usbjmicron,x,1", "-d usbsunplus", "-d usbcypress", "-d sat -T permissive" ]);
  $timestamp = isset($stats[$dev]['timestamp']) ? $stats[$dev]['timestamp'] : time();
  $smart     = isset($stats[$dev]['smart']) ? $stats[$dev]['smart'] : null;
  $temp      = isset($stats[$dev]['temp']) ? $stats[$dev]['temp'] : null;

  if ( ! $smart )
  {
    debug("SMART parameters for drive [{$dev}] not found, probing...", "DEBUG");
    $smart = "none";
    foreach ($all_types as $type)
    {
      $res = shell_exec("smartctl --attributes {$type} '{$dev}' 2>/dev/null| grep -c 'Temperature_Celsius'");
      if ( $res > 0 )
      {
        debug("SMART parameters for disk [{$dev}] ($smart) found.", "DEBUG");
        $smart = $type;
        break;
      }
    }
  }

  if ( (time() - $timestamp > 900) || ! $temp )
  {
    if ( $smart != "none" && is_disk_running($dev) )
    {
      debug("Temperature probing of disk '{$dev}'", "DEBUG");
      $temp = trim(shell_exec("smartctl -A {$type} $dev 2>/dev/null| grep -m 1 -i Temperature_Celsius | awk '{print $10}'"));
      $temp = (is_numeric($temp)) ? $temp : null; 
      $timestamp = time();
    }

    else
    {
      $temp = null;
    }
  }

  $stats[$dev] = array('timestamp' => $timestamp,
                       'temp'      => $temp,
                       'smart'     => $smart);

  file_put_contents($tc, json_encode($stats));

  return $temp ? $temp : "*";

}


function benchmark()
{
  $params   = func_get_args();
  $function = $params[0];
  array_shift($params);
  $time     = -microtime(true); 
  $out      = call_user_func_array($function, $params);
  $time    += microtime(true); 
  debug("benchmark: $function(".implode(",", $params).") took ".sprintf('%f', $time)."s.", "DEBUG");
  return $out;
}


$start_time = time();
switch ($_POST['action']) {
  case 'get_content':
    debug("Starting get_content: ".(time() - $start_time),'DEBUG');
    $disks = benchmark("get_all_disks_info");

    if ( count($disks) )
    {
      $odd="odd";
      
      foreach ($disks as $disk)
      {
        $disk_name = basename($disk['device']);
        $disk_icon = benchmark("is_disk_running", "${disk['device']}") ? "green-on.png" : "green-blink.png";
        $serial    = $disk['serial'];
        $temp      = my_temp($disk['temperature']);
        $mounted   = array_reduce($disk['partitions'], function ($found, $partition) { return $found || is_mounted(realpath($partition)); }, false);
        
        if (! is_file($script_file))
        {
          $status  = "Script not present";
        }
        
        else if ($Preclear->isRunning($disk_name))
        {
          $status  = $Preclear->Status($disk_name, $serial);
        }
        
        else
        {
          $status  = $mounted ? "Disk mounted" : $Preclear->Link($disk_name, "text");
        }

        $disks_o .= "<tr class='$odd'>
                      <td><img src='/webGui/images/$disk_icon'><a href='/Settings/New?name=$disk_name'> $disk_name</a></td>
                      <td><span class='toggle-hdd' hdd='{$disk_name}'><i class='glyphicon glyphicon-hdd hdd'></i><span style='margin:4px;'></span>{$serial}</td>
                      <td>{$temp}</td>
                      <td><span>${disk['size']}</span></td>
                      <td>{$status}</td>
                    </tr>";
        $odd = ($odd == "odd") ? "even" : "odd";
      }
    }

    else 
    {
      $disks_o .= "<tr><td colspan='12' style='text-align:center;font-weight:bold;'>No unassigned disks available.</td></tr>";
    }
    debug("get_content Finished: ".(time() - $start_time),'DEBUG');
    echo json_encode(array("disks" => $disks_o, "info" => json_encode($disks)));
    break;

  case 'get_status':
    $disk_name = urldecode($_POST['device']);
    $serial    = urldecode($_POST['serial']);
    $status    = $Preclear->Status($disk_name, $serial);
    echo json_encode(array("status" => $status));
    break;

  case 'start_preclear':
    $device  = urldecode($_POST['device']);
    $session = "preclear_disk_{$device}";
    $op      = (isset($_POST['op']) && $_POST['op'] != "0") ? urldecode($_POST['op']) : "";
    $scope   = $_POST['scope'];
    $script  = $script_files[$scope];

    @file_put_contents("/tmp/preclear_stat_{$device}","{$device}|NN|Starting...");

    if ($scope == "gfjardim")
    {
      $notify    = (isset($_POST['--notify']) && $_POST['--notify'] > 0) ? " --notify ".urldecode($_POST['--notify']) : "";
      $frequency = (isset($_POST['--frequency']) && $_POST['--frequency'] > 0 && intval($_POST['--notify']) > 0) ? " --frequency ".urldecode($_POST['--frequency']) : "";
      $cycles    = (isset($_POST['--cycles'])) ? " --cycles ".urldecode($_POST['--cycles']) : "";
      $read_sz   = (isset($_POST['--read-size']) && $_POST['--read-size'] != 0) ? " --read-size ".urldecode($_POST['--read-size']) : "";
      $pre_read  = (isset($_POST['--skip-preread']) && $_POST['--skip-preread'] == "on") ? " --skip-preread" : "";
      $post_read = (isset($_POST['--skip-postread']) && $_POST['--skip-postread'] == "on") ? " --skip-postread" : "";
      $noprompt  = " --no-prompt";

      if (!$op)
      {
        $cmd = "$script {$op}${notify}${frequency}{$cycles}{$read_sz}{$pre_read}{$post_read}{$noprompt} /dev/$device";
      }

      else
      {
        $cmd = "$script {$op}${notify}${frequency}{$read_sz} /dev/$device";
      }
      
    }

    else
    {
      $notify    = (isset($_POST['-o']) && $_POST['-o'] > 0) ? " -o ".urldecode($_POST['-o']) : "";
      $mail      = (isset($_POST['-M']) && $_POST['-M'] > 0 && intval($_POST['-o']) > 0) ? " -M ".urldecode($_POST['-M']) : "";
      $passes    = isset($_POST['-c']) ? " -c ".urldecode($_POST['-c']) : "";
      $read_sz   = (isset($_POST['-r']) && $_POST['-r'] != 0) ? " -r ".urldecode($_POST['-r']) : "";
      $write_sz  = (isset($_POST['-w']) && $_POST['-w'] != 0) ? " -w ".urldecode($_POST['-w']) : "";
      $pre_read  = (isset($_POST['-W']) && $_POST['-W'] == "on") ? " -W" : "";
      $post_read = (isset($_POST['-X']) && $_POST['-X'] == "on") ? " -X" : "";
      $fast_read = (isset($_POST['-f']) && $_POST['-f'] == "on") ? " -f" : "";
      $noprompt  = $noprompt ? " -J" : "";
      $confirm   = (! $op || $op == " -z" || $op == " -V") ? TRUE : FALSE;
      
      if ( $post_read && $pre_read )
      {
        $post_read = " -n";
        $pre_read = "";
      }
      
      if (! $op )
      {
        $cmd = "$script {$op}{$mail}{$notify}{$passes}{$read_sz}{$write_sz}{$pre_read}{$post_read}{$fast_read}{$noprompt} -s /dev/$device";
      }

      else if ( $op == "-V" )
      {
        $cmd = "$script {$op}{$fast_read}{$mail}{$notify}{$read_sz}{$write_sz}{$noprompt} -s /dev/$device";
      }

      else
      {
        $cmd = "$script {$op}{$noprompt} -s /dev/$device";
        @unlink("/tmp/preclear_stat_{$device}");
      }
    }

    tmux_kill_window( $session );
    tmux_new_session( $session );
    tmux_send_command($session, "$cmd 2>/tmp/preclear.error");

    if ( $confirm && ! $noprompt )
    {
      foreach( range(0, 30) as $x )
      {
        if ( strpos(tmux_get_session($session), "Answer Yes to continue") )
        {
          sleep(1);
          tmux_send_command($session, "Yes");
          break;
        }

        else
        {
          sleep(1);
        }
      }
    }
    break;


  case 'stop_preclear':
    $device = urldecode($_POST['device']);
    tmux_kill_window("preclear_disk_{$device}");
    @unlink("/tmp/preclear_stat_{$device}");
    reload_partition($device);
    echo "<script>parent.location=parent.location;</script>";
    break;


  case 'clear_preclear':
    $device = urldecode($_POST['device']);
    tmux_kill_window("preclear_disk_{$device}");
    @unlink("/tmp/preclear_stat_{$device}");
    echo "<script>parent.location=parent.location;</script>";
    break;


  case 'get_preclear':
    $device  = urldecode($_POST['device']);
    $content = tmux_get_session("preclear_disk_".$device);
    if (preg_match("%π%", $content)) {
      $output .= "<pre>".preg_replace("#\n{5,}#", "<br>", $content)."</pre>";
    } else {
      $output .= "<pre>".preg_replace("#\n{5,}#", "<br>", $content)."</pre>";
      $output .= "";
    }
    echo json_encode(array("content" => $output));
    break;
}


switch ($_GET['action']) {

  case 'show_preclear':
    $device = urldecode($_GET['device']);
    ?>
    <?if (is_file("webGui/scripts/dynamix.js")):?>
    <script type='text/javascript' src='/webGui/scripts/dynamix.js'></script>
    <?else:?>
    <script type='text/javascript' src='/webGui/javascript/dynamix.js'></script>
    <?endif;?>
    <script>
      var timers = {};
      var URL = "/plugins/<?=$plugin;?>/Preclear.php";
      var device = "<?=$device;?>";
      function get_preclear() {
        clearTimeout(timers.preclear);
        $.post(URL,{action:"get_preclear",device:device},function(data) {
          if (data.content) {
            $("#data_content").html(data.content);
          }
        },"json").always(function() {
          timers.preclear=setTimeout('get_preclear()',1000);
        });
      }
      $(function() {
        document.title='Preclear for disk /dev/<?=$device;?> ';
        get_preclear();
      });
    </script>
    <div id="data_content"></div>
    <?
    break;
}

?>