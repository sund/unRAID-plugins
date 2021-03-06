<?
set_error_handler("log_error");
set_exception_handler( "log_exception" );
$plugin = "preclear.disk";

require_once( "webGui/include/Helpers.php" );
require_once( "plugins/${plugin}/assets/lib.php" );

#########################################################
#############           VARIABLES          ##############
#########################################################

$Preclear     = new Preclear;
$script_files = $Preclear->scriptFiles();
// $VERBOSE        = TRUE;
// $TEST           = TRUE;

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

function log_error($errno, $errstr, $errfile, $errline)
{
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

function log_exception( $e )
{
  debug("PHP Exception: {$e->getMessage()} in {$e->getFile()} on line {$e->getLine()}");
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

$start_time = time();
switch ($_POST['action'])
{

  case 'get_content':
    debug("Starting get_content: ".(time() - $start_time),'DEBUG');
    // shell_exec("/etc/rc.d/rc.diskinfo --daemon &>/dev/null");
    $disks = Misc::get_json($diskinfo);
    $all_status = array();

    if ( count($disks) )
    {
      $odd="odd";
      foreach ($disks as $disk)
      {
        $disk_name = $disk['NAME'];
        $disk_icon = ($disk['RUNNING']) ? "green-on.png" : "green-blink.png";
        $serial    = $disk['SERIAL'];
        $temp      = $disk['TEMP'] ? my_temp($disk['TEMP']) : "*";
        $mounted   = $disk["MOUNTED"];
        $reports   = is_dir("/boot/preclear_reports") ? listDir("/boot/preclear_reports") : [];
        $reports   = array_filter($reports, function ($report) use ($disk)
                                  {
                                    return preg_match("|".$disk["SERIAL_SHORT"]."|", $report) && ( preg_match("|_report_|", $report) || preg_match("|_rpt_|", $report) ); 
                                  });

        if (count($reports))
        {
          $title  = "<span title='Click to view reports.' class='exec toggle-reports' style='margin-left:0px;' hdd='{$disk_name}'>
                      <i class='glyphicon glyphicon-hdd hdd'></i>
                      <i class='glyphicon glyphicon-plus-sign glyphicon-append'></i>
                      ${disk['SERIAL']}
                    </span>";
          
          $report_files = "<div class='toggle-${disk_name}' style='display:none;'>";

          foreach ($reports as $report)
          {
            $report_files .= "<div style='margin:4px 0px 4px 0px;'>
                                <i class='glyphicon glyphicon-list-alt hdd'></i>
                                <span style='margin:7px;'></span>
                                <a href='${report}'>".pathinfo($report, PATHINFO_FILENAME)."</a>
                                <a class='exec' title='Remove Report' style='color:#CC0000;font-weight:bold;' onclick='rmReport(\"{$report}\", this);'>
                                  &nbsp;<i class='glyphicon glyphicon-remove hdd'></i>
                                </a>
                              </div>";  
          }
          
          $report_files .= "</div>";
        }
        else
        {
          $report_files="";
          $title  = "<span class='toggle-reports' hdd='{$disk_name}' style='margin-left:0px;'><i class='glyphicon glyphicon-hdd hdd'></i><span style='margin:8px;'></span>{$serial}";
        }

        if ($Preclear->isRunning($disk_name))
        {
          $status  = $Preclear->Status($disk_name, $disk["SERIAL_SHORT"]);
          $footer = base64_encode("<span>${disk['SERIAL']} - ${disk['SIZE_H']} (${disk['NAME']})</span><br><span style='float:right;'>$status</span>");
          $footer = "<a class='tooltip-toggle-html exec' id='preclear_footer_${disk['SERIAL_SHORT']}' title=' ' data='${footer}'><img src='/plugins/preclear.disk/icons/precleardisk.png'></a>";
          $all_status[$disk['SERIAL_SHORT']]["footer"] = $footer;
          $all_status[$disk['SERIAL_SHORT']]["footer"] = "<span>${disk['SERIAL']} - ${disk['SIZE_H']} (${disk['NAME']})</span><br><span style='float:right;'>$status</span>";
          $all_status[$disk['SERIAL_SHORT']]["status"] = $status;
        }
        else
        {
          $status  = $mounted ? "Disk mounted" : $Preclear->Link($disk_name, "text");
        }
        
        $disks_o .= "<tr class='$odd'>
                      <td><img src='/webGui/images/${disk_icon}'><a href='/Tools/New?name=$disk_name'> $disk_name</a></td>
                      <td>${title}${report_files}</td>
                      <td>{$temp}</td>
                      <td><span>${disk['SIZE_H']}</span></td>
                      <td>{$status}</td>
                    </tr>";
        $disks_o .= $report_files;
        $odd = ($odd == "odd") ? "even" : "odd";
      }
    }

    else 
    {
      $disks_o .= "<tr><td colspan='12' style='text-align:center;font-weight:bold;'>No unassigned disks available.</td></tr>";
    }
    debug("get_content Finished: ".(time() - $start_time),'DEBUG');
    echo json_encode(array("disks" => $disks_o, "info" => json_encode($disks), "status" => $all_status));
    break;



  case 'get_status':
    $disk_name = urldecode($_POST['device']);
    $serial    = urldecode($_POST['serial']);
    $status    = $Preclear->Status($disk_name, $serial);
    echo json_encode(array("status" => $status));
    break;


  case 'start_preclear':
    $device  = urldecode($_POST['device']);
    $serial  = $Preclear->diskSerial($device);
    $session = "preclear_disk_{$serial}";
    $op      = (isset($_POST['op']) && $_POST['op'] != "0") ? urldecode($_POST['op']) : "";
    $scope   = $_POST['scope'];
    $script  = $script_files[$scope];
    $devname = basename($device);

    @file_put_contents("/tmp/preclear_stat_{$devname}","{$devname}|NN|Starting...");

    if ( $op == "resume" && is_file("/boot/config/plugins/$plugin/${serial}.resume"))
    {
      $cmd = "$script --load-file '/boot/config/plugins/$plugin/${serial}.resume' ${device}";
    }
    else if($op == "resume" && ! is_file("/boot/config/plugins/$plugin/${serial}.resume"))
    {
      break;
    }

    else if ($scope == "gfjardim")
    {
      $notify    = (isset($_POST['--notify']) && $_POST['--notify'] > 0) ? " --notify ".urldecode($_POST['--notify']) : "";
      $frequency = (isset($_POST['--frequency']) && $_POST['--frequency'] > 0 && intval($_POST['--notify']) > 0) ? " --frequency ".urldecode($_POST['--frequency']) : "";
      $cycles    = (isset($_POST['--cycles'])) ? " --cycles ".urldecode($_POST['--cycles']) : "";
      $pre_read  = (isset($_POST['--skip-preread']) && $_POST['--skip-preread'] == "on") ? " --skip-preread" : "";
      $post_read = (isset($_POST['--skip-postread']) && $_POST['--skip-postread'] == "on") ? " --skip-postread" : "";
      $test      = (isset($_POST['--test']) && $_POST['--test'] == "on") ? " --test" : "";
      $noprompt  = " --no-prompt";

      $cmd = "$script {$op}${notify}${frequency}{$cycles}{$pre_read}{$post_read}{$noprompt}{$test} $device";
      
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
      $confirm   = (! $op || $op == " -z" || $op == " -V") ? TRUE : FALSE;
      $test      = (isset($_POST['-s']) && $_POST['-s'] == "on") ? " -s" : "";

      $capable  = array_key_exists("joel", $script_files) ? $Preclear->scriptCapabilities($script_files["joel"]) : [];
      $noprompt = (array_key_exists("noprompt", $capable) && $capable["noprompt"]) ? " -J" : "";
      
      if ( $post_read && $pre_read )
      {
        $post_read = " -n";
        $pre_read = "";
      }
      
      if (! $op )
      {
        $cmd = "$script {$op}{$mail}{$notify}{$passes}{$read_sz}{$write_sz}{$pre_read}{$post_read}{$fast_read}{$noprompt}{$test} $device";
      }

      else if ( $op == "-V" )
      {
        $cmd = "$script {$op}{$fast_read}{$mail}{$notify}{$read_sz}{$write_sz}{$noprompt}{$test} $device";
      }

      else
      {
        $cmd = "$script {$op}{$noprompt} $device";
        @unlink("/tmp/preclear_stat_{$devname}");
      }
    }

    TMUX::killSession( $session );
    TMUX::NewSession( $session );
    TMUX::sendCommand($session, "$cmd");

    if ( $confirm && ! $noprompt )
    {
      foreach( range(0, 3) as $x )
      {
        if ( strpos(TMUX::getSession($session), "Answer Yes to continue") )
        {
          sleep(1);
          TMUX::sendCommand($session, "Yes");
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
    $serial = urldecode($_POST['serial']);
    $device = basename($Preclear->serialDisk($serial));
    TMUX::killSession("preclear_disk_{$serial}");
    @unlink("/tmp/preclear_stat_{$device}");
    reload_partition($serial);
    echo "<script>parent.location=parent.location;</script>";
    break;


  case 'clear_preclear':
    $serial = urldecode($_POST['serial']);
    $device = basename($Preclear->serialDisk($serial));
    TMUX::killSession("preclear_disk_{$serial}");
    @unlink("/tmp/preclear_stat_{$device}");
    echo "<script>parent.location=parent.location;</script>";
    break;


  case 'get_preclear':
    $serial  = urldecode($_POST['serial']);
    $session = "preclear_disk_{$serial}";
    if ( ! TMUX::hasSession($session))
    {
      $output = "<script>window.close();</script>";
    }
    $content = TMUX::getSession($session);
    $output .= "<pre>".preg_replace("#\n{5,}#", "<br>", $content)."</pre>";
    if ( strpos($content, "Answer Yes to continue") )
    {
      $output .= "<br><center><button onclick='hit_yes(\"{$serial}\")'>Answer Yes</button></center>";
    }
    echo json_encode(array("content" => $output));
    break;


  case 'hit_yes':
    $serial  = urldecode($_POST['serial']);
    $session = "preclear_disk_{$serial}";
    TMUX::sendCommand($session, "Yes");
    break;


  case 'remove_report':
    $file = $_POST['file'];
    if (! is_bool( strpos($file, "/boot/preclear_reports")))
    {
      unlink($file);
      echo "true";
    }
    break;

  case 'download':
    $dir  = "/preclear";
    $file = $_POST["file"];
    @mkdir($dir);
    exec("cat $log_file 2>/dev/null | todos >".escapeshellarg("$dir/preclear_disk_log.txt"));
    exec("cat /var/log/diskinfo.log 2>/dev/null | todos >".escapeshellarg("$dir/diskinfo_log.txt"));
    exec("cat /var/local/emhttp/plugins/diskinfo/diskinfo.json 2>/dev/null | todos >".escapeshellarg("$dir/diskinfo_json.txt"));
    exec("/etc/rc.d/rc.diskinfo --version  2>/dev/null | todos >".escapeshellarg("$dir/diskinfo_status.txt"));
    exec("/etc/rc.d/rc.diskinfo --status  2>/dev/null | todos >>".escapeshellarg("$dir/diskinfo_status.txt"));
    exec("zip -qmr ".escapeshellarg($file)." ".escapeshellarg($dir));
    echo "/$file";
  break;

  case 'get_resumable':
    $serial  = urldecode($_POST['serial']);
    if (is_file("/boot/config/plugins/$plugin/${serial}.resume"))
    {
      echo json_encode(["resume" => true]);
    }
    else
      {echo json_encode(["resume" => false]);}
    break;
}


switch ($_GET['action']) {

  case 'show_preclear':
    $serial = urldecode($_GET['serial']);
    ?>
    <html>
      <body>
        <table style="width: 100%;float: center;" >
          <tbody>
            <tr>
              <td style="width: auto;">&nbsp;</td>
              <td style="width: 968px;"><div id="data_content"></div></td>
              <td style="width: auto;">&nbsp;</td>
            </tr>
            <tr>
              <td></td>
              <td><div style="text-align: center;"><button class="btn" data-clipboard-target="#data_content">Copy to clipboard</button></div></td>
              <td></td>
            </tr>
          </tbody>
        </table>
        <?if (is_file("webGui/scripts/dynamix.js")):?>
        <script type='text/javascript' src='/webGui/scripts/dynamix.js'></script>
        <?else:?>
        <script type='text/javascript' src='/webGui/javascript/dynamix.js'></script>
        <?endif;?>
        <script src="/plugins/<?=$plugin;?>/assets/clipboard.min.js"></script>
        <script>
          var timers = {};
          var URL = "/plugins/<?=$plugin;?>/Preclear.php";
          var serial = "<?=$serial;?>";

          function get_preclear()
          {
            clearTimeout(timers.preclear);
            $.post(URL,{action:"get_preclear",serial:serial,csrf_token:"<?=$var['csrf_token'];?>"},function(data) {
              if (data.content)
              {
                $("#data_content").html(data.content);
              }
            },"json").always(function() {
              timers.preclear=setTimeout('get_preclear()',1000);
            });
          }
          function hit_yes(serial)
          {
            $.post(URL,{action:"hit_yes",serial:serial,csrf_token:"<?=$var['csrf_token'];?>"});
          }
          $(function() {
            document.title='Preclear for disk <?=$serial;?> ';
            get_preclear();
            new Clipboard('.btn');
          });
        </script>
      </body>
    </html>
    <?
    break;
}

?>