<?php
/*
 * @copyright   QiaoQiaoShiDai Internet Technology(Shanghai)Co.,Ltd
 * @license     https://www.oaooa.com/licenses/
 * 
 * @link        https://www.oaooa.com
 * @author      zyx(zyx@oaooa.com)
 */

define('CURSCRIPT', 'misc');
require __DIR__ . '/../core/coreBase.php';
require_once  __DIR__ . '/../core/class/class_Color.php';
@set_time_limit(0);
error_reporting(0);
$cachelist = array();
$dzz = dzz_app::instance();
global $_G;
$dzz->cachelist = $cachelist;
$dzz->init_cron = false;
$dzz->init_setting = true;
$dzz->init_user = false;
$dzz->init_session = false;
$dzz->init_misc = false;
$dzz->init();
$config = array(
    'dbcharset' => $_G['config']['db']['1']['dbcharset'],
    'charset' => $_G['config']['output']['charset'],
    'tablepre' => $_G['config']['db']['1']['tablepre']
);
$theurl = 'update.php';

$_G['siteurl'] = preg_replace('/\/install\/$/i', '/', $_G['siteurl']);


if ($_GET['from']) {
    if (md5($_GET['from'] . $_G['config']['security']['authkey']) != $_GET['frommd5']) {
        $refererarr = parse_url(dreferer());
        list($dbreturnurl, $dbreturnurlmd5) = explode("\t", authcode($_GET['from']));
        if (md5($dbreturnurl) == $dbreturnurlmd5) {
            $dbreturnurlarr = parse_url($dbreturnurl);

        } else {
            $dbreturnurlarr = parse_url($_GET['from']);
        }
        parse_str($dbreturnurlarr['query'], $dbreturnurlparamarr);
        $operation = $dbreturnurlparamarr['operation'];
        $version = $dbreturnurlparamarr['version'];
        $release = $dbreturnurlparamarr['release'];
        if (!$operation || !$version || !$release) {
            show_msg('请求的参数不正确');
        }
        $time = $_G['timestamp'];
        dheader('Location: ' . $_G['siteurl'] . basename($refererarr['path']) . '?action=upgrade&operation=' . $operation . '&version=' . $version . '&release=' . $release . '&ungetfrom=' . $time . '&ungetfrommd5=' . md5($time . $_G['config']['security']['authkey']));
    }
}
$lockfile = DZZ_ROOT . './data/update.lock';
if (file_exists($lockfile) && !$_GET['from']) {
    show_msg('请您先登录服务器ftp，手工删除 ./data/update.lock 文件，再次运行本文件进行升级。');
}

$sqlfile = DZZ_ROOT . './install/data/install.sql';

if (!file_exists($sqlfile)) {
    show_msg('SQL文件 ' . $sqlfile . ' 不存在');
}

if ($_POST['delsubmit']) {
    if (!empty($_POST['deltables'])) {
        foreach ($_POST['deltables'] as $tname => $value) {
            DB::query("DROP TABLE `" . DB::table($tname) . "`");
        }
    }
    if (!empty($_POST['delcols'])) {
        foreach ($_POST['delcols'] as $tname => $cols) {
            foreach ($cols as $col => $indexs) {
                if ($col == 'PRIMARY') {
                    DB::query("ALTER TABLE " . DB::table($tname) . " DROP PRIMARY KEY", 'SILENT');
                } elseif ($col == 'KEY' || $col == 'UNIQUE') {
                    foreach ($indexs as $index => $value) {
                        DB::query("ALTER TABLE " . DB::table($tname) . " DROP INDEX `$index`", 'SILENT');
                    }
                } else {
                    DB::query("ALTER TABLE " . DB::table($tname) . " DROP `$col`");
                }
            }
        }
    }
    show_msg('删除表和字段操作完成了', $theurl . '?step=cache');
}

function waitingdb($curstep, $sqlarray)
{
    global $theurl;
    foreach ($sqlarray as $key => $sql) {
        $sqlurl .= '&sql[]=' . md5($sql);
        $sendsql .= '<img width="1" height="1" src="' . $theurl . '?step=' . $curstep . '&waitingdb=1&sqlid=' . $key . '">';
    }
    show_msg("优化数据表", $theurl . '?step=waitingdb&nextstep=' . $curstep . $sqlurl . '&sendsql=' . base64_encode($sendsql), 5000, 1);
}

function q_runquery($sql)
{
    global $_G;
    $tablepre = $_G['config']['db'][1]['tablepre'];
    $dbcharset = $_G['config']['db'][1]['dbcharset'];

    $sql = str_replace(array(' dzz_', ' `dzz_', ' cdb_', ' `cdb_'), array(' {tablepre}', ' `{tablepre}', ' {tablepre}', ' `{tablepre}'), $sql);

    $sql = str_replace("\r", "\n", str_replace(array(' {tablepre}', ' `{tablepre}'), array(' ' . $tablepre, ' `' . $tablepre), $sql));

    $ret = array();
    $num = 0;
    foreach (explode(";\n", trim($sql)) as $query) {
        $queries = explode("\n", trim($query));
        foreach ($queries as $query) {
            $ret[$num] .= $query[0] == '#' || $query[0] . $query[1] == '--' ? '' : $query;
        }
        $num++;
    }
    unset($sql);
    foreach ($ret as $query) {
        $query = trim($query);
        if ($query) {

            if (substr($query, 0, 12) == 'CREATE TABLE') {
                $name = preg_replace("/CREATE TABLE ([a-z0-9_]+) .*/is", "\\1", $query);
                DB::query(q_createtable($query, $dbcharset));

            } else {
                DB::query($query);
            }

        }
    }
}

function q_createtable($sql, $dbcharset)
{
    $type = strtoupper(preg_replace("/^\s*CREATE TABLE\s+.+\s+\(.+?\).*(ENGINE|TYPE)\s*=\s*([a-z]+?).*$/isU", "\\2", $sql));
    $type = in_array($type, array('MYISAM', 'HEAP')) ? $type : 'MYISAM';
    return preg_replace("/^\s*(CREATE TABLE\s+.+\s+\(.+?\)).*$/isU", "\\1", $sql) .
        (" ENGINE=$type DEFAULT CHARSET=$dbcharset");
}

if (empty($_GET['step'])) $_GET['step'] = 'start';

