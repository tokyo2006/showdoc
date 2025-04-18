<?php

namespace Api\Controller;

use Think\Controller;

class AdminSettingController extends BaseController
{

    //保存配置
    public function saveConfig()
    {
        $login_user = $this->checkLogin();
        $this->checkAdmin();
        $register_open = intval(I("register_open"));
        $history_version_count = intval(I("history_version_count"));
        $oss_open = intval(I("oss_open"));
        $home_page = intval(I("home_page"));
        $home_item = intval(I("home_item"));
        $oss_setting = I("oss_setting");
        $show_watermark = intval(I("show_watermark"));
        $beian = I("beian");
        $site_url = I("site_url");
        $open_api_key = I("open_api_key");
        $open_api_host = I("open_api_host");
        $ai_model_name = I("ai_model_name");
        $force_login = intval(I("force_login"));
        $enable_public_square = intval(I("enable_public_square"));

        D("Options")->set("history_version_count", $history_version_count);
        D("Options")->set("register_open", $register_open);
        D("Options")->set("home_page", $home_page);
        D("Options")->set("home_item", $home_item);
        D("Options")->set("beian", $beian);
        D("Options")->set("site_url", $site_url);
        D("Options")->set("open_api_key", $open_api_key);
        D("Options")->set("open_api_host", $open_api_host);
        D("Options")->set("ai_model_name", $ai_model_name);
        D("Options")->set("show_watermark", $show_watermark);
        D("Options")->set("force_login", $force_login);
        D("Options")->set("enable_public_square", $enable_public_square);

        if ($oss_open) {
            $this->checkComposerPHPVersion();
            D("Options")->set("oss_setting", json_encode($oss_setting));
        }
        D("Options")->set("oss_open", $oss_open);

        $this->sendResult(array());
    }

    //加载配置
    public function loadConfig()
    {
        $login_user = $this->checkLogin();
        $this->checkAdmin();
        $oss_open = D("Options")->get("oss_open");
        $register_open = D("Options")->get("register_open");
        $show_watermark = D("Options")->get("show_watermark");
        $history_version_count = D("Options")->get("history_version_count");
        $oss_setting = D("Options")->get("oss_setting");
        $home_page = D("Options")->get("home_page");
        $home_item = D("Options")->get("home_item");
        $beian = D("Options")->get("beian");
        $site_url = D("Options")->get("site_url");
        $open_api_key = D("Options")->get("open_api_key");
        $open_api_host = D("Options")->get("open_api_host");
        $ai_model_name = D("Options")->get("ai_model_name");
        $force_login = D("Options")->get("force_login");
        $enable_public_square = D("Options")->get("enable_public_square");
        $oss_setting = json_decode($oss_setting, 1);

        //如果强等于false，那就是尚未有数据。关闭注册应该是有数据且数据为字符串0
        if ($register_open === false) {
            $this->sendResult(array());
        } else {
            $array = array(
                "oss_open" => $oss_open,
                "register_open" => $register_open,
                "show_watermark" => $show_watermark,
                "history_version_count" => $history_version_count,
                "home_page" => $home_page,
                "home_item" => $home_item,
                "beian" => $beian,
                "site_url" => $site_url,
                "oss_setting" => $oss_setting,
                "open_api_key" => $open_api_key,
                "open_api_host" => $open_api_host,
                "ai_model_name" => $ai_model_name,
                "force_login" => $force_login,
                "enable_public_square" => $enable_public_square,
            );
            $this->sendResult($array);
        }
    }

