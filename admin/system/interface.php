<?php
    /**
     * Created by PhpStorm.
     * User: Administrator
     * Date: 2020/9/7
     * Time: 19:49
     */
    if (!defined('IN_OAOOA') || !defined('IN_ADMIN')) {
        exit('Access Denied');
    }
    if (!defined('IS_API')) define('IS_API', 1);
    Hook::listen('adminlogin');
	$db = &DB::object();
	$tabletype = $db -> version() > '4.1' ? 'Engine' : 'Type';
	$tablepre = $_G['config']['db'][1]['tablepre'];
	$dbcharset = $_G['config']['db'][1]['dbcharset'];
	$backupdir = C::t('setting') -> fetch('backupdir');
	if (!$backupdir) {
		$backupdir = random(6);
		@mkdir('./data/backup_' . $backupdir, 0777);
		C::t('setting') -> update('backupdir', $backupdir);
	}
	$backupdir = 'backup_' . $backupdir;
	if (!is_dir('./data/' . $backupdir)) {
		mkdir('./data/' . $backupdir, 0777);
	}
	
	$operation = isset($_GET['operation']) ? trim($_GET['operation']) : '';
	if($operation == 'updatecache'){
		include libfile('function/cache');
		$step = max(1, intval($_GET['step']));
		if ($step == 3) {
			$type = explode('_', $_GET['type']);
			if (in_array('data', $type)) {
				updatecache();
                C::t('pichome_route')->update_route();
			}
			if (in_array('tpl', $type) && $_G['config']['output']['tplrefresh']) {
				cleartemplatecache();
			}
			if (in_array('memory', $type)) {
				//清空内存缓存
				C::memory()->clear();
                C::t('cache')->clear_allcache();
			}
			exit(json_encode(array('msg'=>'success')));
		}
	}

    elseif($operation == 'cron'){
		$do = isset($_GET['do']) ? trim($_GET['do']) : '';
		if($do == 'view'){
            if(submitcheck('cronssubmit')) {
                $cronid = isset($_GET['perm']['cronid']) ? intval($_GET['perm']['cronid']) : '';
                if ($cronid) {
                    $cron = DB::fetch_first("SELECT * FROM %t WHERE cronid=%d", array('cron', $cronid));
                    $available = intval($_GET['perm']['available']);
                    $name = getstr($_GET['perm']['name']);
                    DB::update('cron', array('available' => $available, 'name' => $name), ['cronid' => $cronid]);
                    exit(json_encode(array('success' => true, 'data' => $cron)));
                }
            }elseif(submitcheck('addsubmit')){
                $newname = isset($_GET['newname']) ? trim($_GET['newname']) : '';
				if ($newname) {
					$arr = array(
						'name' => dhtmlspecialchars($newname), 
						'type' => 'user', 
						'available' => '0', 
						'weekday' => '-1', 
						'day' => '-1', 
						'hour' => '-1', 
						'minute' => '', 
						'nextrun' => $_G['timestamp']
					);
					if($id = DB::insert('cron', $arr,1)){
						$arr['cronid'] = $id;
						$arr['time'] = lang('per_hour').'00'. lang('point');;
						$arr['lastrun'] = 'N/A';
						$arr['nextrun'] = $arr['nextrun'] ? dgmdate($arr['nextrun'], $_G['setting']['dateformat'] . " " . $_G['setting']['timeformat']) : 'N/A';
						exit(json_encode(array('success'=>true,'data'=>$arr)));
					}else{
						exit(json_encode(array('error'=>true)));
					}
				}else{
					exit(json_encode(array('error'=>true)));
				}
			}
            elseif(submitcheck('delsubmit')) {
				$delete = isset($_GET['delete']) ? intval($_GET['delete']) : '';
				if ($delete) {
					if(DB::delete('cron', "cronid=$delete AND type!='system'")){
						exit(json_encode(array('success'=>true)));
					}else{
						exit(json_encode(array('error'=>true)));
					}
				}else{
					exit(json_encode(array('error'=>true)));
				}
			}

            else {
				$crons = array();
				$query = DB::query("SELECT * FROM " . DB::table('cron') . " ORDER BY type DESC");
				while ($cron = DB::fetch($query)) {
							
					if ($cron['day'] > 0 && $cron['day'] < 32) {
						$cron['time'] = lang('monthly') . $cron['day'] . lang('day');
					} elseif ($cron['weekday'] >= 0 && $cron['weekday'] < 7) {
						$cron['time'] = lang('weekly') . lang('misc_cron_week_day_' . $cron['weekday']);
					} elseif ($cron['hour'] >= 0 && $cron['hour'] < 24) {
						$cron['time'] = lang('everyday');
					} else {
						$cron['time'] = lang('per_hour');
					}
					$cron['time'] .= $cron['hour'] >= 0 && $cron['hour'] < 24 ? sprintf('%02d', $cron['hour']) . lang('timeliness') : '';
					if (!in_array($cron['minute'], array(-1, ''))) {
						foreach ($cron['minute'] = explode("\t", $cron['minute']) as $k => $v) {
							$cron['minute'][$k] = sprintf('%02d', $v);
						}
						$cron['minute'] = implode(',', $cron['minute']);
						$cron['time'] .= $cron['minute'] . lang('point');
					} else {
						$cron['time'] .= '00' . lang('point');
					}
							
					$cron['lastrun'] = $cron['lastrun'] ? dgmdate($cron['lastrun'], $_G['setting']['dateformat'] . " " . $_G['setting']['timeformat']) : 'N/A';
					$cron['nextcolor'] = $cron['nextrun'] && $cron['nextrun'] + $_G['setting']['timeoffset'] * 3600 < TIMESTAMP ? 'style="color: #ff0000"' : '';
					$cron['nextrun'] = $cron['nextrun'] ? dgmdate($cron['nextrun'], $_G['setting']['dateformat'] . " " . $_G['setting']['timeformat']) : 'N/A';
					$cron['run'] = $cron['available'];
							
					$crons[] = $cron;
				}
				exit(json_encode(array('data'=>$crons)));
			
			}
		}elseif($do == 'edit'){
			$cronid = isset($_GET['cronid']) ? intval($_GET['cronid']) : '';
			$cron = DB::fetch_first("SELECT * FROM " . DB::table('cron') . " WHERE cronid='$cronid'");
			if (!$cron) {
				$msg = lang('cron_not_found');
				exit(json_encode(array('error'=>true,'msg'=>$msg)));
			}
			$cron['filename'] = str_replace(array('..', '/', '\\'), array('', '', ''), $cron['filename']);
			$cronminute = str_replace("\t", ',', $cron['minute']);
			$cron['minute'] = explode("\t", $cron['minute']);
			
			
			if (submitcheck('editsubmit')) {
				$daynew = $_GET['weekday'] != -1 ? -1 : $_GET['day'];
				if (strpos($_GET['minute'], ',') !== FALSE) {
					$minutenew = explode(',', $_GET['minute']);
					foreach ($minutenew as $key => $val) {
						$minutenew[$key] = $val = intval($val);
						if ($val < 0 || $val > 59) {
							unset($minutenew[$key]);
						}
					}
					$minutenew = array_slice(array_unique($minutenew), 0, 12);
					$minutenew = implode("\t", $minutenew);
				} else {
					$minutenew = intval($_GET['minute']);
					$minutenew = $minutenew >= 0 && $minutenew < 60 ? $minutenew : '';
				}
							
				$msg = '';
				$_GET['filename'] = str_replace(array('..', '/', '\\'), '', $_GET['filename']);
				if(!preg_match("/^[a-zA-Z0-9_:]+(\.php)$/i",$_GET['filename'])){
					$msg = lang('database_export_filename_invalid');
					exit(json_encode(array('error'=>true,'msg'=>$msg)));
				}
				$efile = explode(':', $_GET['filename']);
				if (count($efile) > 1) {
					$filename = array_pop($efile);
					$cronfile =  DZZ_ROOT. ''.implode("/",$efile).'/cron/'.$filename; 
				} else {
					$cronfile = DZZ_ROOT . './core/cron/' . trim($_GET['filename']);
				}
				if (preg_match("/[\\\\\/\*\?\"\<\>\|]+/", trim($_GET['filename']))) {
					$msg = lang('crons_filename_illegal');
				} elseif (!is_readable($cronfile)) {
					$msg = lang('crons_filename_invalid', array('cronfile' => $cronfile));
				} elseif ($_GET['weekday'] == -1 && $daynew == -1 && $_GET['hour'] == -1 && $minutenew === '') {
					$msg = lang('crons_time_invalid');
				}
				if (!empty($msg)) {
					exit(json_encode(array('error'=>true,'msg'=>$msg)));
				}
							
				DB::update('cron', array('weekday' => $_GET['weekday'], 'day' => $daynew, 'hour' => $_GET['hour'], 'minute' => $minutenew, 'filename' => trim($_GET['filename']), ), "cronid='$cronid'");
							
				dzz_cron::run($cronid);
				$msg = lang('crons_succeed');
				exit(json_encode(array('success'=>true,'msg'=>$msg)));
			} else {
				$weekdayselect = $dayselect = $hourselect = array();
				for ($i = 0; $i <= 6; $i++) {
					$arr = array(
						'value'=>$i,
						'label'=>lang('misc_cron_week_day_'.$i)
					);
					$weekdayselect[] = $arr;
				}
							
				for ($i = 1; $i <= 31; $i++) {
					$arr = array(
						'value'=>$i,
						'label'=>$i.lang('day')
					);
					$dayselect[] = $arr;
				}
							
				for ($i = 0; $i <= 23; $i++) {
					$arr = array(
						'value'=>$i,
						'label'=>$i.lang('timeliness')
					);
					$hourselect[] = $arr;
				}
				$cron['minute']= implode(',',$cron['minute']);
				exit(json_encode(array('weekdayselect'=>$weekdayselect,'dayselect'=>$dayselect,'hourselect'=>$hourselect,'data'=>$cron)));
			}
		}elseif($do == 'run'){
			$cronid = isset($_GET['cronid']) ? intval($_GET['cronid']) : '';
			$cron = DB::fetch_first("SELECT * FROM " . DB::table('cron') . " WHERE cronid='$cronid'");
			if (!$cron) {
				$msg = lang('cron_not_found');
				exit(json_encode(array('error'=>true,'msg'=>$msg)));
			}
			$cron['filename'] = str_replace(array('..', '/', '\\'), array('', '', ''), $cron['filename']);
			$cronminute = str_replace("\t", ',', $cron['minute']);
			$cron['minute'] = explode("\t", $cron['minute']);
			
			$cron['filename'] = str_replace(array('..', '/', '\\'), '', $cron['filename']);
			$efile = explode(':', $cron['filename']);
			if (count($efile) > 1) {
				$filename = array_pop($efile);
				$cronfile =  DZZ_ROOT. ''.implode("/",$efile).'/cron/'.$filename; 
			} else {
				$cronfile = DZZ_ROOT . './core/cron/' . $cron['filename'];
			}
			
			if (!file_exists($cronfile)) {
				$msg = lang('crons_run_invalid', array('cronfile' => $cronfile));
				exit(json_encode(array('error'=>true,'msg'=>$msg)));
			
			} else {
				dzz_cron::run($cron['cronid']);
				$msg = lang('crons_run_succeed');
				exit(json_encode(array('success'=>true,'msg'=>$msg)));
			}
		}
	}
	function createtable($sql, $dbcharset) {
		$type = strtoupper(preg_replace("/^\s*CREATE TABLE\s+.+\s+\(.+?\).*(ENGINE|TYPE)\s*=\s*([a-z]+?).*$/isU", "\\2", $sql));
		$type = in_array($type, array('MYISAM', 'HEAP')) ? $type : 'MYISAM';
		return preg_replace("/^\s*(CREATE TABLE\s+.+\s+\(.+?\)).*$/isU", "\\1", $sql) . (mysql_get_server_info() > '4.1' ? " ENGINE=$type DEFAULT CHARSET=$dbcharset" : " TYPE=$type");
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
			$tables[] = $table;
		}
		return $tables;
	}
	
	function arraykeys2($array, $key2) {
		$return = array();
		foreach ($array as $val) {
			$return[] = $val[$key2];
		}
		return $return;
	}
	
	function syntablestruct($sql, $version, $dbcharset) {
	
		if (strpos(trim(substr($sql, 0, 18)), 'CREATE TABLE') === FALSE) {
			return $sql;
		}
	
		$sqlversion = strpos($sql, 'ENGINE=') === FALSE ? FALSE : TRUE;
	
		if ($sqlversion === $version) {
	
			return $sqlversion && $dbcharset ? preg_replace(array('/ character set \w+/i', '/ collate \w+/i', "/DEFAULT CHARSET=\w+/is"), array('', '', "DEFAULT CHARSET=$dbcharset"), $sql) : $sql;
		}
	
		if ($version) {
			return preg_replace(array('/TYPE=HEAP/i', '/TYPE=(\w+)/is'), array("ENGINE=MEMORY DEFAULT CHARSET=$dbcharset", "ENGINE=\\1 DEFAULT CHARSET=$dbcharset"), $sql);
	
		} else {
			return preg_replace(array('/character set \w+/i', '/collate \w+/i', '/ENGINE=MEMORY/i', '/\s*DEFAULT CHARSET=\w+/is', '/\s*COLLATE=\w+/is', '/ENGINE=(\w+)(.*)/is'), array('', '', 'ENGINE=HEAP', '', '', 'TYPE=\\1\\2'), $sql);
		}
	}
	
	function sqldumptablestruct($table) {
		global $_G, $db, $excepttables;
	
		if (is_array($excepttables) && in_array($table, $excepttables)) {
			return;
		}
	
		$createtable = DB::query("SHOW CREATE TABLE $table", 'SILENT');
	
		if (!DB::error()) {
			$tabledump = "DROP TABLE IF EXISTS $table;\n";
		} else {
			return '';
		}
	
		$create = $db -> fetch_row($createtable);
	
		if (strpos($table, '.') !== FALSE) {
			$tablename = substr($table, strpos($table, '.') + 1);
			$create[1] = str_replace("CREATE TABLE $tablename", 'CREATE TABLE ' . $table, $create[1]);
		}
		$tabledump .= $create[1];
	
		if ($_GET['sqlcompat'] == 'MYSQL41' && $db -> version() < '4.1') {
			$tabledump = preg_replace("/TYPE\=(.+)/", "ENGINE=\\1 DEFAULT CHARSET=" . $dumpcharset, $tabledump);
		}
		if ($db -> version() > '4.1' && $_GET['sqlcharset']) {
			$tabledump = preg_replace("/(DEFAULT)*\s*CHARSET=.+/", "DEFAULT CHARSET=" . $_GET['sqlcharset'], $tabledump);
		}
	
		$tablestatus = DB::fetch_first("SHOW TABLE STATUS LIKE '$table'");
		$tabledump .= ($tablestatus['Auto_increment'] ? " AUTO_INCREMENT=".$tablestatus['Auto_increment'] : ''). ";\n\n";
		if ($_GET['sqlcompat'] == 'MYSQL40' && $db -> version() >= '4.1' && $db -> version() < '5.1') {
			if ($tablestatus['Auto_increment'] <> '') {
				$temppos = strpos($tabledump, ',');
				$tabledump = substr($tabledump, 0, $temppos) . ' auto_increment' . substr($tabledump, $temppos);
			}
			if ($tablestatus['Engine'] == 'MEMORY') {
				$tabledump = str_replace('TYPE=MEMORY', 'TYPE=HEAP', $tabledump);
			}
		}
		return $tabledump;
	}
	
	function sqldumptable($table, $startfrom = 0, $currsize = 0) {
		global $_G, $db, $startrow, $dumpcharset, $complete, $excepttables;
	
		$offset = 300;
		$tabledump = '';
		$tablefields = array();
	
		$query = DB::query("SHOW FULL COLUMNS FROM $table", 'SILENT');
		if (strexists($table, 'adminsessions')) {
			return;
		} elseif (!$query && DB::errno() == 1146) {
			return;
		} elseif (!$query) {
			$_GET['usehex'] = FALSE;
		} else {
			while ($fieldrow = DB::fetch($query)) {
				$tablefields[] = $fieldrow;
			}
		}
	
		if (!is_array($excepttables) || !in_array($table, $excepttables)) {
			$tabledumped = 0;
			$numrows = $offset;
			$firstfield = $tablefields[0];
	
			if ($_GET['extendins'] == '0') {
				while ($currsize + strlen($tabledump) + 500 < $_GET['sizelimit'] * 1000 && $numrows == $offset) {
					if ($firstfield['Extra'] == 'auto_increment') {
						$selectsql = "SELECT * FROM ".$table." WHERE " .$firstfield['Field']." > ". $startfrom . " ORDER BY ".$firstfield['Field']." LIMIT ".$offset;
					} else {
						$selectsql = "SELECT * FROM $table LIMIT $startfrom, $offset";
					}
					$tabledumped = 1;
					$rows = DB::query($selectsql);
					$numfields = $db -> num_fields($rows);
	
					$numrows = DB::num_rows($rows);
					while ($row = $db -> fetch_row($rows)) {
						$comma = $t = '';
						for ($i = 0; $i < $numfields; $i++) {
							$t .= $comma . ($_GET['usehex'] && !empty($row[$i]) && (strexists($tablefields[$i]['Type'], 'char') || strexists($tablefields[$i]['Type'], 'text')) ? '0x' . bin2hex($row[$i]) : '\'' . ($db->escape_string($row[$i])) . '\'');
							$comma = ',';
						}
						if (strlen($t) + $currsize + strlen($tabledump) + 500 < $_GET['sizelimit'] * 1000) {
							if ($firstfield['Extra'] == 'auto_increment') {
								$startfrom = $row[0];
							} else {
								$startfrom++;
							}
							$tabledump .= "INSERT INTO $table VALUES ($t);\n";
						} else {
							$complete = FALSE;
							break 2;
						}
					}
				}
			} else {
				while ($currsize + strlen($tabledump) + 500 < $_GET['sizelimit'] * 1000 && $numrows == $offset) {
					if ($firstfield['Extra'] == 'auto_increment') {
						$selectsql = "SELECT * FROM ". $table ." WHERE ". $firstfield['Field'] ." > ". $startfrom . " LIMIT ".$offset;
					} else {
						$selectsql = "SELECT * FROM $table LIMIT $startfrom, $offset";
					}
					$tabledumped = 1;
					$rows = DB::query($selectsql);
					$numfields = $db -> num_fields($rows);
	
					if ($numrows = DB::num_rows($rows)) {
						$t1 = $comma1 = '';
						while ($row = $db -> fetch_row($rows)) {
							$t2 = $comma2 = '';
							for ($i = 0; $i < $numfields; $i++) {
								$t2 .= $comma2 . ($_GET['usehex'] && !empty($row[$i]) && (strexists($tablefields[$i]['Type'], 'char') || strexists($tablefields[$i]['Type'], 'text')) ? '0x' . bin2hex($row[$i]) : '\'' . ($db->escape_string($row[$i])) . '\'');
								$comma2 = ',';
							}
							if (strlen($t1) + $currsize + strlen($tabledump) + 500 < $_GET['sizelimit'] * 1000) {
								if ($firstfield['Extra'] == 'auto_increment') {
									$startfrom = $row[0];
								} else {
									$startfrom++;
								}
								$t1 .= "$comma1 ($t2)";
								$comma1 = ',';
							} else {
								$tabledump .= "INSERT INTO $table VALUES $t1;\n";
								$complete = FALSE;
								break 2;
							}
						}
						$tabledump .= "INSERT INTO $table VALUES $t1;\n";
					}
				}
			}
	
			$startrow = $startfrom;
			$tabledump .= "\n";
		}
	
		return $tabledump;
	}
	
	function splitsql($sql) {
		$sql = str_replace("\r", "\n", $sql);
		$ret = array();
		$num = 0;
		$queriesarray = explode(";\n", trim($sql));
		unset($sql);
		foreach ($queriesarray as $query) {
			$queries = explode("\n", trim($query));
			foreach ($queries as $query) {
				$ret[$num] .= $query[0] == "#" ? NULL : $query;
			}
			$num++;
		}
		return ($ret);
	}
	
	function slowcheck($type1, $type2) {
		$t1 = explode(' ', $type1);
		$t1 = $t1[0];
		$t2 = explode(' ', $type2);
		$t2 = $t2[0];
		$arr = array($t1, $t2);
		sort($arr);
		if ($arr == array('mediumtext', 'text')) {
			return TRUE;
		} elseif (substr($arr[0], 0, 4) == 'char' && substr($arr[1], 0, 7) == 'varchar') {
			return TRUE;
		}
		return FALSE;
	}
	
	function checkpermission($action, $break = 1) {
		global $_G;
		if (!isset($_G['config']['admincp'])) {
			return lang('db_config_admincp');
		} elseif (!$_G['config']['admincp'][$action]) {
			return lang('db_not_allow_config_admincp');
		} else {
			return true;
		}
	}