if ($_GET['step'] == 'start') {

    if (!C::t('setting')->fetch('bbclosed')) {
        C::t('setting')->update('bbclosed', 1);
        require_once libfile('function/cache');
        updatecache('setting');
        show_msg('您的站点未关闭，正在关闭，请稍后...', $theurl . '?step=start', 5000);
    }
    show_msg('说明：<br>本升级程序会参照最新的SQL文件，对数据库进行同步升级。<br>
			请确保当前目录下 ./data/install.sql 文件为最新版本。<br><br>
			<a href="' . $theurl . '?step=prepare' . ($_GET['from'] ? '&from=' . rawurlencode($_GET['from']) . '&frommd5=' . rawurlencode($_GET['frommd5']) : '') . '">准备完毕，升级开始</a>');

}
elseif ($_GET['step'] == 'waitingdb') {
    $query = DB::fetch_all("SHOW FULL PROCESSLIST");
    foreach ($query as $row) {
        if (in_array(md5($row['Info']), $_GET['sql'])) {
            $list .= '[时长]:' . $row['Time'] . '秒 [状态]:<b>' . $row['State'] . '</b>[信息]:' . $row['Info'] . '<br><br>';
        }
    }
    if (empty($list) && empty($_GET['sendsql'])) {
        $msg = '准备进入下一步操作，请稍后...';
        $notice = '';
        $url = "?step=$_GET[nextstep]";
        $time = 5;
    } else {
        $msg = '正在升级数据，请稍后...';
        $notice = '<br><br><b>以下是正在执行的数据库升级语句:</b><br>' . $list . base64_decode($_GET['sendsql']);
        $sqlurl = implode('&sql[]=', $_GET['sql']);
        $url = "?step=waitingdb&nextstep=$_GET[nextstep]&sql[]=" . $sqlurl;
        $time = 20;
    }
    show_msg($msg, $theurl . $url, $time * 1000, 0, $notice);
}
elseif ($_GET['step'] == 'prepare') {
    $repeat = array();
    //处理pichome_route表
    if(DB::result_first("SHOW COLUMNS FROM  ".$config['tablepre']."pichome_route LIKE 'id'",array(),true)) {
        $query = DB::query("RENAME TABLE  " . $config['tablepre'] . 'pichome_route' . " TO " . $config['tablepre'] . 'pichome_route1');
        $query = DB::query("ALTER TABLE  " . $config['tablepre'] . 'pichome_route1' . "  ADD COLUMN sid char(6) NOT NULL  DEFAULT '1'");
        $query = DB::query("update  " . $config['tablepre'] . 'pichome_route1' . " set sid = path where 1");
        $query = DB::query("ALTER TABLE  " . $config['tablepre'] . 'pichome_route1' . "  DROP COLUMN id");
        $query = DB::query("ALTER TABLE  " . $config['tablepre'] . 'pichome_route1' . " ADD PRIMARY KEY ( `sid` )");
        $query = DB::query("RENAME TABLE  " . $config['tablepre'] . 'pichome_route1' . " TO " . $config['tablepre'] . 'pichome_route');
    }
    show_msg('准备完毕，进入下一步数据库结构升级', $theurl . '?step=sql');
} elseif ($_GET['step'] == 'sql') {
    $sql = implode('', file($sqlfile));
    preg_match_all("/CREATE\s+TABLE.+?(dzz|pichome|fp)\_(.+?)\s*\((.+?)\)\s*(ENGINE|TYPE)\s*=\s*(\w+)/is", $sql, $matches);
    $newtables = empty($matches[2]) ? array() : str_replace('`', '', $matches[2]);

    $newsqls = empty($matches[0]) ? array() : $matches[0];
    if (empty($newtables) || empty($newsqls)) {
        show_msg('SQL文件内容为空，请确认');
    }

    $i = empty($_GET['i']) ? 0 : intval($_GET['i']);
    $count_i = count($newtables);
    if ($i >= $count_i) {
        show_msg('数据库结构升级完毕，进入下一步数据升级操作', $theurl . '?step=data');
    }
    $newtable = $newtables[$i];

    $specid = intval($_GET['specid']);

    $newcols = getcolumn($newsqls[$i]);
    $query=DB::result_first(" SHOW TABLES LIKE '" . DB::table($newtable)."'");
    if (!$query) {
        preg_match("/(CREATE TABLE .+?)\s*(ENGINE|TYPE)\s*=\s*(\w+)/s", $newsqls[$i], $maths);
        $maths[3] = strtoupper($maths[3]);
        if ($maths[3] == 'MEMORY' || $maths[3] == 'HEAP') {
            $type = " ENGINE=MEMORY" . (empty($config['dbcharset']) ? '' : " DEFAULT CHARSET=$config[dbcharset]");
        } else {
            $type = " ENGINE=MYISAM" . (empty($config['dbcharset']) ? '' : " DEFAULT CHARSET=$config[dbcharset]");
        }
        $usql = $maths[1] . $type;
        $usql = str_replace("CREATE TABLE IF NOT EXISTS dzz_", 'CREATE TABLE IF NOT EXISTS ' . $config['tablepre'], $usql);
        $usql = str_replace("CREATE TABLE IF NOT EXISTS pichome_", 'CREATE TABLE IF NOT EXISTS ' . $config['tablepre'], $usql);
        $usql = str_replace("CREATE TABLE IF NOT EXISTS `dzz_", 'CREATE TABLE IF NOT EXISTS `' . $config['tablepre'], $usql);
        $usql = str_replace("CREATE TABLE IF NOT EXISTS `pichome_", 'CREATE TABLE IF NOT EXISTS `' . $config['tablepre'], $usql);
        $usql = str_replace("CREATE TABLE dzz_", 'CREATE TABLE ' . $config['tablepre'], $usql);
        $usql = str_replace("CREATE TABLE pichome_", 'CREATE TABLE ' . $config['tablepre'], $usql);
        $usql = str_replace("CREATE TABLE `dzz_", 'CREATE TABLE `' . $config['tablepre'], $usql);
        $usql = str_replace("CREATE TABLE `pichome_", 'CREATE TABLE `' . $config['tablepre'], $usql);
        if (!DB::query($usql, 'SILENT')) {
            show_msg('添加表 ' . DB::table($newtable) . ' 出错,请手工执行以下SQL语句后,再重新运行本升级程序:<br><br>' . dhtmlspecialchars($usql));
        } else {
            $msg = '添加表 ' . DB::table($newtable) . ' 完成';
        }
    } else {

        $query = DB::query("SHOW CREATE TABLE " . DB::table($newtable), 'SILENT');
        $value = DB::fetch($query);
        $oldcols = getcolumn($value['Create Table']);
        $tablepre = $_G['config']['db'][1]['tablepre'];
        $tablist = fetchtablelist($tablepre);
        $updates = array();
        $allfileds = array_keys($newcols);
        foreach ($newcols as $key => $value) {
            if ($key == 'PRIMARY') {
                if ($value != $oldcols[$key]) {
                    if (!empty($oldcols[$key])) {
                        $baktab =DB::table($newtable . '_bak');
                        if(!in_array($baktab,$tablist)){
                            $usql = "RENAME TABLE " . DB::table($newtable) . " TO " . DB::table($newtable . '_bak');
                            if (!DB::query($usql, 'SILENT')) {
                                show_msg('升级表 ' . DB::table($newtable) . ' 出错,请手工执行以下升级语句后,再重新运行本升级程序:<br><br><b>升级SQL语句</b>:<div style=\"position:absolute;font-size:11px;font-family:verdana,arial;background:#EBEBEB;padding:0.5em;\">' . dhtmlspecialchars($usql) . "</div><br><b>Error</b>: " . DB::error() . "<br><b>Errno.</b>: " . DB::errno());
                            } else {
                                $msg = '表改名 ' . DB::table($newtable) . ' 完成！';
                                show_msg($msg, $theurl . '?step=sql&i=' . $_GET['i']);
                            }
                            $updates[] = "ADD PRIMARY KEY $value";
                        }

                    }

                }
            } elseif ($key == 'KEY') {
                foreach ($value as $subkey => $subvalue) {
                    if (!empty($oldcols['KEY'][$subkey])) {
                        if ($subvalue != $oldcols['KEY'][$subkey]) {
                            $updates[] = "DROP INDEX `$subkey`";
                            $updates[] = "ADD INDEX `$subkey` $subvalue";
                        }
                    } else {
                        $updates[] = "ADD INDEX `$subkey` $subvalue";
                    }
                }
            } elseif ($key == 'UNIQUE') {
                foreach ($value as $subkey => $subvalue) {
                    if (!empty($oldcols['UNIQUE'][$subkey])) {
                        if ($subvalue != $oldcols['UNIQUE'][$subkey]) {
                            $updates[] = "DROP INDEX `$subkey`";
                            $updates[] = "ADD UNIQUE INDEX `$subkey` $subvalue";
                        }
                    } else {
                        $usql = "ALTER TABLE  " . DB::table($newtable) . " DROP INDEX `$subkey`";
                        try{
                            DB::query($usql, 'SILENT');
                        }catch (\Exception $e){

                        }
                        $updates[] = "ADD UNIQUE INDEX `$subkey` $subvalue";
                    }
                }
            } else {
                if (!empty($oldcols[$key])) {
                    if (strtolower($value) != strtolower($oldcols[$key])) {
                        $updates[] = "CHANGE `$key` `$key` $value";
                    }
                } else {
                    $i = array_search($key, $allfileds);
                    $fieldposition = $i > 0 ? 'AFTER `' . $allfileds[$i - 1] . '`' : 'FIRST';
                    $updates[] = "ADD `$key` $value $fieldposition";
                }
            }
        }

        if (!empty($updates)) {
            $usql = "ALTER TABLE " . DB::table($newtable) . " " . implode(', ', $updates);
            try{
                if (!DB::query($usql, 'SILENT')) {
                    show_msg('升级表 ' . DB::table($newtable) . ' 出错,请手工执行以下升级语句后,再重新运行本升级程序:<br><br><b>升级SQL语句</b>:<div style=\"position:absolute;font-size:11px;font-family:verdana,arial;background:#EBEBEB;padding:0.5em;\">' . dhtmlspecialchars($usql) . "</div><br><b>Error</b>: " . DB::error() . "<br><b>Errno.</b>: " . DB::errno());
                } else {
                    $msg = '升级表 ' . DB::table($newtable) . ' 完成！';
                }
            }catch (\Exception $e){
                show_msg('升级表 ' . DB::table($newtable) . ' 出错,请手工执行以下升级语句后,再重新运行本升级程序:<br><br><b>升级SQL语句</b>:<div style=\"position:absolute;font-size:11px;font-family:verdana,arial;background:#EBEBEB;padding:0.5em;\">' . dhtmlspecialchars($usql) . "</div><br><b>Error</b>: " .$e->getMessage() . "<br><b>Errno.</b>: " .$e->getCode());
            }
        } else {
            $msg = '检查表 ' . DB::table($newtable) . ' 完成，不需升级，跳过';
        }
    }

    if ($specid) {
        $newtable = $spectable;
    }

    if (get_special_table_by_num($newtable, $specid + 1)) {
        $next = $theurl . '?step=sql&i=' . ($_GET['i']) . '&specid=' . ($specid + 1);
    } else {
        $next = $theurl . '?step=sql&i=' . ($_GET['i'] + 1);
    }
    show_msg("[ $i / $count_i ] " . $msg, $next);

} elseif ($_GET['step'] == 'data') {
    //如果没有识别码，增加识别码
    if (!$_GET['dp']) {
        //appmarket语言包应用
        if(!DB::result_first("select appid from %t where identifier = %s",array('app_market','lang'),true)){
            DB::insert('app_market',
                array(
                    'mid'=>0,
                    'appname'=>'多语言',
                    'appico'=>'appico/201712/21/lang.png',
                    'appdesc'=>'',
                    'appurl'=>'{dzzscript}?mod=lang',
                    'appadminurl'=>'{dzzscript}?mod=lang&op=admin',
                    'noticeurl'=>'',
                    'dateline'=>0,
                    'disp'=>0,
                    'vendor'=>'',
                    'haveflash'=>0,
                    'isshow'=>0,
                    'havetask'=>0,
                    'hideInMarket'=>1,
                    'feature'=>'',
                    'fileext'=>'',
                    'group'=>0,
                    'orgid'=>0,
                    'position'=>0,
                    'system'=>0,
                    'identifier'=>'lang',
                    'app_path'=> 'dzz',
                    'available' =>1,
                    'version'=>'1.0',
                    'upgrade_version'=>'',
                    'check_upgrade_time'=> 0,
                    'extra'=>'a:1:{s:11:\"installfile\";s:11:\"install.php\";}',
                    'uids'=> '',
                    'showadmin'=>1
                )
            );
        }


        //增加分享应用
        if(!DB::result_first("select appid from %t where identifier = %s",array('app_market','shares'),true)){
            $appid=DB::insert('app_market', array(
                'mid'=>0,
                'appname'=>'分享',
                'appico'=>'appico/201712/21/share.png',
                'appdesc'=>'',
                'appurl'=>'{dzzscript}?mod=shares',
                'appadminurl'=>'{dzzscript}?mod=shares&op=admin',
                'noticeurl'=>'',
                'dateline'=>0,
                'disp'=>0,
                'vendor'=>'',
                'haveflash'=>0,
                'isshow'=>0,
                'havetask'=>0,
                'hideInMarket'=>1,
                'feature'=>'',
                'fileext'=>'',
                'group'=>0,
                'orgid'=>0,
                'position'=>0,
                'system'=>0,
                'identifier'=>'shares',
                'app_path'=> 'dzz',
                'available' =>1,
                'version'=>'1.0',
                'upgrade_version'=>'',
                'check_upgrade_time'=> 0,
                'extra'=>'N;',
                'uids'=> '',
                'showadmin'=>0),1, false, true);
            if($appid){ //增加hooks

                if ($hid=DB::result_first("select id from %t where addons = %s", array('hooks', 'dzz\shares\classes\mynavigation'))) {
                    DB::update('hooks', array('app_market_id' => $appid), array('id' => $hid));
                }else{
                    DB::insert('hooks', array(
                        'app_market_id' => $appid,
                        'name' => 'getMyNavigation',
                        'description' => 'getMyNavigation',
                        'type' => 1,
                        'update_time' => 0,
                        'addons' => 'dzz\shares\classes\mynavigation',
                        'status' => 1,
                        'priority' => 0
                    ), false, true,true);
                }

            }
        }
        //增加阿里云以图搜图应用
        if(!DB::result_first("select appid from %t where identifier = %s",array('app_market','imageSearchAli'),true)){
            $appid=DB::insert('app_market', array(
                'mid'=>0,
                'appname'=>'阿里云以图搜图',
                'appico'=>'appico/201712/21/aliimagesearch.png',
                'appdesc'=>'',
                'appurl'=>'{dzzscript}?mod=imageSearchAli',
                'appadminurl'=>'{dzzscript}?mod=imageSearchAli',
                'noticeurl'=>'',
                'dateline'=>0,
                'disp'=>0,
                'vendor'=>'',
                'haveflash'=>0,
                'isshow'=>0,
                'havetask'=>0,
                'hideInMarket'=>1,
                'feature'=>'',
                'fileext'=>'',
                'group'=>0,
                'orgid'=>0,
                'position'=>0,
                'system'=>0,
                'identifier'=>'imageSearchAli',
                'app_path'=> 'dzz',
                'available' =>1,
                'version'=>'1.0',
                'upgrade_version'=>'',
                'check_upgrade_time'=> 0,
                'extra'=>'N;',
                'uids'=> '',
                'showadmin'=>1),1,false, true);
            if($appid){ //增加hooks
                //处理相关挂载点

                if ($hid=DB::result_first("select id from %t where addons = %s", array('hooks', 'dzz\imageSearchAli\classes\imageSearchadd'),true)) {
                    DB::update('hooks', array('app_market_id' => $appid), array('id' => $hid));
                }else{
                    DB::insert('hooks', array(
                        'app_market_id' => $appid,
                        'name' => 'addfileafter',
                        'description' => '',
                        'type' => 1,
                        'update_time' => 0,
                        'addons' => 'dzz\imageSearchAli\classes\imageSearchadd',
                        'status' => 1,
                        'priority' => 0
                    ), false, true,true);
                }
                if ($hid=DB::result_first("select id from %t where addons = %s", array('hooks', 'dzz\imageSearchAli\classes\imageSearchdel'),true)) {
                    DB::update('hooks', array('app_market_id' => $appid), array('id' => $hid));
                }else{
                    DB::insert('hooks', array(
                        'app_market_id' => $appid,
                        'name' => 'pichomedatadeleteafter',
                        'description' => '',
                        'type' => 1,
                        'update_time' => 0,
                        'addons' => 'dzz\imageSearchAli\classes\imageSearchdel',
                        'status' => 1,
                        'priority' => 0
                    ), false, true,true);
                }
                if ($hid=DB::result_first("select id from %t where addons = %s", array('hooks', 'dzz\imageSearchAli\classes\imageSearch'),true)) {
                    DB::update('hooks', array('app_market_id' => $appid), array('id' => $hid));
                }else{
                    DB::insert('hooks', array(
                        'app_market_id' => $appid,
                        'name' => 'search_condition_filter',
                        'description' => '',
                        'type' => 1,
                        'update_time' => 0,
                        'addons' => 'dzz\imageSearchAli\classes\imageSearch',
                        'status' => 1,
                        'priority' => 0
                    ), false, true,true);
                }
                if ($hid=DB::result_first("select id from %t where addons = %s", array('hooks', 'dzz\imageSearchAli\classes\imageSearchupdate'),true)) {
                    DB::update('hooks', array('app_market_id' => $appid), array('id' => $hid));
                }else{
                    DB::insert('hooks', array(
                        'app_market_id' => $appid,
                        'name' => 'updatedataafter',
                        'description' => '',
                        'type' => 1,
                        'update_time' => 0,
                        'addons' => 'dzz\imageSearchAli\classes\imageSearchupdate',
                        'status' => 1,
                        'priority' => 0
                    ), false, true,true);
                }
                if ($hid=DB::result_first("select id from %t where addons = %s", array('hooks', 'dzz\imageSearchAli\classes\allowSearch'),true)) {
                    DB::update('hooks', array('app_market_id' => $appid), array('id' => $hid));
                }else{
                    DB::insert('hooks', array(
                        'app_market_id' => $appid,
                        'name' => 'ResourceDataFilter',
                        'description' => '',
                        'type' => 1,
                        'update_time' => 0,
                        'addons' => 'dzz\imageSearchAli\classes\allowSearch',
                        'status' => 1,
                        'priority' => 0
                    ), false, true,true);
                }
                if ($hid=DB::result_first("select cronid from %t where addons = %s", array('hooks', 'dzz\imageSearchAli\classes\imageSearchTask'),true)) {
                    DB::update('hooks', array('app_market_id' => $appid), array('cronid' => $hid));
                }else{
                    DB::insert('hooks', array(
                        'app_market_id' => $appid,
                        'name' => 'imageSearchTask',
                        'description' => '',
                        'type' => 1,
                        'update_time' => 0,
                        'addons' => 'dzz\imageSearchAli\classes\imageSearchTask',
                        'status' => 1,
                        'priority' => 0
                    ), false, true,true);
                }
                //处理计划任务
                if ($cronid=DB::result_first("select cronid from %t where filename = %s", array('cron', 'dzz:imageSearchAli:cron_imageSearch_add.php'),true)) {

                }else{
                    DB::insert('cron', array(
                        'available' => 1,
                        'name'=>'以图搜图入库',
                        'filename' => 'dzz:imageSearchAli:cron_imageSearch_add.php',
                        'type' => 'app',
                        'weekday' => '-1',
                        'day' => '-1',
                        'hour' => '-1',
                        'minute'=>'0	5   10	15	20	25	30	35	40	45	50	55',
                    ), false, true,true);
                }
                if ($cronid=DB::result_first("select cronid from %t where filename = %s", array('cron', 'dzz:imageSearchAli:cron_imageSearch_md5chk.php'),true)) {


                }else{
                    DB::insert('cron', array(
                        'available' => 1,
                        'name'=>'以图搜图Md5校验',
                        'filename' => 'dzz:imageSearchAli:cron_imageSearch_md5chk.php',
                        'type' => 'app',
                        'weekday' => '-1',
                        'day' => '-1',
                        'hour' => '-1',
                        'minute'=>'0	5   10	15	20	25	30	35	40	45	50	55',
                    ), false, true,true);
                }
                if ($cronid=DB::result_first("select cronid from %t where filename = %s", array('cron', 'dzz:imageSearchAli:cron_imageSearch_prepare.php'),true)) {

                }else{
                    DB::insert('cron', array(
                        'available' => 1,
                        'name'=>'以图搜图入表',
                        'filename' => 'dzz:imageSearchAli:cron_imageSearch_prepare.php',
                        'type' => 'app',
                        'weekday' => '-1',
                        'day' => '-1',
                        'hour' => '-1',
                        'minute'=>'0	5   10	15	20	25	30	35	40	45	50	55',
                    ), false, true,true);
                }
            }
        }

        //处理阿里百炼应用
        if(!DB::result_first("select appid from %t where identifier = %s",array('app_market','aliBaiLian'),true)) {
            $appid = DB::insert('app_market', array(
                'mid' => 0,
                'appname' => '阿里百炼',
                'appico' => 'appico/201712/21/alibainian.png',
                'appdesc' => '',
                'appurl' => '{dzzscript}?mod=aliBaiLian',
                'appadminurl' => '{dzzscript}?mod=aliBaiLian&op=setting',
                'noticeurl' => '',
                'dateline' => 0,
                'disp' => 0,
                'vendor' => '',
                'haveflash' => 0,
                'isshow' => 0,
                'havetask' => 0,
                'hideInMarket' => 1,
                'feature' => '',
                'fileext' => '',
                'group' => 0,
                'orgid' => 0,
                'position' => 0,
                'system' => 0,
                'identifier' => 'aliBaiLian',
                'app_path' => 'dzz',
                'available' => 1,
                'version' => '1.0',
                'upgrade_version' => '',
                'check_upgrade_time' => 0,
                'extra' => 'N;',
                'uids' => '',
                'showadmin' => 1), 1,false, true);
            if ($appid) { //增加hooks
                //处理相关挂载点
                if ($hid = DB::result_first("select id from %t where addons = %s", array('hooks', 'dzz\aliBaiLian\classes\pichomedatadeleteafter'),true)) {
                    DB::update('hooks', array('app_market_id' => $appid), array('id' => $hid));
                } else {
                    DB::insert('hooks', array(
                        'app_market_id' => $appid,
                        'name' => 'pichomedatadeleteafter',
                        'description' => 'pichomedatadeleteafter',
                        'type' => 1,
                        'update_time' => 0,
                        'addons' => 'dzz\aliBaiLian\classes\pichomedatadeleteafter',
                        'status' => 1,
                        'priority' => 100
                    ), false, true,true);
                }
                if ($hid = DB::result_first("select id from %t where addons = %s", array('hooks', 'dzz\aliBaiLian\classes\attachmentDelAfter'),true)) {
                    DB::update('hooks', array('app_market_id' => $appid), array('id' => $hid));
                } else {
                    DB::insert('hooks', array(
                        'app_market_id' => $appid,
                        'name' => 'finalydelete',
                        'description' => 'finalydelete',
                        'type' => 1,
                        'update_time' => 0,
                        'addons' => 'dzz\aliBaiLian\classes\attachmentDelAfter',
                        'status' => 1,
                        'priority' => 100
                    ), false, true,true);
                }
                if ($hid = DB::result_first("select id from %t where addons = %s", array('hooks', 'dzz\aliBaiLian\classes\ImagetagAnddesc'),true)) {
                    DB::update('hooks', array('app_market_id' => $appid), array('id' => $hid));
                } else {
                    DB::insert('hooks', array(
                        'app_market_id' => $appid,
                        'name' => 'imageAiData',
                        'description' => 'ImagetagAnddesc',
                        'type' => 1,
                        'update_time' => 0,
                        'addons' => 'dzz\aliBaiLian\classes\ImagetagAnddesc',
                        'status' => 1,
                        'priority' => 100
                    ), false, true,true);
                }
                if ($hid = DB::result_first("select id from %t where addons = %s", array('hooks', 'dzz\aliBaiLian\classes\ImageAIkey'),true)) {
                    DB::update('hooks', array('app_market_id' => $appid), array('id' => $hid));
                } else {
                    DB::insert('hooks', array(
                        'app_market_id' => $appid,
                        'name' => 'editfilefilter',
                        'description' => 'ImageAIkey',
                        'type' => 1,
                        'update_time' => 0,
                        'addons' => 'dzz\aliBaiLian\classes\ImageAIkey',
                        'status' => 1,
                        'priority' => 100
                    ), false, true,true);
                }

            }
        }
        //markdown应用
        if(!DB::result_first("select appid from %t where identifier = %s",array('app_market','markdown'),true)){
            $appid=DB::insert('app_market',
                array(
                    'mid'=>0,
                    'appname'=>'Markdown',
                    'appico'=>'appico/201712/21/markdown.png',
                    'appdesc'=>'',
                    'appurl'=>'{dzzscript}?mod=markdown',
                    'appadminurl'=>'',
                    'noticeurl'=>'',
                    'dateline'=>0,
                    'disp'=>0,
                    'vendor'=>'',
                    'haveflash'=>0,
                    'isshow'=>0,
                    'havetask'=>0,
                    'hideInMarket'=>1,
                    'feature'=>'',
                    'fileext'=>'md',
                    'group'=>0,
                    'orgid'=>0,
                    'position'=>0,
                    'system'=>0,
                    'identifier'=>'markdown',
                    'app_path'=> 'dzz',
                    'available' =>1,
                    'version'=>'1.0',
                    'upgrade_version'=>'',
                    'check_upgrade_time'=> 0,
                    'extra'=>'a:1:{s:11:\"installfile\";s:11:\"install.php\";}',
                    'uids'=> '',
                    'showadmin'=>1
                ),1,false,true);
            if($appid) { //增加hooks
                //处理后缀关联
                if(!DB::result_first("select count(*) from %t where ext='md'",array('app_open'))){
                    DB::insert('app_open',array(
                        'ext'=>'md',
                        'appid'=>$appid,
                        'default'=>1
                    ),1,1);
                }
                //处理相关挂载点
                if ($hid = DB::result_first("select id from %t where addons = %s", array('hooks', 'dzz\markdown\classes\mdtohtml'),true)) {
                    DB::update('hooks', array('app_market_id' => $appid), array('id' => $hid));
                } else {
                    DB::insert('hooks', array(
                        'app_market_id' => $appid,
                        'name' => 'mdtohtml',
                        'description' => 'mdtohtml',
                        'type' => 1,
                        'update_time' => 0,
                        'addons' => 'dzz\markdown\classes\mdtohtml',
                        'status' => 1,
                        'priority' => 1
                    ),false,true,true);
                }
            }
        }
        //处理发布相关hooks

        if (!DB::result_first("select count(*) from %t where addons = %s", array('hooks', 'dzz\publish\classes\deleteafter'),true)) {
            DB::insert('hooks', array(
                'app_market_id' =>0,
                'name' => 'pichomedatadeleteafter',
                'description' => '删除文件时处理发布数据',
                'type' => 1,
                'update_time' => 0,
                'addons' => 'dzz\publish\classes\deleteafter',
                'status' => 1,
                'priority' => 0
            ), false, true,true);
        }
        if (!DB::result_first("select count(*) from %t where addons = %s", array('hooks', 'dzz\publish\classes\intelligentdeleteafter'),true)) {
            DB::insert('hooks', array(
                'app_market_id' =>0,
                'name' => 'intelligentdeleteafter',
                'description' => '删除智能数据时处理发布数据',
                'type' => 1,
                'update_time' => 0,
                'addons' => 'dzz\publish\classes\intelligentdeleteafter',
                'status' => 1,
                'priority' => 0
            ), false, true,true);
        }
        if (!DB::result_first("select count(*) from %t where addons = %s", array('hooks', 'dzz\publish\classes\alonepagedeleteafter'),true)) {
            DB::insert('hooks', array(
                'app_market_id' =>0,
                'name' => 'alonepagedeleteafter',
                'description' => '删除单页时处理发布数据',
                'type' => 1,
                'update_time' => 0,
                'addons' => 'dzz\publish\classes\alonepagedeleteafter',
                'status' => 1,
                'priority' => 0
            ), false, true,true);
        }
        if (!DB::result_first("select count(*) from %t where addons = %s", array('hooks', 'dzz\publish\classes\vappdeleteafter'),true)) {
            DB::insert('hooks', array(
                'app_market_id' =>0,
                'name' => 'pichomevappdelete',
                'description' => '删除库时处理发布数据',
                'type' => 1,
                'update_time' => 0,
                'addons' => 'dzz\publish\classes\vappdeleteafter',
                'status' => 1,
                'priority' => 0
            ), false, true,true);
        }
        if (!DB::result_first("select count(*) from %t where addons = %s", array('hooks', 'core\dzz\ucheck'),true)) {
            DB::insert('hooks', array(
                'app_market_id' =>0,
                'name' => 'uc_add_user',
                'description' => '',
                'type' => 1,
                'update_time' => 0,
                'addons' => 'core\dzz\ucheck',
                'status' => 1,
                'priority' => 0
            ), false, true,true);
        }

        //处理theme
        DB::insert('pichome_theme',array(
            'id'=>1,
            'themename'=>'超酷时尚',
            'colors'=>'white,dark',
            'templates'=>'',
            'selcolor'=>'dark',
            'themestyle'=>'a:12:{s:5:"slide";a:2:{s:10:"horizontal";a:4:{s:5:"title";s:6:"横幅";s:7:"default";s:4:"true";s:5:"value";s:10:"horizontal";s:4:"size";a:3:{i:0;a:3:{s:5:"title";s:9:"1800×450";s:7:"default";s:4:"true";s:5:"value";s:3:"25%";}i:1;a:3:{s:5:"title";s:9:"1800×500";s:7:"default";s:5:"false";s:5:"value";s:3:"28%";}i:2;a:3:{s:5:"title";s:9:"1800×800";s:7:"default";s:5:"false";s:5:"value";s:3:"44%";}}}s:4:"full";a:4:{s:5:"title";s:6:"满屏";s:7:"default";s:5:"false";s:5:"value";s:4:"full";s:4:"size";a:3:{i:0;a:3:{s:5:"title";s:9:"1800×450";s:7:"default";s:4:"true";s:5:"value";s:3:"25%";}i:1;a:3:{s:5:"title";s:9:"1800×500";s:7:"default";s:5:"false";s:5:"value";s:3:"28%";}i:2;a:3:{s:5:"title";s:9:"1800×800";s:7:"default";s:5:"false";s:5:"value";s:3:"44%";}}}}s:10:"search_rec";a:4:{s:6:"style1";a:3:{s:5:"title";s:21:"简洁边框中对齐";s:7:"default";s:4:"true";s:5:"value";s:6:"style1";}s:6:"style2";a:3:{s:5:"title";s:21:"简洁边框左对齐";s:7:"default";s:5:"false";s:5:"value";s:6:"style2";}s:6:"style3";a:3:{s:5:"title";s:18:"无边框中对齐";s:7:"default";s:5:"false";s:5:"value";s:6:"style3";}s:6:"style4";a:3:{s:5:"title";s:18:"无边框左对齐";s:7:"default";s:5:"false";s:5:"value";s:6:"style4";}}s:10:"search_pub";a:4:{s:6:"style1";a:3:{s:5:"title";s:21:"简洁边框中对齐";s:7:"default";s:4:"true";s:5:"value";s:6:"style1";}s:6:"style2";a:3:{s:5:"title";s:21:"简洁边框左对齐";s:7:"default";s:5:"false";s:5:"value";s:6:"style2";}s:6:"style3";a:3:{s:5:"title";s:18:"无边框中对齐";s:7:"default";s:5:"false";s:5:"value";s:6:"style3";}s:6:"style4";a:3:{s:5:"title";s:18:"无边框左对齐";s:7:"default";s:5:"false";s:5:"value";s:6:"style4";}}s:9:"rich_text";a:2:{s:3:"top";a:4:{s:5:"title";s:12:"顶部分类";s:7:"default";s:4:"true";s:5:"value";s:3:"top";s:4:"size";a:2:{i:0;a:3:{s:5:"title";s:3:"宽";s:7:"default";s:4:"true";s:5:"value";s:4:"full";}i:1;a:3:{s:5:"title";s:3:"窄";s:7:"default";s:5:"false";s:5:"value";s:5:"limit";}}}s:4:"left";a:4:{s:5:"title";s:12:"左侧分类";s:7:"default";s:5:"false";s:5:"value";s:4:"left";s:4:"size";a:2:{i:0;a:3:{s:5:"title";s:3:"宽";s:7:"default";s:4:"true";s:5:"value";s:4:"full";}i:1;a:3:{s:5:"title";s:3:"窄";s:7:"default";s:5:"false";s:5:"value";s:5:"limit";}}}}s:4:"link";a:3:{s:10:"horizontal";a:3:{s:5:"title";s:6:"横排";s:7:"default";s:4:"true";s:5:"value";s:10:"horizontal";}s:4:"card";a:3:{s:5:"title";s:6:"卡片";s:7:"default";s:5:"false";s:5:"value";s:4:"card";}s:4:"icon";a:3:{s:5:"title";s:6:"图标";s:7:"default";s:5:"false";s:5:"value";s:4:"icon";}}s:8:"question";a:2:{s:3:"top";a:4:{s:5:"title";s:12:"顶部分类";s:7:"default";s:4:"true";s:5:"value";s:3:"top";s:4:"size";a:2:{i:0;a:3:{s:5:"title";s:3:"宽";s:7:"default";s:4:"true";s:5:"value";s:4:"full";}i:1;a:3:{s:5:"title";s:3:"窄";s:7:"default";s:5:"false";s:5:"value";s:5:"limit";}}}s:4:"left";a:4:{s:5:"title";s:12:"左侧分类";s:7:"default";s:5:"false";s:5:"value";s:4:"left";s:4:"size";a:2:{i:0;a:3:{s:5:"title";s:3:"宽";s:7:"default";s:4:"true";s:5:"value";s:4:"full";}i:1;a:3:{s:5:"title";s:3:"窄";s:7:"default";s:5:"false";s:5:"value";s:5:"limit";}}}}s:8:"file_rec";a:5:{s:9:"imageList";a:3:{s:5:"title";s:6:"网格";s:7:"default";s:4:"true";s:5:"value";s:9:"imageList";}s:7:"rowGrid";a:3:{s:5:"title";s:9:"自适应";s:7:"default";s:5:"false";s:5:"value";s:7:"rowGrid";}s:6:"tabodd";a:3:{s:5:"title";s:12:"列表单列";s:7:"default";s:5:"false";s:5:"value";s:6:"tabodd";}s:7:"tabeven";a:3:{s:5:"title";s:12:"列表双列";s:7:"default";s:5:"false";s:5:"value";s:7:"tabeven";}s:7:"details";a:3:{s:5:"title";s:6:"详情";s:7:"default";s:5:"false";s:5:"value";s:7:"details";}}s:6:"db_ids";a:6:{s:9:"waterFall";a:3:{s:5:"title";s:9:"瀑布流";s:7:"default";s:4:"true";s:5:"value";s:9:"waterFall";}s:9:"imageList";a:3:{s:5:"title";s:6:"网格";s:7:"default";s:5:"false";s:5:"value";s:9:"imageList";}s:7:"rowGrid";a:3:{s:5:"title";s:9:"自适应";s:7:"default";s:5:"false";s:5:"value";s:7:"rowGrid";}s:6:"tabodd";a:3:{s:5:"title";s:12:"列表单列";s:7:"default";s:5:"false";s:5:"value";s:6:"tabodd";}s:7:"tabeven";a:3:{s:5:"title";s:12:"列表双列";s:7:"default";s:5:"false";s:5:"value";s:7:"tabeven";}s:7:"details";a:3:{s:5:"title";s:6:"详情";s:7:"default";s:5:"false";s:5:"value";s:7:"details";}}s:10:"manual_rec";a:7:{s:3:"one";a:4:{s:5:"title";s:18:"单排文字居中";s:7:"default";s:4:"true";s:5:"value";s:3:"one";s:4:"size";a:3:{i:0;a:3:{s:5:"title";s:8:"266×182";s:7:"default";s:4:"true";s:5:"value";s:9:"rectangle";}i:1;a:3:{s:5:"title";s:8:"266×400";s:7:"default";s:4:"true";s:5:"value";s:8:"vertical";}i:2;a:3:{s:5:"title";s:8:"266×266";s:7:"default";s:4:"true";s:5:"value";s:6:"square";}}}s:3:"two";a:4:{s:5:"title";s:18:"单排文字居下";s:7:"default";s:5:"false";s:5:"value";s:3:"two";s:4:"size";a:3:{i:0;a:3:{s:5:"title";s:8:"266×182";s:7:"default";s:4:"true";s:5:"value";s:9:"rectangle";}i:1;a:3:{s:5:"title";s:8:"266×400";s:7:"default";s:4:"true";s:5:"value";s:8:"vertical";}i:2;a:3:{s:5:"title";s:8:"266×266";s:7:"default";s:4:"true";s:5:"value";s:6:"square";}}}s:5:"three";a:4:{s:5:"title";s:18:"单排图外文字";s:7:"default";s:5:"false";s:5:"value";s:5:"three";s:4:"size";a:3:{i:0;a:3:{s:5:"title";s:8:"266×182";s:7:"default";s:4:"true";s:5:"value";s:9:"rectangle";}i:1;a:3:{s:5:"title";s:8:"266×400";s:7:"default";s:4:"true";s:5:"value";s:8:"vertical";}i:2;a:3:{s:5:"title";s:8:"266×266";s:7:"default";s:4:"true";s:5:"value";s:6:"square";}}}s:4:"four";a:4:{s:5:"title";s:18:"双排文字居中";s:7:"default";s:5:"false";s:5:"value";s:4:"four";s:4:"size";a:3:{i:0;a:3:{s:5:"title";s:8:"266×182";s:7:"default";s:4:"true";s:5:"value";s:9:"rectangle";}i:1;a:3:{s:5:"title";s:8:"266×400";s:7:"default";s:4:"true";s:5:"value";s:8:"vertical";}i:2;a:3:{s:5:"title";s:8:"266×266";s:7:"default";s:4:"true";s:5:"value";s:6:"square";}}}s:4:"five";a:4:{s:5:"title";s:18:"双排文字居下";s:7:"default";s:5:"false";s:5:"value";s:4:"five";s:4:"size";a:3:{i:0;a:3:{s:5:"title";s:8:"266×182";s:7:"default";s:4:"true";s:5:"value";s:9:"rectangle";}i:1;a:3:{s:5:"title";s:8:"266×400";s:7:"default";s:4:"true";s:5:"value";s:8:"vertical";}i:2;a:3:{s:5:"title";s:8:"266×266";s:7:"default";s:4:"true";s:5:"value";s:6:"square";}}}s:3:"six";a:4:{s:5:"title";s:18:"双排图外文字";s:7:"default";s:5:"false";s:5:"value";s:3:"six";s:4:"size";a:3:{i:0;a:3:{s:5:"title";s:8:"266×182";s:7:"default";s:4:"true";s:5:"value";s:9:"rectangle";}i:1;a:3:{s:5:"title";s:8:"266×400";s:7:"default";s:4:"true";s:5:"value";s:8:"vertical";}i:2;a:3:{s:5:"title";s:8:"266×266";s:7:"default";s:4:"true";s:5:"value";s:6:"square";}}}s:5:"seven";a:3:{s:5:"title";s:18:"大图小图混排";s:7:"default";s:5:"false";s:5:"value";s:5:"seven";}}s:9:"image_mix";a:8:{s:3:"x-m";a:3:{s:5:"title";s:19:"左文右图-有框";s:7:"default";s:4:"true";s:5:"value";s:3:"x-m";}s:3:"m-x";a:3:{s:5:"title";s:19:"左图右文-有框";s:7:"default";s:5:"false";s:5:"value";s:3:"m-x";}s:3:"x-b";a:3:{s:5:"title";s:19:"上文下图-有框";s:7:"default";s:5:"false";s:5:"value";s:3:"x-b";}s:3:"b-x";a:3:{s:5:"title";s:19:"上图下文-有框";s:7:"default";s:5:"false";s:5:"value";s:3:"b-x";}s:5:"x-m-n";a:3:{s:5:"title";s:19:"左文右图-无框";s:7:"default";s:5:"false";s:5:"value";s:5:"x-m-n";}s:5:"m-x-n";a:3:{s:5:"title";s:19:"左图右文-无框";s:7:"default";s:5:"false";s:5:"value";s:5:"m-x-n";}s:5:"x-b-n";a:3:{s:5:"title";s:19:"上文下图-无框";s:7:"default";s:5:"false";s:5:"value";s:5:"x-b-n";}s:5:"b-x-n";a:3:{s:5:"title";s:19:"上图下文-无框";s:7:"default";s:5:"false";s:5:"value";s:5:"b-x-n";}}s:11:"collect_rec";a:4:{s:9:"imageList";a:3:{s:5:"title";s:6:"网格";s:7:"default";s:5:"false";s:5:"value";s:9:"imageList";}s:7:"details";a:3:{s:5:"title";s:6:"详情";s:7:"default";s:5:"false";s:5:"value";s:7:"details";}s:6:"tabodd";a:3:{s:5:"title";s:12:"列表单列";s:7:"default";s:5:"false";s:5:"value";s:6:"tabodd";}s:7:"tabeven";a:3:{s:5:"title";s:12:"列表双列";s:7:"default";s:5:"false";s:5:"value";s:7:"tabeven";}}s:11:"collect_ids";a:4:{s:9:"imageList";a:3:{s:5:"title";s:6:"网格";s:7:"default";s:5:"false";s:5:"value";s:9:"imageList";}s:7:"details";a:3:{s:5:"title";s:6:"详情";s:7:"default";s:5:"false";s:5:"value";s:7:"details";}s:6:"tabodd";a:3:{s:5:"title";s:12:"列表单列";s:7:"default";s:5:"false";s:5:"value";s:6:"tabodd";}s:7:"tabeven";a:3:{s:5:"title";s:12:"列表双列";s:7:"default";s:5:"false";s:5:"value";s:7:"tabeven";}}}',
            'themefolder'=>'fashion',
            'dateline'=>'1706533040'
        ),0,true,true);

        //插入模板表
        $tablepre = $_G['config']['db'][1]['tablepre'];
        $sql='REPLACE INTO '.$tablepre.'publish_template (`id`, `tname`, `tdesc`, `tflag`, `ttype`, `dateline`, `cuse`, `tdir`, `exts`, `tags`) VALUES'
            ."(1, 'Base主题-通用展示模板', '适合任意类型文件发布', 'default', 3, 0, 0, 'library', '', ''),"
            ."(3, 'Base主题-默认单页模板', '适合搭建各类型个性化页面', 'default', 4, 0, 0, 'alonepage', '', ''),"
            ."(4, 'Base主题-通用文件详情', '适合任意类型文件发布', 'default', 1, 0, 0, 'singlefile', '', ''),"
            ."(5, '独立主题-可换色多文件模板', '适合任意类型文件发布', 'simple', 2, 0, 0, 'multifile', '', ''),"
            ."(6, '合集默认模板', '合集模板', 'default', 6, 0, 0, 'collect', '', ''),"
            ."(7, 'Base主题-通用展示模板', '适合任意类型文件发布', 'default', 5, 0, 0, 'intelligent', '', ''),"
            ."(8, 'Base主题-满屏多文件模板', '适合任意类型文件发布', 'default', 2, 0, 0, 'multifile', '', ''),"
            ."(9, 'Base主题-知识库、帮助文档模板', '适合文档类文件发布', 'details', 3, 0, 0, 'library', '', ''),"
            ."(10, 'Base主题-单文档模板', '只支持txt类文档发布', 'text', 1, 0, 0, 'singlefile', 'txt,css,html,php', ''),"
            ."(11, 'Base主题-单文档模板', '只支持markdown文档发布', 'md', 1, 0, 0, 'singlefile', 'md', ''),"
            ."(12, 'Base主题-单图模板', '适合视频文件发布', 'video', 1, 0, 0, 'singlefile', 'mp3,mp4,webm,ogv,ogg,wav,m3u8,hls,mpg,mpeg', ''),"
            ."(13, 'Base主题-单图模板', '适合图片文件发布', 'image', 1, 0, 0, 'singlefile', 'jpg,png,jpeg,gif,svg,webp,aai,art,arw,bmp,cmyk,cmyka,cr2,crw,dds,dib,djvu,dng,dot,dpx,emf,epdf,epi,eps,eps2,eps3,epsf,epsi,ept,exr,fax,fig,fits,fpx,gplt,gray,graya,hdr,heic,hpgl,hrz,ico,jbig,jng,jp2,jpt,j2c,j2k,jxr,,miff,mono,mng,m2v,mpc,mpr,mrwmmsl,mtv,mvg,nef,pcd,pcds,pcl,pcx,pdb,pef,pes,pfa,pfb,pfm,pgm,picon,pict,pix,png8,png00,png24,png32,png48,png64,pnm,ppm,ps,ps2,ps3,psb,psd,ptif,pwp,rad,raf,rgb,rgb565,rgba,rgf,rla,rle,sfw,sgi,shtml,sid,mrsid,sum,svg,text,tga,tif,tiff,tim,ttf,ubrl,ubrl6,uil,uyvy,vicar,viff,wbmp,wpg,webp,wmf,wpg,x,xbm,xcf,xpm,xwd,x3f,YCbCr,YCbCrA,yuv,sr2,srf,srw,rw2,nrw,mrw,kdc,erf,canvas,caption,clip,clipboard,fractal,gradient,hald,histogram,inline,map,mask,matte,null,pango,plasma,preview,print,scan,scanx,screenshot,stegano,tile,vid,xc,granite,logo,rose,bricks,circles,fff,3fr,ai,iiq,cdr', ''),"
            ."(14, 'Base主题-窄屏多文件模板', '适合任意类型文件发布，支持自定义横幅与描述。', 'simple2', 2, 0, 0, 'multifile', '', ''),"
            ."(15, '独立主题-可换色大图展示模板', '以大图方式展示、适合图片、视频发布。', 'simple1', 2, 0, 0, 'multifile', 'jpg,png,jpeg,gif,svg,webp,aai,art,arw,bmp,cr2,crw,djvu,dng,dot,dpx,emf,epdf,epi,eps,eps2,eps3,epsf,epsi,ept,exr,fax,fig,fits,fpx,gplt,gray,graya,hdr,heic,hpgl,hrz,ico,jbig,jng,jp2,jpt,j2c,j2k,jxr,,miff,mono,mng,m2v,mpc,mpr,mrwmmsl,mtv,mvg,nef,pcd,pcds,pcl,pcx,pdb,pef,pes,pfa,pfb,pfm,pgm,picon,pict,pix,png8,png00,png24,png32,png48,png64,pnm,ppm,ps,ps2,ps3,psb,psd,ptif,rgb,rgb565,rgba,sfw,tga,tif,tiff,tim,ttf,viff,wbmp,wpg,wmf,wpg,xcf,YCbCr,YCbCrA,srf,srw,rw2,nrw,mrw,erf,canvas,caption,clip,preview,print,scan,scanx,screenshot,logo,rose,circles,fff,3fr,ai,iiq,cdr,mp4,mp3', ''),"
            ."(16, 'Base主题-音乐素材库', '适合音乐素材', 'music', 3, 0, 0, 'library', 'mp3,wav', ''),"
            ."(17, 'Base主题-文档库', '适合文档类', 'document', 3, 0, 0, 'library', '', ''),"
            ."(18, 'Base主题-音乐多文件', '适合音乐素材', 'music', 2, 0, 1, 'multifile', 'aac,ac3,aiff,alac,amr,ape,au,flac,g722,g729,mp1,mp2,mp3,ogg,opus,ra,rm,tta,voc,wav,wma,wv', '');";
        DB::query($sql,array(), true);
        show_msg("数据升级结束", "$theurl?step=delete");

    }

}
elseif ($_GET['step'] == 'delete') {
    $oldtables = array();
    $query = DB::query("SHOW TABLES LIKE '$config[tablepre]%'");
    while ($value = DB::fetch($query)) {
        $values = array_values($value);
        $oldtables[] = $values[0];
    }

    $sql = implode('', file($sqlfile));
    preg_match_all("/CREATE\s+TABLE.+?dzz\_(.+?)\s+\((.+?)\)\s*(ENGINE|TYPE)\s*\=/is", $sql, $matches);
    $newtables = empty($matches[1]) ? array() : $matches[1];
    $newsqls = empty($matches[0]) ? array() : $matches[0];
    $deltables = array();
    $delcolumns = array();

    foreach ($oldtables as $tname) {
        $tname = substr($tname, strlen($config['tablepre']));
        if (in_array($tname, $newtables)) {
            $query = DB::query("SHOW CREATE TABLE " . DB::table($tname));
            $cvalue = DB::fetch($query);
            $oldcolumns = getcolumn($cvalue['Create Table']);
            $i = array_search($tname, $newtables);
            $newcolumns = getcolumn($newsqls[$i]);

            foreach ($oldcolumns as $colname => $colstruct) {
                if ($colname == 'UNIQUE' || $colname == 'KEY') {
                    foreach ($colstruct as $key_index => $key_value) {
                        if (empty($newcolumns[$colname][$key_index])) {
                            $delcolumns[$tname][$colname][$key_index] = $key_value;
                        }
                    }
                } else {
                    if (empty($newcolumns[$colname])) {
                        $delcolumns[$tname][] = $colname;
                    }
                }
            }
        } else {

        }
    }

    show_header();
    echo '<form method="post" autocomplete="off" action="' . $theurl . '?step=delete' . ($_GET['from'] ? '&from=' . rawurlencode($_GET['from']) . '&frommd5=' . rawurlencode($_GET['frommd5']) : '') . '">';

    $deltablehtml = '';
    if ($deltables) {
        $deltablehtml .= '<table>';
        foreach ($deltables as $tablename) {
            $deltablehtml .= "<tr><td><input type=\"checkbox\" name=\"deltables[$tablename]\" value=\"1\"></td><td>{$config['tablepre']}$tablename</td></tr>";
        }
        $deltablehtml .= '</table>';
        echo "<p>以下 <strong>数据表</strong> 与标准数据库相比是多余的:<br>您可以根据需要自行决定是否删除</p>$deltablehtml";
    }

    $delcolumnhtml = '';
    if ($delcolumns) {
        $delcolumnhtml .= '<table>';
        foreach ($delcolumns as $tablename => $cols) {
            foreach ($cols as $coltype => $col) {
                if (is_array($col)) {
                    foreach ($col as $index => $indexvalue) {
                        $delcolumnhtml .= "<tr><td><input type=\"checkbox\" name=\"delcols[$tablename][$coltype][$index]\" value=\"1\"></td><td>{$config['tablepre']}$tablename</td><td>索引($coltype) $index $indexvalue</td></tr>";
                    }
                } else {
                    $delcolumnhtml .= "<tr><td><input type=\"checkbox\" name=\"delcols[$tablename][$col]\" value=\"1\"></td><td>{$config['tablepre']}$tablename</td><td>字段 $col</td></tr>";
                }
            }
        }
        $delcolumnhtml .= '</table>';

        echo "<p>以下 <strong>字段</strong> 与标准数据库相比是多余的:<br>您可以根据需要自行决定是否删除(建议删除)</p>$delcolumnhtml";
    }

    if (empty($deltables) && empty($delcolumns)) {
        echo "<p>与标准数据库相比，没有需要删除的数据表和字段</p><a href=\"$theurl?step=cache" . ($_GET['from'] ? '&from=' . rawurlencode($_GET['from']) . '&frommd5=' . rawurlencode($_GET['frommd5']) : '') . "\">请点击进入下一步</a></p>";
    } else {
        echo "<p><input type=\"submit\" name=\"delsubmit\" value=\"提交删除\"></p><p>您也可以忽略多余的表和字段<br><a href=\"$theurl?step=cache" . ($_GET['from'] ? '&from=' . rawurlencode($_GET['from']) . '&frommd5=' . rawurlencode($_GET['frommd5']) : '') . "\">直接进入下一步</a></p>";
    }
    echo '</form>';

    show_footer();
    exit();


} elseif ($_GET['step'] == 'cache') {

    if (@$fp = fopen($lockfile, 'w')) {
        fwrite($fp, ' ');
        fclose($fp);
    }
    //删除多余文件
    @unlink(DZZ_ROOT . './dzz/pichome/css/admin.css');
    @unlink(DZZ_ROOT . './dzz/pichome/css/common.css');
    @unlink(DZZ_ROOT . './dzz/pichome/css/details.css');
    @unlink(DZZ_ROOT . './dzz/pichome/css/index.css');
    @unlink(DZZ_ROOT . './dzz/pichome/js/audioPlay.js');
    @unlink(DZZ_ROOT . './dzz/pichome/js/headerMethods.js');
    @unlink(DZZ_ROOT . './dzz/pichome/js/headerMethods.js');
    @unlink(DZZ_ROOT . './dzz/pichome/js/jquery,mousewheel.min.js');
    //删除之前版本多余模板文件
    dir_clear(DZZ_ROOT . './dzz/pichome/template/components', 0);
    @rmdir(DZZ_ROOT . './dzz/pichome/template/components');
    dir_clear(DZZ_ROOT . './dzz/pichome/template/frame', 0);
    @rmdir(DZZ_ROOT . './dzz/pichome/template/frame');
    dir_clear(DZZ_ROOT . './dzz/pichome/template/page', 0);
    @rmdir(DZZ_ROOT . './dzz/pichome/template/page');
    dir_clear(DZZ_ROOT . './dzz/pichome/js/plug', 0);
    @rmdir(DZZ_ROOT . './dzz/pichome/js/plug');
    //删除数据库恢复文件，防止一些安全问题；
    @unlink(DZZ_ROOT . './data/restore.php');
    dir_clear(DZZ_ROOT . './data/template');
    //dir_clear(DZZ_ROOT.'./data/cache');
    savecache('setting', '');
    $routefile = DZZ_ROOT.'./data/cache/'. 'route.php';
    if(!is_file($routefile)){
        @file_put_contents($routefile,"<?php \t\n return array();");
    }
    $configfile = DZZ_ROOT.'data/cache/default_mod.php';
    $configarr = array();
    $configarr['default_mod' ]='banner';
    @file_put_contents($configfile,"<?php \t\n return ".var_export($configarr,true).";");
    C::t('setting')->update('default_mod','banner');
    if ($_GET['from']) {
        show_msg('<span id="finalmsg">缓存更新中，请稍候 ...</span><iframe src="../misc.php?mod=syscache" style="display:none;" onload="parent.window.location.href=\'' . $_GET['from'] . '&t=1\'"></iframe>');
    } else {
        show_msg('<span id="finalmsg">缓存更新中，请稍候 ...</span><iframe src="../misc.php?mod=syscache" style="display:none;" onload="document.getElementById(\'finalmsg\').innerHTML = \'恭喜，数据库结构升级完成！为了数据安全，请删除本文件。' . $opensoso . '\'"></iframe>');
    }

}

function has_another_special_table($tablename, $key)
{
    if (!$key) {
        return $tablename;
    }

    $tables_array = get_special_tables_array($tablename);

    if ($key > count($tables_array)) {
        return FALSE;
    } else {
        return TRUE;
    }
}

function converttodzzcode($aid)
{
    return 'path=' . dzzencode('attach::' . $aid);
}

function get_special_tables_array($tablename)
{
    $tablename = DB::table($tablename);
    $tablename = str_replace('_', '\_', $tablename);
    $query = DB::query("SHOW TABLES LIKE '{$tablename}\_%'");
    $dbo = DB::object();
    $tables_array = array();
    while ($row = $dbo->fetch_array($query, $dbo->drivertype == 'mysqli' ? MYSQLI_NUM : MYSQL_NUM)) {
        if (preg_match("/^{$tablename}_(\\d+)$/i", $row[0])) {
            $prefix_len = strlen($dbo->tablepre);
            $row[0] = substr($row[0], $prefix_len);
            $tables_array[] = $row[0];
        }
    }
    return $tables_array;
}

function get_special_table_by_num($tablename, $num)
{
    $tables_array = get_special_tables_array($tablename);

    $num--;
    return isset($tables_array[$num]) ? $tables_array[$num] : FALSE;
}

function getcolumn($creatsql)
{

    $creatsql = preg_replace("/ COMMENT '.*?'/i", '', $creatsql);
    preg_match("/\((.+)\)\s*(ENGINE|TYPE)\s*\=/is", $creatsql, $matchs);

    $cols = explode("\n", $matchs[1]);
    $newcols = array();
    foreach ($cols as $value) {
        $value = trim($value);
        if (empty($value)) continue;
        $value = remakesql($value);
        if (substr($value, -1) == ',') $value = substr($value, 0, -1);

        $vs = explode(' ', $value);
        $cname = $vs[0];

        if ($cname == 'KEY' || $cname == 'INDEX' || $cname == 'UNIQUE') {

            $name_length = strlen($cname);
            if ($cname == 'UNIQUE') $name_length = $name_length + 4;

            $subvalue = trim(substr($value, $name_length));
            $subvs = explode(' ', $subvalue);
            $subcname = $subvs[0];
            $newcols[$cname][$subcname] = trim(substr($value, ($name_length + 2 + strlen($subcname))));

        } elseif ($cname == 'PRIMARY') {

            $newcols[$cname] = trim(substr($value, 11));

        } else {

            $newcols[$cname] = trim(substr($value, strlen($cname)));
        }
    }
    return $newcols;
}

function remakesql($value)
{
    $value = trim(preg_replace("/\s+/", ' ', $value));
    $value = str_replace(array('`', ', ', ' ,', '( ', ' )', 'mediumtext'), array('', ',', ',', '(', ')', 'text'), $value);
    return $value;
}

function show_msg($message, $url_forward = '', $time = 1, $noexit = 0, $notice = '')
{

    if ($url_forward) {
        $url_forward = $_GET['from'] ? $url_forward . '&from=' . rawurlencode($_GET['from']) . '&frommd5=' . rawurlencode($_GET['frommd5']) : $url_forward;
        $message = "<a href=\"$url_forward\">$message (跳转中...)</a><br>$notice<script>setTimeout(\"window.location.href ='$url_forward';\", $time);</script>";
    }

    show_header();
    print<<<END
	<table>
	<tr><td>$message</td></tr>
	</table>
END;
    show_footer();
    !$noexit && exit();
}


function show_header()
{
    global $config;

    $nowarr = array($_GET['step'] => ' class="current"');
    if (in_array($_GET['step'], array('waitingdb', 'prepare'))) {
        $nowarr = array('sql' => ' class="current"');
    }
    print<<<END
	<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
	<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
	<meta http-equiv="Content-Type" content="text/html; charset=$config[charset]" />
	<title> 数据库升级程序 </title>
	<style type="text/css">
	* {font-size:12px; font-family: Verdana, Arial, Helvetica, sans-serif; line-height: 1.5em; word-break: break-all; }
	body { text-align:center; margin: 0; padding: 0; background: #F5FBFF; }
	.bodydiv { margin: 40px auto 0; width:720px; text-align:left; border: solid #86B9D6; border-width: 5px 1px 1px; background: #FFF; }
	h1 { font-size: 18px; margin: 1px 0 0; line-height: 50px; height: 50px; background: #E8F7FC; color: #5086A5; padding-left: 10px; }
	#menu {width: 100%; margin: 10px auto; text-align: center; }
	#menu td { height: 30px; line-height: 30px; color: #999; border-bottom: 3px solid #EEE; }
	.current { font-weight: bold; color: #090 !important; border-bottom-color: #F90 !important; }
	input { border: 1px solid #B2C9D3; padding: 5px; background: #F5FCFF; }
	#footer { font-size: 10px; line-height: 40px; background: #E8F7FC; text-align: center; height: 38px; overflow: hidden; color: #5086A5; margin-top: 20px; }
	</style>
	</head>
	<body>
	<div class="bodydiv">
	<h1>欧奥数据库升级工具</h1>
	<div style="width:90%;margin:0 auto;">
	<table id="menu">
	<tr>
	<td{$nowarr['start']}>升级开始</td>
	<td{$nowarr['sql']}>数据库结构添加与更新</td>
	<td{$nowarr['data']}>数据更新</td>
	<td{$nowarr['delete']}>数据库结构删除</td>
	<td{$nowarr['cache']}>升级完成</td>
	</tr>
	</table>
	<br>
END;
}

function show_footer()
{
    print<<<END
	</div>
	<div id="footer">Copyright © 2012-2026 FilePress All Rights Reserved.</div>
	</div>
	<br>
	</body>
	</html>
END;
}

function runquery($sql)
{
    global $_G;
    $tablepre = $_G['config']['db'][1]['tablepre'];
    $dbcharset = $_G['config']['db'][1]['dbcharset'];

    $sql = str_replace("\r", "\n", str_replace(array(' {tablepre}', ' dzz_', ' `dzz_'), array(' ' . $tablepre, ' ' . $tablepre, ' `' . $tablepre), $sql));
    $ret = array();
    $num = 0;
    foreach (explode(";\n", trim($sql)) as $query) {
        $queries = explode("\n", trim($query));
        foreach ($queries as $query) {
            $ret[$num] .= $query[0] == '#' || $query[0] . $query[1] == '--' ? '' : $query;
        }
        $num++;
    }
    unset($sql);

    foreach ($ret as $query) {
        $query = trim($query);
        if ($query) {

            if (substr($query, 0, 12) == 'CREATE TABLE') {
                $name = preg_replace("/CREATE TABLE ([a-z0-9_]+) .*/is", "\\1", $query);
                DB::query(create_table($query, $dbcharset));

            } else {
                DB::query($query);
            }

        }
    }
}


function save_config_file($filename, $config, $default, $deletevar)
{
    $config = setdefault($config, $default, $deletevar);
    $date = gmdate("Y-m-d H:i:s", time() + 3600 * 8);
    $content = <<<EOT
<?php


\$_config = array();

EOT;
    $content .= getvars(array('_config' => $config));
    $content .= "\r\n// ".str_pad('  THE END  ', 50, '-', STR_PAD_BOTH)."\r\n return \$_config;";
    if (!is_writable($filename) || !($len = file_put_contents($filename, $content))) {
        file_put_contents(DZZ_ROOT . './data/config.php', $content);
        return 0;
    }
    return 1;
}

function setdefault($var, $default, $deletevar)
{

    foreach ($default as $k => $v) {
        if (!isset($var[$k])) {
            $var[$k] = $default[$k];
        } elseif (is_array($v)) {
            $var[$k] = setdefault($var[$k], $default[$k]);
        }
    }
    foreach ($deletevar as $k) {
        unset($var[$k]);
    }
    return $var;
}

function getvars($data, $type = 'VAR')
{
    $evaluate = '';
    foreach ($data as $key => $val) {
        if (!preg_match("/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/", $key)) {
            continue;
        }
        if (is_array($val)) {
            $evaluate .= buildarray($val, 0, "\${$key}") . "\r\n";
        } else {
            $val = addcslashes($val, '\'\\');
            $evaluate .= $type == 'VAR' ? "\$$key = '$val';\n" : "define('" . strtoupper($key) . "', '$val');\n";
        }
    }
    return $evaluate;
}

function buildarray($array, $level = 0, $pre = '$_config')
{
    static $ks;
    if ($level == 0) {
        $ks = array();
        $return = '';
    }

    foreach ($array as $key => $val) {
        if ($level == 0) {
            $newline = str_pad('  CONFIG ' . strtoupper($key) . '  ', 70, '-', STR_PAD_BOTH);
            $return .= "\r\n// $newline //\r\n";
            if ($key == 'admincp') {
                $newline = str_pad(' Founders: $_config[\'admincp\'][\'founder\'] = \'1,2,3\'; ', 70, '-', STR_PAD_BOTH);
                $return .= "// $newline //\r\n";
            }
        }

        $ks[$level] = $ks[$level - 1] . "['$key']";
        if (is_array($val)) {
            $ks[$level] = $ks[$level - 1] . "['$key']";
            $return .= buildarray($val, $level + 1, $pre);
        } else {
            $val = is_string($val) || strlen($val) > 12 || !preg_match("/^\-?[1-9]\d*$/", $val) ? '\'' . addcslashes($val, '\'\\') . '\'' : $val;
            $return .= $pre . $ks[$level - 1] . "['$key']" . " = $val;\r\n";
        }
    }
    return $return;
}

function dir_clear($dir, $index = 1)
{
    global $lang;
    if ($directory = @dir($dir)) {
        while ($entry = $directory->read()) {
            $filename = $dir . '/' . $entry;
            if (is_file($filename)) {
                @unlink($filename);
            }
        }
        $directory->close();
        if ($index) @touch($dir . '/index.htm');
    }
}

function create_table($sql, $dbcharset)
{
    $type = strtoupper(preg_replace("/^\s*CREATE TABLE\s+.+\s+\(.+?\).*(ENGINE|TYPE)\s*=\s*([a-z]+?).*$/isU", "\\2", $sql));
    $type = in_array($type, array('MYISAM', 'HEAP', 'MEMORY')) ? $type : 'MYISAM';
    return preg_replace("/^\s*(CREATE TABLE\s+.+\s+\(.+?\)).*$/isU", "\\1", $sql) .
        (" ENGINE=$type DEFAULT CHARSET=" . $dbcharset);
}

function getpathdata($folderdata, $appid, $pathdata = array())
{
    foreach ($folderdata as $v) {
        $pathdata[$v['id'] . $appid] = $v['name'];
        if ($v['children']) {
            $tmpchild = $v['children'];
            $pathdata = getpathdata($tmpchild, $appid, $pathdata);

        }
    }

    return $pathdata;
}

//更新eagle库目录数据
function initFoldertag($data)
{
    $path = $data['path'];
    if (!is_dir($path)) {
        $path = DZZ_ROOT . 'library' . BS . $data['path'];
    }
    if (!is_dir($path)) return;
    $jsonfile = $path . BS . 'metadata.json';
    $mtime = filemtime($jsonfile);
    $appdatas = file_get_contents($jsonfile);
    //解析出json数据
    $appdatas = json_decode($appdatas, true);

    //目录数据
    $folderdata = $appdatas['folders'];

    C::t('pichome_folder')->insert_folderdata_by_appid($data['appid'], $folderdata);
    //对比目录数据
    $folderarr = getpathdata($folderdata, $data['appid']);
    $folderfids = array_keys($folderarr);
    $delfids = [];
    foreach (DB::fetch_all("select fid from %t where fid not in(%n) and appid = %s", array('pichome_folder', $folderfids, $data['appid'])) as $v) {
        $delfids[] = $v['fid'];
    }
    C::t('pichome_folder')->delete($delfids);
    C::t('pichome_vapp')->update($data['appid'], array('path' => $path));
    return true;

}
function formatpath($path)
{
    if(strpos($path,':') === false){
        $bz = 'dzz';
    }else{
        $patharr = explode(':', $path);
        $bz = $patharr[0];
        $did = $patharr[1];

    }
    if(!is_numeric($did) || $did < 2){
        $bz = 'dzz';
    }

    $rootpath = str_replace(BS,'/',DZZ_ROOT);
    $path = str_replace(DZZ_ROOT, '', $path);
    $path = str_replace($rootpath, '', $path);
    $path = str_replace(BS, '/', $path);
    $path = str_replace('//', '/', $path);
    $path = str_replace('./', '', $path);
    if($bz == 'dzz')$path = 'dzz::'.ltrim($path,'/');
    else $path = ltrim($path,'/');
    return $path;
}

function getbasename($filename)
{
    return preg_replace('/^.+[\\\\\\/]/', '', $filename);
}

function getintPaletteNumber($colors, $palette = array())
{

    if (empty($palette))  $palette = [
        0xfff8e1,0xf57c00,0xffd740,0xb3e5fc,0x607d8b,0xd7ccc8,
        0xff80ab,0x4e342e,0x9e9e9e,0x66bb6a,0xaed581,0x18ffff,
        0xffe0b2,0xc2185b,0x00bfa5,0x00e676,0x0277bd,0x26c6da,
        0x7c4dff,0xea80fc,0x512da8,0x7986cb,0x00e5ff,0x0288d1,
        0x69f0ae,0x3949ab,0x8e24aa,0x40c4ff,0xdd2c00,0x283593,
        0xaeea00,0xffa726,0xd84315,0x82b1ff,0xab47bc,0xd4e157,
        0xb71c1c,0x880e4f,0x00897b,0x689f38,0x212121,0xffff00,
        0x827717,0x8bc34a,0xe0f7fa,0x304ffe,0xd500f9,0xec407a,
        0x6200ea,0xffab00,0xafb42b,0x6a1b9a,0x616161,0x8d6e63,
        0x80cbc4,0x8c9eff,0xffeb3b,0xffe57f,0xfff59d,0xff7043,
        0x1976d2,0x5c6bc0,0x64dd17,0xffd600
    ];
    $arr = array();

    if (is_array($colors)) {
        $isarray = 1;
    } else {
        $colors = (array)$colors;
        $isarray = 0;
    }

    foreach ($colors as $color) {
        $bestColor = 0x000000;
        $bestDiff = PHP_INT_MAX;
        $color = new Color($color);
        foreach ($palette as $key => $wlColor) {
            // calculate difference (don't sqrt)
            $diff = $color->getDiff($wlColor);
            // see if we got a new best
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestColor = $wlColor;
            }
        }
        unset($color);
        $arr[] = array_search($bestColor, $palette);
    }
    return $isarray ? $arr : $arr[0];
}
function fetchtablelist($tablepre = '') {
    global $db;
    $arr = explode('.', $tablepre);
    $dbname = $arr[1] ? $arr[0] : '';
    $tablepre = str_replace('_', '\_', $tablepre);
    $sqladd = $dbname ? " FROM $dbname LIKE '$arr[1]%'" : "LIKE '$tablepre%'";
    $tables = $table = array();
    $query = DB::query("SHOW TABLE STATUS $sqladd");
    while ($table = DB::fetch($query)) {
        $table['Name'] = ($dbname ? "$dbname." : '') . $table['Name'];
        $tables[] = $table['Name'];
    }
    return $tables;
}

?>
