<?php

namespace Api\Model;

use Api\Model\BaseModel;

class UserModel extends BaseModel
{

    /**
     * 用户名是否已经存在
     * 
     */
    public function isExist($username)
    {
        return  $this->where("username = '%s'", array($username))->find();
    }

    /**
     * 注册新用户
     * 
     */
    public function register($username, $password)
    {
        $salt = get_rand_str();
        $password = encry_password($password, $salt);
        $uid = $this->add(array('username' => $username, 'password' => $password, 'salt' => $salt,  'reg_time' => time()));
        return $uid;
    }

    //修改用户密码
    public function updatePwd($uid, $password)
    {
        $res = $this->where("uid = '%d'", array($uid))->find();
        $password = encry_password($password, $res['salt']);
        return $this->where("uid ='%d' ", array($uid))->save(array('password' => $password));
    }

    /**
     * 返回用户信息
     * @return 
     */
    public function userInfo($uid)
    {
        return  $this->where("uid = '%d'", array($uid))->find();
    }

    /**
     *@param username:登录名  
     *@param password 登录密码   
     */

    public function checkLogin($username, $password)
    {
        $where = array($username, $username);
        $res = $this->where(" username='%s' or email='%s'  ", $where)->find();
        if ($res) {
            if ($res['password'] === encry_password($password, $res['salt'])) {
                return $res;
            }
        }

        return false;
    }
    //设置最后登录时间
    public function setLastTime($uid)
    {
        return $this->where("uid='%s'", array($uid))->save(array("last_login_time" => time()));
    }

    //删除用户
    public function delete_user($uid)
    {
        $uid = intval($uid);
        D("TeamMember")->where("member_uid = '$uid' ")->delete();
        D("TeamItemMember")->where("member_uid = '$uid' ")->delete();
        D("ItemMember")->where("uid = '$uid' ")->delete();
        D("UserToken")->where("uid = '$uid' ")->delete();
        D("Template")->where("uid = '$uid' ")->delete();
        D("ItemTop")->where("uid = '$uid' ")->delete();
        $return = D("User")->where("uid = '$uid' ")->delete();
        return $return;
    }
    //检测ldap登录
    public function checkLdapLogin($username, $password)
    {
        set_time_limit(60);
        ini_set('memory_limit', '500M');
        $ldap_open = D("Options")->get("ldap_open");
        $ldap_form = D("Options")->get("ldap_form");
        $ldap_form = json_decode($ldap_form, 1);
        if (!$ldap_open) {
            return false;
        }
        if (!$ldap_form['user_field']) {
            $ldap_form['user_field'] = 'cn';
        }
        $ldap_conn = ldap_connect($ldap_form['host'], $ldap_form['port']); //建立与 LDAP 服务器的连接
        if (!$ldap_conn) {
            return false;
        }
        ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, $ldap_form['version']);
        $rs = ldap_bind($ldap_conn, $ldap_form['bind_dn'], $ldap_form['bind_password']); //与服务器绑定 用户登录验证 成功返回1 
        if (!$rs) {
            return false;
        }
        $ldap_form['search_filter'] = $ldap_form['search_filter'] ? $ldap_form['search_filter'] : '(cn=*)';
        $result = ldap_search($ldap_conn, $ldap_form['base_dn'], $ldap_form['search_filter']);
        $data = ldap_get_entries($ldap_conn, $result);
        for ($i = 0; $i < $data["count"]; $i++) {
            $ldap_user = $data[$i][$ldap_form['user_field']][0];
            $dn = $data[$i]["dn"];
            if ($ldap_user == $username) {
                // 获取用户姓名
                $ldap_name = '';
                $name_field = strtolower($ldap_form['name_field']);

                if ($name_field) {
                    // 因为LDAP属性可能大小写不同，遍历所有属性找到匹配的
                    foreach ($data[$i] as $key => $value) {
                        if (strtolower($key) === $name_field && isset($value['count']) && $value['count'] > 0) {
                            $ldap_name = $value[0];
                            break;
                        }
                    }
                }
                
                //如果该用户不在数据库里，则帮助其注册
                $userInfo = D("User")->isExist($username);
                if (!$userInfo) {
                    $uid = D("User")->register($ldap_user, $ldap_user . get_rand_str());
                    // 如果有姓名字段，则设置用户姓名
                    if ($ldap_name) {
                        D("User")->where("uid = '%d'", array($uid))->save(array("name" => $ldap_name));
                    }
                    $userInfo = D("User")->isExist($username);
                } else if ($ldap_name) {
                    // 如果用户已存在且有姓名字段，则更新用户姓名
                    D("User")->where("uid = '%d'", array($userInfo['uid']))->save(array("name" => $ldap_name));
                }
                
                $rs2 = ldap_bind($ldap_conn, $dn, $password);
                if ($rs2) {
                    // LDAP认证成功，更新本地密码
                    D("User")->updatePwd($userInfo['uid'], $password);
                    
                    // 直接返回用户信息，避免再次调用checkLogin造成的验证问题
                    // 因为LDAP已经验证了密码的正确性，无需再次验证
                    $userInfo = D("User")->where("uid = '%d'", array($userInfo['uid']))->find();
                    
                    // 清除敏感信息
                    unset($userInfo['password']);
                    unset($userInfo['salt']);
                    
                    return $userInfo;
                }
            }
        }

        return false;
    }

    public function checkDbOk()
    {
        $ret = $this->find();
        if ($ret) {
            return true;
        } else {
            return false;
        }
    }
}
