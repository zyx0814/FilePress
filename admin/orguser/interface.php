<?php
    /**
     * Created by PhpStorm.
     * User: Administrator
     * Date: 2020/9/7
     * Time: 19:49
     */
if (!defined('IN_OAOOA') || !defined('IN_ADMIN') || !defined('PICHOME_LIENCE')) {
    exit('Access Denied');
}
    if (!defined('IS_API')) define('IS_API', 1);
    Hook::listen('adminlogin');
    $do = isset($_GET['do']) ? trim($_GET['do']) : '';
    global $_G;
    $doperm = get_user_perm($_G['member']);
    //非管理员拒绝访问
    if ($doperm < 2) exit(json_encode(array('error' => true,'msg'=>lang('privilege'))));
    require_once libfile('function/organization');
    if ($do == 'getgroup') {//获取部门列表
        $defaultdepartment = '1';
        //获取所有机构部门数据
        $orgdata = DB::fetch_all("select orgid,orgname ,forgid from %t  where `type`=0 order by forgid asc", array('organization'));
        
        $orgdatas = array();
        foreach ($orgdata as $val) {
            if ($val['forgid']) {
                $val['icon'] = 'ri-community-line';
            } else {
                $val['icon'] = 'ri-building-4-line';
            }
            $orgdatas[] = $val;
        }
        $returndata = list_to_tree($orgdatas);
        exit(json_encode(array('data' => $returndata, 'defaultid' => $defaultdepartment)));
    } elseif ($do == 'addgroup') {
        $grouptitle = isset($_GET['grouptitle']) ? trim($_GET['grouptitle']) : '';
        if (!$grouptitle) exit(json_encode(array('error' => true)));
        $groupid = C::t('usergroup')->insert_data($grouptitle);
        exit(json_encode(array('groupid' => $groupid)));
    } elseif ($do == 'create') {
        $forgid = intval($_GET['forgid']);
        $borgid = isset($_GET['orgid']) ? intval($_GET['orgid']) : 0;
        $text = isset($_GET['text']) ? trim($_GET['text']) : lang('new_department');
        //放在此部门后面
        if (!$ismoderator = C::t('organization_admin')->ismoderator_by_uid_orgid($forgid, $_G['uid'])) {
            exit(json_encode(array('error' =>true,'msg'=> lang('privilege'))));
        }
        /*默认新建机构和部门开始群组manageon群组管理员开启 syatemon系统管理员开启 available 系统管理员开启共享目录,保留diron(群组管理员开启目录)控制是否开启目录显示在前台*/
        $setarr = array('forgid' => intval($_GET['forgid']), 'orgname' => $text, 'fid' => 0, 'disp' => intval($_GET['disp']), 'indesk' => 0, 'dateline' => TIMESTAMP, 'available' => 1, 'syatemon' => 1, 'manageon' => 1);
        
        if ($setarr = C::t('organization')->insert_by_forgid($setarr, $borgid)) {
            include_once libfile('function/cache');
            updatecache('organization');
        } else {
            $setarr['error'] = true;
            $setarr['mgs']='create organization failure';
        }
        if ($forgid) {
            $setarr['icon'] = 'ri-community-line';
        } else {
            $setarr['icon'] = 'ri-building-4-line';
        }
        exit(json_encode($setarr));
    } elseif ($do == 'rename') {
        $orgid = intval($_GET['orgid']);
        if (!$ismoderator = C::t('organization_admin')->ismoderator_by_uid_orgid($orgid, $_G['uid'])) {
            exit(json_encode(array('error' =>true,'msg'=>lang('privilege'))));
        }
        if (C::t('organization')->update_by_orgid($orgid, array('orgname' => getstr($_GET['text'])))) {
            exit(json_encode(array('msg' => 'success')));
        } else {
            exit(json_encode(array('error' =>true,'msg' => lang('rechristen_error'))));
        }
    } elseif ($do == 'deleteorg') {
        $orgid = ($_GET['orgid']);
        $forgid = intval($_GET['forgid']);
        if (!$ismoderator = C::t('organization_admin')->ismoderator_by_uid_orgid($forgid, $_G['uid'])) {
            exit(json_encode(array('error' =>true,'msg' => loang('privilege'))));
        }
        if ($return = C::t('organization')->delete_by_orgid($orgid)) {//删除部门，部门的用户移动到上级部门去;
            if ($return['error']) {
                exit(json_encode($return));
            }
            exit(json_encode(array('error' =>true,'msg' => 'success')));
        } else {
            exit(json_encode(array('error' =>true,'msg' => lang('delete_error'))));
        }
        
    } elseif ($do == 'adduser') {//添加用户
        require_once libfile('function/user', '', 'user');
        if (submitcheck('accountadd')) {
            //验证用户限制
             if (!checkUserLimit()) {
                exit(json_encode(array('error' => true, 'msg' => lang('license_user_exceed'))));
             }
            //处理用户部门和职位
            $orgids = array();
            foreach ($_GET['orgids'] as $key => $orgid) {
                if (!$orgid)
                    continue;
                if (C::t('organization_admin')->ismoderator_by_uid_orgid($orgid, $_G['uid'], 1)) {
                    $orgids[$orgid] = intval($_GET['jobids'][$key]);
                }
            }
            if (!$orgids && $_G['adminid'] != 1)
                
                exit(json_encode(array('error' => true, 'msg' => lang('no_parallelism_jurisdiction'))));
            //用户名验证
            $username = trim($_GET['username']);
            if (empty($username)) {
                exit(json_encode(array('error' => true, 'msg' => lang('nickname_will'))));
            }
            $nickname = trim($_GET['nickname']);
            
            $usernamelen = dstrlen($_GET['nickname']);
            if ($usernamelen < 3) {
                exit(json_encode(array('error' => true, 'msg' => lang('profile_username_tooshort'))));
            } elseif ($usernamelen > 30) {
                exit(json_encode(array('error' => true, 'msg' => lang('profile_username_toolong'))));
            }
            /* $username = trim($_GET['username']);
             if ($username) {
                 $usernamelen = dstrlen($_GET['username']);
                 if ($usernamelen < 3) {
                     exit(json_encode(array('error' => true, 'msg' => lang('profile_username_tooshort'))));
                 } elseif ($usernamelen > 30) {
                     exit(json_encode(array('error' => true, 'msg' => lang('profile_username_toolong'))));
                 }*/
            //如果输入用户名，检查用户名不能重复
            if (C::t('user')->fetch_by_nickname($nickname)) {
                exit(json_encode(array('error' => true, 'msg' => lang('user_registered_retry'))));
            }
            
            
            $user_extra = array();
            //如果输入手机号码，检查手机号码不能重复
            $phone = trim($_GET['phone']);
            if ($phone) {
                if (!preg_match("/^\d+$/", $phone)) {
                    exit(json_encode(array('error' => true, 'msg' => lang('user_phone_illegal'))));
                }
                if (C::t('user')->fetch_by_phone($phone)) {
                    exit(json_encode(array('error' => true, 'msg' => lang('user_phone_registered'))));
                    
                }
                $user_extra['phone'] = $phone;
            }
            //如果输入微信号，检查微信号不能重复
            $weixinid = trim($_GET['weixinid']);
            if ($weixinid) {
                if (!preg_match("/^[a-zA-Z\d_]{5,}$/i", $weixinid)) {
                    exit(json_encode(array('error' => true, 'msg' => lang('weixin_illegal'))));
                    
                }
                if (C::t('user')->fetch_by_weixinid($weixinid)) {
                    exit(json_encode(array('error' => true, 'msg' => lang('weixin_registered'))));
                    
                }
                $user_extra['weixinid'] = $weixinid;
            }
            
            
            //邮箱验证部分
            $email = strtolower(trim($_GET['email']));
            checkemail($_GET['email']);
            
            //密码验证部分
            if ($_G['setting']['pwlength']) {
                if (strlen($_GET['password']) < $_G['setting']['pwlength']) {
                    exit(json_encode(array('error' => true, 'msg' => lang('profile_password_tooshort'))));
                    
                }
            }
            
            if (!$_GET['password'] || $_GET['password'] != addslashes($_GET['password'])) {
                exit(json_encode(array('error' => true, 'msg' => lang('profile_passwd_illegal'))));
                
            }
            $password = $_GET['password'];

            $result = uc_user_register(addslashes($username), $password, $email, $nickname, '', '', $_G['clientip'], 0);
            if (is_array($result)) {
                if ($result['error']) {
                    exit(json_encode(array('error' => true, 'msg' => $result['error'])));
                    
                }
                $uid = $result['uid'];
                $password = $result['password'];
            } else {
                $uid = $result;
            }
            if ($uid <= 0) {
                if ($uid == -1) {
                    exit(json_encode(array('error' => true, 'msg' => lang('profile_username_illegal'))));
                    
                } elseif ($uid == -2) {
                    exit(json_encode(array('error' => true, 'msg' => lang('profile_username_protect'))));
                    
                } elseif ($uid == -3) {
                    exit(json_encode(array('error' => true, 'msg' => lang('profile_username_duplicate'))));
                    
                    
                } elseif ($uid == -4) {
                    exit(json_encode(array('error' => true, 'msg' => lang('profile_email_illegal'))));
                    
                } elseif ($uid == -5) {
                    exit(json_encode(array('error' => true, 'msg' => lang('profile_email_domain_illegal'))));
                    
                } elseif ($uid == -6) {
                    exit(json_encode(array('error' => true, 'msg' => lang('profile_email_duplicate'))));
                    
                } elseif ($uid == -7) {
                    exit(json_encode(array('error' => true, 'msg' => lang('profile_username_illegal'))));
                    
                } else {
                    exit(json_encode(array('error' => true, 'msg' => lang('undefined_action'))));
                    
                }
            }
            //插入用户状态表
            $status = array('uid' => $uid, 'regip' => '', 'lastip' => '', 'lastvisit' => TIMESTAMP, 'lastactivity' => TIMESTAMP, 'lastsendmail' => 0);
            C::t('user_status')->insert($status, false, true);
            //处理管理员
            C::t('user')->setAdministror($uid, intval($_GET['groupid']));
            //加入额外信息
            if ($user_extra)
                C::t('user')->update($uid, $user_extra);
            
            if ($orgids) C::t('organization_user')->replace_orgid_by_uid($uid, $orgids);
            //用户权限级别
            $pichomelevel = isset($_GET['pichomelevel']) ? intval($_GET['pichomelevel']):0;
            C::t('user_setting')->update_by_skey('pichomelevel',$pichomelevel,$uid);
            //处理额外空间
            $addsize = intval($_GET['addsize']);
            if (C::t('user_field')->fetch($uid)) {
                C::t('user_field')->update($uid, array('addsize' => $addsize, 'perm' => 0));
            } else {
                C::t('user_field')->insert(array('uid' => $uid, 'addsize' => $addsize, 'perm' => 0, 'iconview' => $_G['setting']['desktop_default']['iconview'] ? $_G['setting']['desktop_default']['iconview'] : 2, 'taskbar' => $_G['setting']['desktop_default']['taskbar'] ? $_G['setting']['desktop_default']['taskbar'] : 'bottom', 'iconposition' => intval($_G['setting']['desktop_default']['iconposition']), 'direction' => intval($_G['setting']['desktop_default']['direction']),));
            }
            
            if ($_GET['sendmail']) {
                $email_password_message = lang('email_password_message', array('sitename' => $_G['setting']['sitename'], 'siteurl' => $_G['siteurl'], 'email' => $_GET['email'], 'password' => $_GET['password']));
                
                if (!sendmail_cron("$_GET[email] <$_GET[email]>", lang('email_password_subject'), $email_password_message)) {
                    runlog('sendmail', "$_GET[email] sendmail failed.");
                }
            }
            
            exit(json_encode(array('success' => true)));
            
        } else {
            $defaultdepartment = C::t('setting')->fetch('defaultdepartment');
            if (!$defaultdepartment) $defaultdepartment = 1;
            $depart = C::t('organization')->fetch($defaultdepartment);
            $orgdatas = array();
            foreach ($depart as $key => $value) {
                $pathkey = str_replace('_', '', $value['pathkey']);
                $tmporgids = explode('-', $pathkey);
                $currentkey = array_search($value['orgid'], $tmporgids);
                unset($tmporgids[$currentkey]);
                $orgdatas[$value['orgid']] = array('orgname' => $value['orgname'], 'path' => $tmporgids);
            }
            exit(json_encode($orgdatas));
        }
    } elseif ($do == 'edituser') {//编辑用户
        require_once libfile('function/user', '', 'user');
        $uid = $_GET['uid'];
		$user = C::t('user')->fetch_by_uid($uid);
		$edituserperm = get_user_perm($user);
        if (submitcheck('accountedit')) {
            //判断对当前用户的修改权限
            if ($doperm < $edituserperm && $_G['uid'] != $uid) {
                exit(json_encode(array('error' => true, 'msg' => lang('privilege'))));
            }
            //用户名验证
            $username = trim($_GET['username']);
            if (empty($username)) {
                exit(json_encode(array('error' => true, 'msg' => lang('name_will'))));
            }
            $nickname = trim($_GET['nickname']);
            
            $usernamelen = dstrlen($_GET['nickname']);
            if ($usernamelen < 3) {
                exit(json_encode(array('error' => true, 'msg' => lang('profile_nickname_tooshort'))));
            } elseif ($usernamelen > 30) {
                showmessage('profile_nickname_toolong');
                exit(json_encode(array('error' => true, 'msg' => lang('profile_nickname_tooshort'))));
            } elseif (!check_username(addslashes(trim(stripslashes($nickname))))) {
                exit(json_encode(array('error' => true, 'msg' => lang('profile_username_illegal'))));
            }
            //如果输入用户名，检查用户名不能重复
            if (strtolower($nickname) != strtolower($user['nickname'])) {
                if (C::t('user')->fetch_by_nickname($nickname)) {
                    exit(json_encode(array('error' => true, 'msg' => lang('user_registered_retry'))));
                }
            }
            
            //如果输入手机号码，检查手机号码不能重复
            $phone = trim($_GET['phone']);
            if ($phone) {
                if (!preg_match("/^\d+$/", $phone)) {
                    exit(json_encode(array('error' => true, 'msg' => lang('user_phone_illegal'))));
                }
                if ($phone != $user['phone'] && C::t('user')->fetch_by_phone($phone)) {
                    exit(json_encode(array('error' => true, 'msg' => lang('user_phone_registered'))));
                }
            }
            //如果输入微信号，检查微信号不能重复
            $weixinid = trim($_GET['weixinid']);
            if ($weixinid) {
                if (!preg_match("/^[a-zA-Z\d_]{5,}$/i", $weixinid)) {
                    exit(json_encode(array('error' => true, 'msg' => lang('weixin_illegal'))));
                }
                if ($weixinid != $user['weixinid'] && C::t('user')->fetch_by_weixinid($weixinid)) {
                    exit(json_encode(array('error' => true, 'msg' => lang('weixin_registered'))));
                    
                }
            }
            
            //邮箱验证部分
            $email = strtolower(trim($_GET['email']));
            if (!isemail($email)) {
                exit(json_encode(array('error' => true, 'msg' => lang('profile_email_illegal'))));
                
            } elseif (!check_emailaccess($email)) {
                exit(json_encode(array('error' => true, 'msg' => lang('profile_email_domain_illegal'))));
                
            }
            if ($email != strtolower($user['email'])) {
                //邮箱不能重复
                if (C::t('user')->fetch_by_email($email)) {
                    exit(json_encode(array('error' => true, 'msg' => lang('email_registered_retry'))));
                    
                }
            }
            
            //密码验证部分
            if ($_GET['password']) {
                if ($_G['setting']['pwlength']) {
                    if (strlen($_GET['password']) < $_G['setting']['pwlength']) {
                        exit(json_encode(array('error' => true, 'msg' => lang('profile_password_tooshort'))));
                        
                    }
                }
                
                if ($_GET['password'] !== $_GET['password2']) {
                    exit(json_encode(array('error' => true, 'msg' => lang('profile_passwd_notmatch'))));
                    
                }
            }
            $password = $_GET['password'];
            if ($password) {
                $salt = substr(uniqid(rand()), -6);
                $setarr = array('salt' => $salt, 'password' => md5(md5($password) . $salt), 'nickname' => $nickname, 'username' => $username, 'phone' => $phone, 'weixinid' => $weixinid, 'secques' => '', 'email' => $email, 'status' => intval($_GET['status']));
                
            } else {
                $setarr = array('nickname' => $nickname, 'username' => $username, 'email' => $email, 'phone' => $phone, 'weixinid' => $weixinid, 'status' => intval($_GET['status']));
            }
            C::t('user')->update($uid, $setarr);
            
            //处理管理员
            C::t('user')->setAdministror($uid, intval($_GET['groupid']));
            $orgids = [];
            foreach ($_GET['orgids'] as $key => $orgid) {
                if (!$orgid)
                    continue;
                if (C::t('organization_admin')->ismoderator_by_uid_orgid($orgid, $_G['uid'], 1)) {
                    if ($_GET['jobids']) {
                        $orgids[$orgid] = intval($_GET['jobids'][$key]);
                    } else {
                        $orgids[$orgid] = intval($orgid);
                    }
                    
                }
            }
            //用户权限级别
            $pichomelevel = isset($_GET['pichomelevel']) ? intval($_GET['pichomelevel']):0;
            C::t('user_setting')->update_by_skey('pichomelevel',$pichomelevel,$uid);
            if ($orgids) C::t('organization_user')->replace_orgid_by_uid($uid, $orgids);
            //处理额外空间和用户空间
            $userspace = intval($_GET['userspace']);
            if (C::t('user_field')->fetch($uid)) {
                C::t('user_field')->update($uid, array('userspace' => $userspace, 'perm' => 0));
            } else {
                C::t('user_field')->insert(array('uid' => $uid, 'userspace' => $userspace, 'perm' => 0, 'iconview' => $_G['setting']['desktop_default']['iconview'] ? $_G['setting']['desktop_default']['iconview'] : 2, 'taskbar' => $_G['setting']['desktop_default']['taskbar'] ? $_G['setting']['desktop_default']['taskbar'] : 'bottom', 'iconposition' => intval($_G['setting']['desktop_default']['iconposition']), 'direction' => intval($_G['setting']['desktop_default']['direction']),));
            }
            exit(json_encode(array('success' => true)));
            
        } else {
            

            $userfield = C::t('user_field')->fetch($uid);
            
            //$user['status']=$user['status']>0?0:1;
            $departs = array();
            $data_depart = array();
            //$departs=getDepartmentByUid($uid);
            $orgids = C::t('organization_user')->fetch_orgids_by_uid($uid);
            //判断是否对此用户有管理权限
            $uperm = false;
            if ($_G['adminid'] != 1) {
                foreach ($orgids as $orgid) {
                    if (C::t('organization_admin')->ismoderator_by_uid_orgid($orgid, $_G['uid'])) {
                        $uperm = true;
                        break;
                    }
                }
                if (!$uperm)
                    exit(lang('orguser_edituser_add_user1'));
            }
            //获取系统可分配空间大小
            $allowallotspace = C::t('organization')->get_system_allowallot_space();
            //如果该用户之前有分配空间，当前用户可分配空间=系统可分配空间+该用户之前分配空间(若无，则加上当前用户已使用空间)
            if ($userfield['userspace'] > 0) {
                $currentuserAllotspace = $allowallotspace + $userfield['userspace'] * 1024 * 1024;
            } else {
                $currentuserAllotspace = $allowallotspace + $userfield['usesize'];
            }
            $neworgid = array();
            foreach ($orgids as $key => $value) {
                $neworgid[] = $value;
            }
            // $departs = C::t('organization')->fetch_all($orgids);
            // $orgdatas = array();
            // foreach ($departs as $key => $value) {
            //     $pathkey  = str_replace('_','',$value['pathkey']);
            //     $tmporgids = explode('-',$pathkey);
            //     $currentkey = array_search($value['orgid'],$tmporgids);
            //     unset($tmporgids[$currentkey]);
            //     $orgdatas[$value['orgid']] = array('orgname'=>$value['orgname'],'path'=>$tmporgids);
            // }
            //用户权限级别

            $pichomelevel = C::t('user_setting')->fetch_by_skey('pichomelevel',$uid);
            $user['pichomelevel'] = $pichomelevel ? intval($pichomelevel):0;
            $perm = 1;
            if ($user['groupid'] < $_G['groupid'] || (C::t('user')->checkfounder($user) && !C::t('user')->checkfounder($_G['member']))) {
                $perm = 0;
            }
            $user['orgids'] = $neworgid;
			$user['perm'] = $edituserperm;
            exit(json_encode($user));
            
        }
    } elseif ($do == 'getuser') {
        $id = intval($_GET['id']);
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $prepage = isset($_GET['limit']) ? intval($_GET['limit']):20;
        $start = ($page - 1) * $prepage;
        $limitsql = 'limit ' . $start . ',' . $prepage;
        $uids = $data = $userdata = array();
        if (!$id) {
           $uids_org=array();
    		foreach(DB::fetch_all("SELECT u.uid from %t u LEFT JOIN %t o ON u.orgid=o.orgid where o.type='0'",array('organization_user','organization')) as $value){
    			 $uids_org[$value['uid']]=$value['uid'];
    		}
    		//获取不属于所有机构和部门的用户
    		$total = DB::result_first("select count(*) from %t where uid NOT IN(%n) ",array('user',$uids_org));
            foreach (C::t('organization_user')->fetch_user_not_in_orgid(0, $limitsql) as $value) {
                if (!$value['uid']) continue;
                $uids[] = $value['uid'];
            }
            foreach (DB::fetch_all("select uid,username,groupid,adminid,nickname,collects,downloads,uploads,status from %t where uid in(%n)", array('user', $uids)) as $val) {
                $val['perm'] = get_user_perm($val);
                $datas[] = $val;
            }
            getuserIcon($uids, $datas, $data);
            
        } else {
            $orgids = C::t('organization')->get_childorg_by_orgid($id);
            $total = C::t('organization_user')->fetch_user_by_orgid($orgids, 0, true);
            foreach (C::t('organization_user')->fetch_user_by_orgid($orgids, 0, false, $limitsql) as $value) {
                if (!$value['uid']) continue;
                $uids[] = $value['uid'];
                
            }
            foreach (DB::fetch_all("select uid,groupid,username,adminid,nickname,collects,downloads,uploads,status from %t where uid in(%n)", array('user', $uids)) as $val) {
                $val['perm'] = get_user_perm($val);
                $datas[] = $val;
            }
            
            getuserIcon($uids, $datas, $data);
            
        }
        $orgdata = array();
        foreach (DB::fetch_all("select ou.uid,o.orgname from %t ou
    left join %t o on o.orgid=ou.orgid  where ou.uid in(%n) and o.type=0", array('organization_user', 'organization', $uids)) as $v) {
            if (!isset($orgdata[$v['uid']])) $orgdata[$v['uid']] = array($v['orgname']);
            else $orgdata[$v['uid']][] = $v['orgname'];
        }
        
        foreach ($data as $uv) {
            $uv['orgname'] = (isset($orgdata[$uv['uid']])) ? implode(',', $orgdata[$uv['uid']]) : lang('no_institution_users1');
            $userdata[] = $uv;
        }
        $returndata = [];
        foreach($userdata as $v){
            if($v['adminid'] == 1) $v['pichomelevel'] = 5;
            else $v['pichomelevel'] = C::t('user_setting')->fetch_by_skey('pichomelevel',$v['uid']);
            $returndata[] = $v;
        }

        exit(json_encode(array('data' => $returndata, 'total' => $total)));
    } elseif ($do == 'deleteuser') {
        $forgid = intval($_GET['forgid']);
        $uids = $_GET['uids'];
        //真实删除用户参数，暂时保留
        $realdelete = intval($_GET['realdelete']);
        //判断权限，非创始人，且操作者权限大于被操作者时才允许操作
        $datas = [];
        foreach (DB::fetch_all("select u.nickname,u.groupid,u.adminid,u.uid,ou.orgid from  %t u
            left join %t ou on ou.uid=u.uid where u.uid in(%n)", array('user', 'organization_user', $uids)) as $v) {
            $v['perm'] = get_user_perm($v);
            //如果是创始人，或者当前用户操作权限不大于该被操作用户不允许操作
            if ($v['perm'] == 3 || $doperm <= $v['perm']) continue;
            $v['orgid'] = $v['orgid'] ? [$v['orgid']] : [];
            if (isset($datas[$v['uid']])) {
                $datas[$v['uid']]['orgid'] = array_merge($datas[$v['uid']]['orgid'], $v['orgid']);
            } else {
                $datas[$v['uid']] = $v;
            }
        }
        $deluids = [];
        //如果是机构部门成员，则将该用户机构部门移除，否则删除该用户
        foreach ($datas as $v) {
            if (!empty($v['orgid'])) {
                if (C::t('organization_user')->delete_by_uid($v['uid'])) {
                    //如果是强制删除则删移除机构部门后直接删除用户
                    if ($realdelete) C::t('user')->delete_by_uid( $v['uid']);
                }
                
            } else C::t('user')->delete_by_uid( $v['uid']);//无机构部门用户直接删除
            $deluids[] = $v['uid'];
        }
        exit(json_encode(array('msg' => 'success','deluids'=>$deluids)));
        
    } elseif ($do == 'unableuser') {
        $uids = $_GET['uids'];
        $douids = [];
        //判断权限，非创始人，且操作者权限大于被操作者时才允许操作
        foreach(DB::fetch_all("select uid,nickname,groupid,adminid from %t where uid in(%n)",array('user',$uids)) as $v){
            $perm = get_user_perm($v);
            //如果是创始人，或者当前用户操作权限不大于该被操作用户不允许操作
            if ($perm == 3 || $doperm <= $perm) continue;
            else $douids[]= $v['uid'];
        }
        $status = intval($_GET['status']);
        C::t('user')->update($douids, array('status' => $status));
        exit(json_encode(array('success' => true,'douids'=>$douids)));
    } elseif ($do == 'getparentorgids') {
        $orgid = $_GET['orgid'];
        $uporgids = array();
        if ($orgid == 'search') {
            $data = array('orgname' => '用户搜索');
        } elseif ($orgid == 'other') {
            $data = array('orgname' => lang('no_institution_users'));
        } else {
            $data = C::t('organization')->fetch($orgid);
            $uporgids = C::t('organization')->fetch_parent_by_orgid($orgid);
        }
        
        
        exit(json_encode(array('parents' => $uporgids, 'data' => $data)));
    } elseif ($do == 'usersearch') {
        $username = isset($_GET['username']) ? trim($_GET['username']) : '';
        $nickname = isset($_GET['nickname']) ? trim($_GET['nickname']) : '';
        $uid = isset($_GET['uid']) ? intval($_GET['uid']) : 0;
        $orgids = isset($_GET['orgids']) ? trim($_GET['orgids']) : 0;
        $status = isset($_GET['status']) ? intval($_GET['status']) : 0;
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        $uids = array();

        $params = array('user', $status);
        $wheresql = " status  = %d ";
        if ($username) {
            $wheresql .= " and username like %s";
            $params[] = '%' . $username . '%';
        }
        if ($nickname) {
            $wheresql .= " and nickname like %s";
            $params[] = '%' . $nickname . '%';
        }
        if ($uid) {
            $wheresql .= " and uid = %d";
            $params[] = $uid;
        }
        if ($orgids) {
            $orgids = explode(',', $orgids);
            $orgidarr = [];
            foreach($orgids as $v){
                $tmporgids = C::t('organization')->get_childorg_by_orgid($v);
                $orgidarr = array_merge($orgidarr,$tmporgids);
            }
            $orgidarr = array_unique($orgidarr);
            $uids = C::t('organization_user')->fetch_uids_by_orgid($orgidarr);
            if(empty($uids)) $uids = [];
            $wheresql .= " and uid in(%n)";
            $params[] = $uids;
        }
        $uuids = array();
        $datas = array();
        $data = array();
        $limitsql = "limit " . ($page - 1) * $limit . ',' . $limit;
        $count = DB::result_first("select count(*) from %t where $wheresql", $params);
        foreach (DB::fetch_all("select username,nickname,adminid,groupid,uid,collects,uploads,downloads,status from %t where $wheresql $limitsql", $params) as $v) {
            $uuids[] = $v['uid'];
            $v['perm'] = get_user_perm($v);
            $datas[] = $v;
        }
        getuserIcon($uuids, $datas, $data);
        $orgdata = array();
        foreach (DB::fetch_all("select ou.uid,o.orgname from %t ou
    left join %t o on o.orgid=ou.orgid  where ou.uid in(%n) and o.type=0", array('organization_user', 'organization', $uuids)) as $v) {
            if (!isset($orgdata[$v['uid']])) $orgdata[$v['uid']] = array($v['orgname']);
            else $orgdata[$v['uid']][] = $v['orgname'];
        }
        $userdata = array();
        foreach ($data as $uv) {
            if (isset($orgdata[$uv['uid']])) {
                $uv['orgname'] = implode(',', $orgdata[$uv['uid']]);
                $uv['hasorg'] = 1;
            } else {
                $uv['orgname'] = lang('no_institution_users1');
                $uv['hasorg'] = 0;
            }
            $userdata[] = $uv;
        }
        exit(json_encode(array('data' => $userdata, 'total' => $count)));
        
    }
    function get_user_perm($user)
    {
        if (C::t('user')->checkfounder($user)) {
            return 3;
        } elseif ($user['adminid'] == 1) {
            return 2;
        } else {
            return 1;
        }
    }
    
    function getuserIcon($uids, $datas, &$data)
    {
        $colors = array('#6b69d6', '#a966ef', '#e9308d', '#e74856', '#f35b42', '#00cc6a', '#0078d7', '#5290f3', '#00b7c3', '#0099bc', '#018574', '#c77c52', '#ff8c00', '#68768a', '#7083cb', '#26a255');
        $uids = array_unique($uids);
        $avatars = array();
        foreach (DB::fetch_all('select u.avatarstatus,u.uid,s.svalue from %t u left join %t s on u.uid=s.uid and s.skey=%s where u.uid in(%n)', array('user', 'user_setting', 'headerColor', $uids)) as $v) {
            if ($v['avatarstatus'] == 1) {
                $avatars[$v['uid']]['avatarstatus'] = 1;
            } else {
                $avatars[$v['uid']]['avatarstatus'] = 0;
                $avatars[$v['uid']]['headerColor'] = $v['svalue'];
            }
        }
        foreach ($datas as $v) {
            $uid = $v['uid'];
            $v['text'] = $v['username'];
            if ($avatars[$v['uid']]['avatarstatus']) {
                $v['icon'] = 'avatar.php?uid=' . $uid;
            } elseif ($avatars[$uid]['headerColor']) {
                $v['headerColor'] = $avatars[$uid]['headerColor'];
                $v['firstword'] = strtoupper(new_strsubstr($v['username'], 1, ''));
                $v['icon'] = false;
                $v['text'] = '<span class="Topcarousel" style="background:' . $v['headerColor'] . ';" title="' . preg_replace("/<em.+?\/em>/i", '', $v['text']) . '">' . $v['firstword'] . '</span>' . $v['text'];
                
            } else {
                $v['icon'] = false;
                $colorkey = rand(1, 15);
                $headerColor = $colors[$colorkey];
                C::t('user_setting')->insert_by_skey('headerColor', $headerColor, $uid);
                $v['firstword'] = strtoupper(new_strsubstr($v['text'], 1, ''));
                $v['headerColor'] = $headerColor;
            }
            $data[$uid] = $v;
        }
    }
    
    function list_to_tree($list, $pk = 'orgid', $pid = 'forgid', $child = 'children', $root = 0)
    {
        // 创建Tree
        $tree = array();
        if (is_array($list)) {
            // 创建基于主键的数组引用
            $refer = array();
            foreach ($list as $key => $data) {
                $refer[$data[$pk]] =& $list[$key];
            }
            foreach ($list as $key => $data) {
                // 判断是否存在parent
                $parentId = $data[$pid];
                if ($root == $parentId) {
                    $tree[] =& $list[$key];
                } else {
                    if (isset($refer[$parentId])) {
                        $parent =& $refer[$parentId];
                        $parent[$child][] =& $list[$key];
                    }
                }
            }
        }
        return $tree;
    }