    //保存Ldap配置
    public function saveLdapConfig()
    {
        set_time_limit(60);
        ini_set('memory_limit', '500M');
        $login_user = $this->checkLogin();
        $this->checkAdmin();
        $ldap_open = intval(I("ldap_open"));
        $ldap_form = I("ldap_form");

        if ($ldap_open) {
            if (!$ldap_form['user_field']) {
                $ldap_form['user_field'] = 'cn';
            }

            $ldap_form['user_field'] = strtolower($ldap_form['user_field']);
            
            // 如果未配置姓名字段，则默认为空
            if (!isset($ldap_form['name_field'])) {
                $ldap_form['name_field'] = '';
            }
            
            if ($ldap_form['name_field']) {
                $ldap_form['name_field'] = strtolower($ldap_form['name_field']);
            }

            if (!extension_loaded('ldap')) {
                $this->sendError(10011, "你尚未安装php-ldap扩展。如果是普通PHP环境，请手动安装之。如果是使用之前官方docker镜像，则需要重新安装镜像。方法是：备份 /showdoc_data 整个目录，然后全新安装showdoc，接着用备份覆盖/showdoc_data 。然后递归赋予777可写权限。");
                return;
            }

            $ldap_conn = ldap_connect($ldap_form['host'], $ldap_form['port']); //建立与 LDAP 服务器的连接
            if (!$ldap_conn) {
                $this->sendError(10011, "Can't connect to LDAP server");
                return;
            }

            $ldap_form['bind_password'] = htmlspecialchars_decode($ldap_form['bind_password']);

            ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, $ldap_form['version']);
            $rs = ldap_bind($ldap_conn, $ldap_form['bind_dn'], $ldap_form['bind_password']); //与服务器绑定 用户登录验证 成功返回1 
            if (!$rs) {
                $this->sendError(10011, "Can't bind to LDAP server");
                return;
            }

            $ldap_form['search_filter'] = $ldap_form['search_filter'] ? $ldap_form['search_filter'] : '(cn=*)';
            $ldap_form['search_filter'] = trim(htmlspecialchars_decode($ldap_form['search_filter']));
            $result = ldap_search($ldap_conn, $ldap_form['base_dn'], $ldap_form['search_filter']);
            $data = ldap_get_entries($ldap_conn, $result);

            for ($i = 0; $i < $data["count"]; $i++) {
                $ldap_user = $data[$i][$ldap_form['user_field']][0];
                if (!$ldap_user) {
                    continue;
                }
                
                // 获取用户姓名
                $ldap_name = '';
                if ($ldap_form['name_field'] && isset($data[$i][$ldap_form['name_field']]) && $data[$i][$ldap_form['name_field']]['count'] > 0) {
                    $ldap_name = $data[$i][$ldap_form['name_field']][0];
                }
                
                //如果该用户不在数据库里，则帮助其注册
                $userInfo = D("User")->isExist($ldap_user);
                if (!$userInfo) {
                    $uid = D("User")->register($ldap_user, $ldap_user . get_rand_str());
                    // 如果有姓名字段，则更新用户姓名
                    if ($ldap_name) {
                        D("User")->where("uid = '%d'", array($uid))->save(array("name" => $ldap_name));
                    }
                } else if ($ldap_name) {
                    // 如果用户已存在且有姓名字段，则更新用户姓名
                    D("User")->where("uid = '%d'", array($userInfo['uid']))->save(array("name" => $ldap_name));
                }
            }

            D("Options")->set("ldap_form", json_encode($ldap_form));
        }
        D("Options")->set("ldap_open", $ldap_open);
        $this->sendResult(array());
    }

    //加载Ldap配置
    public function loadLdapConfig()
    {
        $login_user = $this->checkLogin();
        $this->checkAdmin();
        $ldap_open = D("Options")->get("ldap_open");
        $ldap_form = D("Options")->get("ldap_form");
        $ldap_form = json_decode($ldap_form, 1);

        if ($ldap_form && $ldap_form['host'] && !$ldap_form['search_filter']) {
            $ldap_form['search_filter'] = '(cn=*)';
        }
        
        // 确保name_field字段存在
        if ($ldap_form && !isset($ldap_form['name_field'])) {
            $ldap_form['name_field'] = '';
        }
        
        $array = array(
            "ldap_open" => $ldap_open,
            "ldap_form" => $ldap_form,
        );
        $this->sendResult($array);
    }

    //保存Oauth2配置
    public function saveOauth2Config()
    {
        $login_user = $this->checkLogin();
        $this->checkAdmin();
        $this->checkComposerPHPVersion();
        $oauth2_open = intval(I("oauth2_open"));
        $oauth2_form = I("oauth2_form");
        D("Options")->set("oauth2_form", json_encode($oauth2_form));
        D("Options")->set("oauth2_open", $oauth2_open);
        $this->sendResult(array());
    }

    //加载Oauth2配置
    public function loadOauth2Config()
    {
        $login_user = $this->checkLogin();
        $this->checkAdmin();
        $oauth2_open = D("Options")->get("oauth2_open");
        $oauth2_form = D("Options")->get("oauth2_form");
        $oauth2_form = htmlspecialchars_decode($oauth2_form);
        $oauth2_form = json_decode($oauth2_form, 1);

        $array = array(
            "oauth2_open" => $oauth2_open,
            "oauth2_form" => $oauth2_form,
        );
        $this->sendResult($array);
    }

    public function getLoginSecretKey()
    {
        $login_user = $this->checkLogin();
        $this->checkAdmin();
        $login_secret_key = D("Options")->get("login_secret_key");
        if (!$login_secret_key) {
            $login_secret_key = get_rand_str();
            D("Options")->set("login_secret_key", $login_secret_key);
        }
        $this->sendResult(array("login_secret_key" => $login_secret_key));
    }

    public function resetLoginSecretKey()
    {
        $login_user = $this->checkLogin();
        $this->checkAdmin();
        $login_secret_key = get_rand_str();
        D("Options")->set("login_secret_key", $login_secret_key);
        $this->sendResult(array("login_secret_key" => $login_secret_key));
    }


    public function checkLdapLogin()
    {
        set_time_limit(60);
        ini_set('memory_limit', '500M');
        $username = 'admin';
        $password = '123456';

        $ldap_open = D("Options")->get("ldap_open");
        $ldap_form = D("Options")->get("ldap_form");
        $ldap_form = json_decode($ldap_form, 1);
        if (!$ldap_open) {
            return;
        }
        if (!$ldap_form['user_field']) {
            $ldap_form['user_field'] = 'cn';
        }
        $ldap_conn = ldap_connect($ldap_form['host'], $ldap_form['port']); //建立与 LDAP 服务器的连接
        if (!$ldap_conn) {
            $this->sendError(10011, "Can't connect to LDAP server");
            return;
        }
        ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, $ldap_form['version']);
        $rs = ldap_bind($ldap_conn, $ldap_form['bind_dn'], $ldap_form['bind_password']); //与服务器绑定 用户登录验证 成功返回1 
        if (!$rs) {
            $this->sendError(10011, "Can't bind to LDAP server");
            return;
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
                if (isset($ldap_form['name_field']) && $ldap_form['name_field'] && 
                    isset($data[$i][$ldap_form['name_field']]) && 
                    $data[$i][$ldap_form['name_field']]['count'] > 0) {
                    $ldap_name = $data[$i][$ldap_form['name_field']][0];
                }
                
                //如果该用户不在数据库里，则帮助其注册
                $userInfo = D("User")->isExist($username);
                if (!$userInfo) {
                    $uid = D("User")->register($ldap_user, $ldap_user . get_rand_str());
                    // 如果有姓名字段，则设置用户姓名
                    if ($ldap_name) {
                        D("User")->where("uid = '%d'", array($uid))->save(array("name" => $ldap_name));
                    }
                } else if ($ldap_name) {
                    // 如果用户已存在且有姓名字段，则更新用户姓名
                    D("User")->where("uid = '%d'", array($userInfo['uid']))->save(array("name" => $ldap_name));
                }
                
                $rs2 = ldap_bind($ldap_conn, $dn, $password);
                if ($rs2) {
                    D("User")->updatePwd($userInfo['uid'], $password);
                    $this->sendResult(array());
                    return;
                }
            }
        }
        $this->sendError(10011, "用户名或者密码错误");
    }
}
